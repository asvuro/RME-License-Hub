<?php

namespace Tests\Unit;

use App\Events\GroupForceDisableExecuted;
use App\Events\GroupForceDisableWarning;
use App\Events\GroupLicenseUpdated;
use App\Events\GroupMemberJoined;
use App\Events\GroupMemberLeft;
use App\Events\GroupQuotaChanged;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PHPUnit\Framework\TestCase;

/**
 * Validates that each group realtime event targets the exact channels defined in
 * the README contract and implements ShouldBroadcast. Pure unit test (no DB):
 * broadcastOn() only builds channel name strings from constructor arguments.
 */
class GroupRealtimeEventChannelsTest extends TestCase
{
    public function test_group_member_joined_broadcasts_to_branch_private_and_group_shared_channel(): void
    {
        $event = new GroupMemberJoined('grp-1', 'inst-A', 'Cabang A', 'BR-A');

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertSame('GroupMemberJoined', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-group.grp-1.branch.inst-A', (string) $channels[0]);
        $this->assertInstanceOf(Channel::class, $channels[1]);
        // Shared group channel (presence) — note: presence- prefix is added by the broadcaster.
        $this->assertSame('group.grp-1', (string) $channels[1]);

        $payload = $event->broadcastWith();
        $this->assertSame('grp-1', $payload['group_id']);
        $this->assertSame('inst-A', $payload['branch_instance_id']);
        $this->assertSame('Cabang A', $payload['branch_name']);
        // No secrets in payload.
        $this->assertArrayNotHasKey('api_token_hash', $payload);
        $this->assertArrayNotHasKey('webhook_secret_hash', $payload);
    }

    public function test_group_member_left_targets_branch_private_and_group_shared_channel(): void
    {
        $event = new GroupMemberLeft('grp-2', 'inst-B', 'Cabang B', 'BR-B');
        $this->assertSame('GroupMemberLeft', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertSame('private-group.grp-2.branch.inst-B', (string) $channels[0]);
        $this->assertSame('group.grp-2', (string) $channels[1]);
    }

    public function test_group_license_updated_targets_only_the_branch_private_channel(): void
    {
        $event = new GroupLicenseUpdated(
            groupId: 'grp-3',
            branchInstanceId: 'inst-C',
            licenseKey: 'LIC-XXXX',
            status: 'active',
            validUntil: '2030-01-01T00:00:00+00:00',
            changedModules: ['farmasi'],
            reason: 'addon_purchased',
        );
        $this->assertSame('GroupLicenseUpdated', $event->broadcastAs());

        $channels = $event->broadcastOn();
        // Single, private, per-branch channel — never fanned to the whole group.
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-group.grp-3.branch.inst-C', (string) $channels[0]);

        $payload = $event->broadcastWith();
        $this->assertSame('LIC-XXXX', $payload['license_key']);
        $this->assertSame('active', $payload['status']);
        // The opaque key string is exposed, but NOT the signed token / secret.
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertArrayNotHasKey('token_payload', $payload);
    }

    public function test_group_quota_changed_targets_only_the_branch_private_channel(): void
    {
        $event = new GroupQuotaChanged('grp-4', 'inst-D', 25, 3, ['pasien', 'farmasi'], 'addon_purchased');
        $this->assertSame('GroupQuotaChanged', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-group.grp-4.branch.inst-D', (string) $channels[0]);

        $payload = $event->broadcastWith();
        $this->assertSame(25, $payload['max_users']);
        $this->assertSame(3, $payload['max_branches']);
    }

    public function test_group_force_disable_warning_targets_only_the_branch_private_channel(): void
    {
        $event = new GroupForceDisableWarning('grp-5', 'inst-E', 'quota_exceeded', 3600, 'over quota');
        $this->assertSame('GroupForceDisableWarning', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-group.grp-5.branch.inst-E', (string) $channels[0]);

        $payload = $event->broadcastWith();
        $this->assertSame(3600, $payload['grace_seconds']);
    }

    public function test_group_force_disable_executed_targets_only_the_branch_private_channel(): void
    {
        $event = new GroupForceDisableExecuted('grp-6', 'inst-F', 'license_expired', 4, true, 'expired');
        $this->assertSame('GroupForceDisableExecuted', $event->broadcastAs());

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertSame('private-group.grp-6.branch.inst-F', (string) $channels[0]);

        $payload = $event->broadcastWith();
        $this->assertSame(4, $payload['users_disabled']);
        $this->assertTrue($payload['admin_protected']);
    }
}
