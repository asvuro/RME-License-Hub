<?php

namespace App\Http\Controllers;

use App\Enums\GroupRealtimeEventType;
use App\Models\Group;
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
        if (!$tenant || !$tenant->group_id) {
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
        if (!$tenant) {
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
        $expectedChannel = \App\Events\GrupNotification::CHANNEL_PREFIX.$instanceId;
        if ($instanceId === '' || $headerInstance === '' || $channel !== $expectedChannel || !hash_equals($headerInstance, $instanceId)) {
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
        if (!$tenant) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'event' => ['required', 'string', 'in:membership.updated,patient.updated,referral.created,referral.updated'],
            'resource_id' => ['nullable', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
        ]);

        if (!$tenant->group_id) {
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
        if (!$tenant || !$tenant->group_id) {
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
        if (!$target) {
            return response()->json(['success' => false, 'message' => 'Target branch not found in group.'], 404);
        }

        $base = rtrim((string) $target->instance_url, '/');
        if (!$base) {
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
