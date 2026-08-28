<?php

namespace App\Services;

use App\Enums\GroupRealtimeEventType;
use App\Events\GrupNotification;
use App\Models\Group;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Realtime relay service for the Group feature.
 *
 * Architecture (reconciled contract — see docs/reconciliation-with-grup-module.md):
 * - Hub hosts Laravel Reverb. Each branch subscribes ONLY to its own channel
 *   `private-grup.instance.{instance_id}` (Decision 1) — no presence channel
 *   (Decision 2). The single wire event is `grup.notification` carrying one of
 *   four fixed `type` values.
 * - A branch sends an event to the hub over REST; the hub validates the sender
 *   and re-broadcasts a `grup.notification` to every OTHER branch in the same
 *   group, each on its own channel. Each branch trusts ONE address (the hub),
 *   preventing SSRF / multiplied attack surface.
 *
 * The hub ONLY emits the four fixed types; anything else is rejected (fail-closed)
 * so it can never drift from the client's RealtimeEventProcessor validation.
 */
class GroupRelayService
{
    /**
     * Relay a typed event from one tenant to all sibling tenants in its group.
     *
     * @param  Tenant  $sender   The authenticated sender (resolved by the tenant guard).
     * @param  GroupRealtimeEventType  $type  One of the four fixed contract types.
     * @param  string|null  $resourceId  Optional resource reference.
     * @param  array  $extra  Optional extra payload fields (never PHI).
     * @return int  Number of sibling branches the notification was broadcast to.
     */
    public function relayToGroup(
        Tenant $sender,
        GroupRealtimeEventType $type,
        ?string $resourceId = null,
        array $extra = []
    ): int {
        if (!$sender->group_id) {
            Log::info("GroupRelayService: sender {$sender->client_code} not in a group; skip.");
            return 0;
        }

        // Sender instance id (hub-issued license_keys.instance_id) — used as the
        // source_branch_id so clients can ignore self-echo if needed.
        $sourceInstanceId = $sender->licenseKeys()->latest()->value('instance_id');

        $siblings = Tenant::where('group_id', $sender->group_id)
            ->where('id', '!=', $sender->id)
            ->where('status', 'active')
            ->get();

        $delivered = 0;
        foreach ($siblings as $sibling) {
            $this->broadcast($sibling, $type, $sourceInstanceId, $resourceId, $extra);
            $delivered++;
        }

        Log::info("GroupRelayService: relayed {$type->value} from {$sender->client_code} to {$delivered} siblings.");
        return $delivered;
    }

    /**
     * Broadcast a grup.notification to a single tenant's own channel.
     */
    public function broadcast(
        Tenant $tenant,
        GroupRealtimeEventType $type,
        ?string $sourceBranchId = null,
        ?string $resourceId = null,
        array $extra = []
    ): void {
        $instanceId = $tenant->licenseKeys()->latest()->value('instance_id');
        if (!$instanceId) {
            Log::warning("GroupRelayService: tenant {$tenant->client_code} has no instance_id; cannot broadcast.");
            return;
        }

        broadcast(new GrupNotification(
            instanceId: $instanceId,
            type: $type,
            resourceId: $resourceId,
            sourceBranchId: $sourceBranchId,
            occurredAt: now()->toIso8601String(),
        ));
    }

    /**
     * Build the group context payload (non-PHI) for GET /api/v1/group/context.
     * Mirrors the shape the client's GroupContextController expects.
     */
    public function buildContext(Group $group): array
    {
        $branches = $group->activeTenants->map(function (Tenant $tenant) {
            $licenseKey = $tenant->licenseKeys()->latest()->first();
            return [
                'id' => $tenant->id,
                'instance_id' => $licenseKey?->instance_id,
                'code' => $tenant->client_code,
                'name' => $tenant->client_name,
                'status' => $tenant->status,
                'is_local' => false, // Set by the client for its own branch.
                'last_seen_at' => $tenant->last_heartbeat_at?->toIso8601String(),
            ];
        })->values();

        return [
            'id' => $group->id,
            'legal_name' => $group->legal_entity_name,
            'legal_identifier' => null,
            'status' => $group->status,
            'synced_at' => now()->toIso8601String(),
            'branches' => $branches,
        ];
    }
}
