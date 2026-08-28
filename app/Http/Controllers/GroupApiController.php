<?php

namespace App\Http\Controllers;

use App\Enums\GroupRealtimeEventType;
use App\Events\GrupNotification;
use App\Models\Group;
use App\Models\GroupReferral;
use App\Models\Tenant;
use App\Services\GroupHubSignature;
use App\Services\GroupRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Group realtime + REST relay API — the hub side of the reconciled contract
 * with RME-Backend Modules/Grup.
 *
 * Endpoints consumed by the client (GroupHubClient / RealtimeEventProcessor):
 *   GET  /api/v1/group/context            -> group + branch roster (non-PHI)
 *   POST /api/v1/group/realtime/auth      -> Pusher/Reverb channel auth (fail-closed)
 *   POST /api/v1/group/relay              -> tenant -> hub -> siblings broadcast
 *   GET  /api/v1/group/relay/patients ... -> hub-proxied clinical fetch (signed)
 *
 * Egress (hub -> instance) is signed with GroupHubSignature so each instance
 * trusts only the hub (single relay, no sibling SSRF surface).
 */
class GroupApiController extends Controller
{
    public function __construct(
        protected GroupRelayService $relayService,
    ) {}

    /**
     * GET /api/v1/group/context
     * Returns the group roster with each branch's instance_id + last_seen_at.
     * The client fills in is_local for its own branch.
     */
    public function context(Request $request): JsonResponse
    {
        $tenant = $request->user(); // Tenant resolved by the `tenant` guard
        if (! $tenant || ! $tenant->group_id) {
            return response()->json(['success' => false, 'message' => 'Not in a group.'], 422);
        }

        $group = Group::findOrFail($tenant->group_id);

        return response()->json([
            'success' => true,
            'data' => $this->relayService->buildContext($group),
        ]);
    }

