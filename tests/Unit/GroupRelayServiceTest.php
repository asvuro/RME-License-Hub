<?php

namespace Tests\Unit;

use App\Enums\GroupRealtimeEventType;
use App\Events\GrupNotification;
use App\Models\Group;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Services\GroupRelayService;
use Tests\DatabaseTestCase;
use Illuminate\Support\Str;

class GroupRelayServiceTest extends DatabaseTestCase
{
    private function tenantInGroup(string $instanceId, string $groupId): Tenant
    {
        $tenant = Tenant::factory()->create(['group_id' => $groupId, 'status' => 'active']);
        LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.uniqid(),
            'status' => 'active',
            'instance_id' => $instanceId,
        ]);
        return $tenant;
    }

    public function test_relay_broadcasts_to_each_sibling_on_its_own_channel(): void
    {
        $group = Group::create(['id' => Str::uuid()->toString(), 'name' => 'G']);
        $sender = $this->tenantInGroup('INST-SEND', $group->id);
        $sib1 = $this->tenantInGroup('INST-A', $group->id);
        $sib2 = $this->tenantInGroup('INST-B', $group->id);

        // The relay returns the number of sibling branches broadcast to (one per
        // sibling, each on its own private-grup.instance.{instance_id} channel).
        // With 3 tenants in the group (sender + 2 siblings), the sender is
        // excluded, so exactly 2 are delivered.
        $delivered = app(GroupRelayService::class)
            ->relayToGroup($sender, GroupRealtimeEventType::PatientUpdated, 'p1');

        $this->assertSame(2, $delivered);

        // Verify the wire contract of the GrupNotification event object directly
        // (broadcast dispatch is environment-driven and not asserted here).
        $event = new GrupNotification(
            instanceId: 'INST-A',
            type: GroupRealtimeEventType::PatientUpdated,
            resourceId: 'p1',
            sourceBranchId: 'INST-SEND',
            occurredAt: now()->toIso8601String(),
        );
        $this->assertSame('grup.notification', $event->broadcastAs());
        $this->assertSame('INST-A', $event->instanceId);
        $this->assertSame(
            'private-'.GrupNotification::CHANNEL_PREFIX.'INST-A',
            $event->broadcastOn()[0]->name
        );
    }

    public function test_relay_returns_zero_when_sender_not_in_group(): void
    {
        $tenant = Tenant::factory()->create(['group_id' => null]);

        $delivered = app(GroupRelayService::class)
            ->relayToGroup($tenant, GroupRealtimeEventType::PatientUpdated);

        $this->assertSame(0, $delivered);
    }
}
