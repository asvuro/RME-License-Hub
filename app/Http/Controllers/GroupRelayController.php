<?php

namespace App\Http\Controllers;

use App\Models\HubAuditLog;
use App\Services\GroupRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles realtime relay requests from tenant instances.
 *
 * When a tenant in a group wants to broadcast an event to its siblings,
 * it sends the event to the hub via this endpoint. The hub validates
 * the sender and relays the event to all sibling tenants in the group
 * via their Reverb channels.
 *
 * This ensures each tenant only needs to know ONE address (the hub),
 * not the addresses of all N siblings (avoiding SSRF/attack-surface).
 */
class GroupRelayController extends Controller
{
    public function __construct(
        protected GroupRelayService $relayService
    ) {}

    /**
     * POST /api/v1/group/relay
     *
     * Authenticated via service-to-service token.
     * Body: { event: "patient.transferred", data: {...} }
     */
    public function relay(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json(['success' => false, 'message' => 'Not authenticated.'], 401);
        }

        $data = $request->validate([
            'event' => ['required', 'string', 'max:100'],
            'data' => ['nullable', 'array'],
        ]);

        if (! $tenant->group_id) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant is not part of a group.',
            ], 422);
        }

        $delivered = $this->relayService->relayToGroup(
            $tenant,
            $data['event'],
            $data['data'] ?? []
        );

        HubAuditLog::create([
            'tenant_id' => $tenant->id,
            'event_type' => 'group.relay',
            'details' => [
                'event' => $data['event'],
                'delivered_to' => $delivered,
                'group_id' => $tenant->group_id,
            ],
            'ip_address' => $request->ip(),
            'actor_type' => 'api',
        ]);

        return response()->json([
            'success' => true,
            'delivered_to' => $delivered,
        ]);
    }
}