    /**
     * POST /api/v1/group/realtime/auth
     * Mirrors Pusher/Reverb channel auth. The client calls this with
     * { socket_id, channel_name }. Authorization is fail-closed: the request
     * must carry the tenant bearer token (tenant guard) AND an
     * X-RME-Instance-ID header that matches the channel suffix exactly.
     */
    public function realtimeAuth(Request $request): JsonResponse
    {
        $tenant = $request->user(); // Tenant resolved by the `tenant` guard
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'socket_id' => ['required', 'string', 'max:64'],
            'channel_name' => ['required', 'string', 'max:255'],
        ]);

        $instanceId = (string) ($tenant->licenseKeys()->latest()->value('instance_id') ?? '');
        $headerInstance = (string) $request->header('X-RME-Instance-ID', '');
        $channel = $data['channel_name'];

        // Channel must be the reconciled private-grup.instance.{instance_id}
        // AND the instance must match both the token's tenant and the header.
        $expectedChannel = GrupNotification::CHANNEL_PREFIX.$instanceId;
        if ($instanceId === '' || $headerInstance === '' || $channel !== $expectedChannel || ! hash_equals($headerInstance, $instanceId)) {
            return response()->json(['success' => false, 'message' => 'Channel authorization denied.'], 403);
        }

        $socketId = $data['socket_id'];
        $stringToSign = $socketId.':'.$channel;
        $secret = (string) config('reverb.apps.0.secret', config('reverb.app_secret', ''));

        if ($secret === '') {
            return response()->json(['success' => false, 'message' => 'Reverb app secret not configured.'], 500);
        }

        $signature = hash_hmac('sha256', $stringToSign, $secret);

        return response()->json([
            'auth' => config('reverb.apps.0.key', config('reverb.app_key', '')).':'.$signature,
        ]);
    }

    /**
     * POST /api/v1/group/relay
     * Tenant -> hub -> siblings broadcast. The hub maps the free client "event"
     * to one of the four fixed contract types (fail-closed). Payload carries no
     * PHI; the client refetches data on demand via the relay proxy.
     */
    public function relay(Request $request): JsonResponse
    {
        $tenant = $request->user(); // Tenant resolved by the `tenant` guard
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'event' => ['required', 'string', 'in:membership.updated,patient.updated,referral.created,referral.updated'],
            'resource_id' => ['nullable', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
        ]);

        if (! $tenant->group_id) {
            return response()->json(['success' => false, 'message' => 'Tenant is not part of a group.'], 422);
        }

        $type = GroupRealtimeEventType::from($data['event']);
        $delivered = $this->relayService->relayToGroup(
            $tenant,
            $type,
            $data['resource_id'] ?? null,
            $data['data'] ?? []
        );

        return response()->json(['success' => true, 'delivered_to' => $delivered]);
    }

    /**
     * GET /api/v1/group/relay/referrals
     * List referrals where the caller's branch is source or destination.
     * The hub is the authoritative store for referral status (both branches
     * must agree), unlike patient data which is never cached here.
     */
    public function listReferrals(Request $request): JsonResponse
    {
        $tenant = $request->user();
        if (! $tenant || ! $tenant->group_id) {
            return response()->json(['success' => false, 'message' => 'Not in a group.'], 422);
        }

        $query = GroupReferral::where('group_id', $tenant->group_id)
            ->where(fn ($q) => $q->where('source_branch_id', $tenant->id)->orWhere('destination_branch_id', $tenant->id));

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $referrals = $query->latest('referred_at')->paginate(min($request->integer('per_page', 20), 50));

        return response()->json(['data' => $referrals]);
    }

    /**
     * GET /api/v1/group/relay/referrals/{referralId}
     */
    public function showReferral(Request $request, string $referralId): JsonResponse
    {
        $tenant = $request->user();
        $referral = $this->findReferralForTenant($tenant, $referralId);
        if (! $referral) {
            return response()->json(['success' => false, 'message' => 'Referral not found.'], 404);
        }

        return response()->json(['data' => $this->referralPayload($referral)]);
    }

    /**
     * POST /api/v1/group/relay/referrals
     * Creates the hub-authoritative referral record and notifies the
     * destination branch. The caller MUST be the referral's source branch —
     * a tenant can never create a referral impersonating another branch.
     */
    public function storeReferral(Request $request): JsonResponse
    {
        $tenant = $request->user();
        if (! $tenant || ! $tenant->group_id) {
            return response()->json(['success' => false, 'message' => 'Not in a group.'], 422);
        }

        $data = $request->validate([
            'source_branch_id' => ['required', 'uuid'],
            'destination_branch_id' => ['required', 'uuid'],
            'source_patient_id' => ['required', 'string', 'max:100'],
            'patient_snapshot' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'max:5000'],
            'clinical_summary' => ['nullable', 'string', 'max:20000'],
            'referred_at' => ['required', 'date'],
        ]);

        // Anti-spoofing: the authenticated tenant IS the source branch.
        if ($data['source_branch_id'] !== $tenant->id) {
            return response()->json(['success' => false, 'message' => 'source_branch_id must match the authenticated branch.'], 403);
        }

        $destination = Tenant::where('id', $data['destination_branch_id'])
            ->where('group_id', $tenant->group_id)
            ->where('status', 'active')
            ->first();

        if (! $destination || $destination->id === $tenant->id) {
            return response()->json(['success' => false, 'message' => 'Destination branch not found in group.'], 404);
        }

        $referral = GroupReferral::create([
            'group_id' => $tenant->group_id,
            'source_branch_id' => $tenant->id,
            'destination_branch_id' => $destination->id,
            'source_patient_id' => $data['source_patient_id'],
            'patient_snapshot' => $data['patient_snapshot'] ?? null,
            'reason' => $data['reason'],
            'clinical_summary' => $data['clinical_summary'] ?? null,
            'status' => 'requested',
            'referred_at' => $data['referred_at'],
        ]);

        $sourceInstanceId = $tenant->licenseKeys()->latest()->value('instance_id');
        $this->relayService->broadcast($destination, GroupRealtimeEventType::ReferralCreated, $sourceInstanceId, $referral->id);

        return response()->json(['data' => $this->referralPayload($referral)], 201);
    }

    /**
     * PATCH /api/v1/group/relay/referrals/{referralId}
     * The hub re-validates the status transition server-side (defense in
     * depth — the client already enforces this, but the hub is authoritative).
     */
    public function updateReferral(Request $request, string $referralId): JsonResponse
    {
        $tenant = $request->user();
        $referral = $this->findReferralForTenant($tenant, $referralId);
        if (! $referral) {
            return response()->json(['success' => false, 'message' => 'Referral not found.'], 404);
        }

        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected,in_progress,completed,cancelled'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $isSource = $referral->source_branch_id === $tenant->id;
        $allowed = $isSource ? GroupReferral::SOURCE_TRANSITIONS : GroupReferral::DESTINATION_TRANSITIONS;

        if (! in_array($data['status'], $allowed[$referral->status] ?? [], true)) {
            return response()->json(['success' => false, 'message' => 'Transisi status rujukan tidak diizinkan.'], 422);
        }

        $referral->update([
            'status' => $data['status'],
            'note' => $data['note'] ?? $referral->note,
        ]);

        // Notify the OTHER party (not the caller, who already knows).
        $other = $isSource ? $referral->destinationBranch : $referral->sourceBranch;
        $sourceInstanceId = $tenant->licenseKeys()->latest()->value('instance_id');
        $this->relayService->broadcast($other, GroupRealtimeEventType::ReferralUpdated, $sourceInstanceId, $referral->id);

        return response()->json(['data' => $this->referralPayload($referral->fresh())]);
    }

    private function findReferralForTenant(?Tenant $tenant, string $referralId): ?GroupReferral
    {
        if (! $tenant || ! $tenant->group_id) {
            return null;
        }

        return GroupReferral::where('id', $referralId)
            ->where('group_id', $tenant->group_id)
            ->where(fn ($q) => $q->where('source_branch_id', $tenant->id)->orWhere('destination_branch_id', $tenant->id))
            ->first();
    }

    private function referralPayload(GroupReferral $referral): array
    {
        return [
            'id' => $referral->id,
            'group_id' => $referral->group_id,
            'source_branch_id' => $referral->source_branch_id,
            'destination_branch_id' => $referral->destination_branch_id,
            'status' => $referral->status,
            'reason' => $referral->reason,
            'clinical_summary' => $referral->clinical_summary,
            'note' => $referral->note,
            'referred_at' => $referral->referred_at?->toIso8601String(),
        ];
    }

    /**
     * GET /api/v1/group/relay/{path?}
     * Hub-proxied clinical/referral fetch on behalf of a requesting branch.
     * The hub signs each egress call to the TARGET instance with
     * GroupHubSignature, so the target trusts only the hub.
     *
     * path examples (client GroupHubClient):
     *   patients?q=...&page=...&per_page=...
     *   patients/{branchId}/{patientId}
     *   referrals/{referralId}
     */
    public function relayProxy(Request $request, string $path = ''): JsonResponse
    {
        $tenant = $request->user(); // Tenant resolved by the `tenant` guard
        if (! $tenant || ! $tenant->group_id) {
            return response()->json(['success' => false, 'message' => 'Not in a group.'], 422);
        }

        // Resolve the target branch from the path's first segment (branch id/code).
        $segments = explode('/', trim($path, '/'));
        $targetRef = $segments[0] ?? null;
        $target = null;
        if ($targetRef) {
            $target = Tenant::where('group_id', $tenant->group_id)
                ->where(function ($q) use ($targetRef) {
                    $q->where('id', $targetRef)->orWhere('client_code', $targetRef);
                })
                ->first();
        }
        if (! $target) {
            return response()->json(['success' => false, 'message' => 'Target branch not found in group.'], 404);
        }

        $base = rtrim((string) $target->instance_url, '/');
        if (! $base) {
            return response()->json(['success' => false, 'message' => 'Target instance URL not configured.'], 502);
        }

        $secret = (string) config('license.grup_hub_hmac_secret', '');
        $groupHubId = (string) ($tenant->group?->id ?? '');
        if ($secret === '' || $groupHubId === '') {
            return response()->json(['success' => false, 'message' => 'Hub relay signing not configured.'], 500);
        }

        $signer = new GroupHubSignature($secret, $groupHubId);
        $query = $request->getQueryString() ?? '';
        $rawBody = '';
        $url = $base.'/api/v1/grup/relay/'.$path.($query ? '?'.$query : '');
        $headers = $signer->signedHeaders($target, $rawBody);

        try {
            $response = Http::timeout((int) config('license.webhook_timeout', 15))
                ->withHeaders($headers)
                ->get($url);

            return response()->json($response->json() ?? [], $response->status());
        } catch (\Throwable $e) {
            Log::error("GroupApiController relayProxy failed: {$e->getMessage()}");

            return response()->json(['success' => false, 'message' => 'Relay to target instance failed.'], 502);
        }
    }
}
