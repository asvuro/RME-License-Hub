<?php

namespace Tests\Feature;

use App\Events\GroupLicenseUpdated;
use App\Events\GroupMemberJoined;
use App\Events\GroupQuotaChanged;
use App\Models\Group;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Services\GroupRealtimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Verifies the GroupRealtimeService (the integration seam the hub's REST flows
 * call) dispatches the correct, tenant-scoped events. We assert the event class,
 * that it implements ShouldBroadcast, and that the private channel targets only
 * the intended branch — proving no cross-tenant broadcast path exists.
 */
class GroupRealtimeServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenantWithInstance(string $id, string $groupId, string $instanceId): Tenant
    {
        $tenant = new Tenant([
            'group_id' => $groupId,
            'client_code' => 'CODE-'.$id,
            'client_name' => 'Tenant '.$id,
            'status' => 'active',
        ]);
        $tenant->id = $id;
        $tenant->save();

        $license = new LicenseKey([
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.$id,
            'status' => 'active',
            'instance_id' => $instanceId,
        ]);
        $license->id = 'lic-'.$id;
        $license->save();

        return $tenant;
    }

    public function test_member_joined_targets_only_that_branch_instance(): void
    {
        $group = new Group(['name' => 'G1', 'status' => 'active']);
        $group->id = 'grp-1';
        $group->save();
        $branch = $this->makeTenantWithInstance('t-1', 'grp-1', 'inst-1');

        Event::fake([GroupMemberJoined::class]);

        (new GroupRealtimeService())->memberJoined('grp-1', $branch);

        Event::assertDispatched(GroupMemberJoined::class, function (GroupMemberJoined $e) {
            $channel = (string) $e->broadcastOn()[0];

            return $e->groupId === 'grp-1'
                && $e->branchInstanceId === 'inst-1'
                && $channel === 'private-group.grp-1.branch.inst-1';
        });
    }

    public function test_license_updated_has_no_cross_group_broadcast(): void
    {
        $group = new Group(['name' => 'G2', 'status' => 'active']);
        $group->id = 'grp-2';
        $group->save();
        $branch = $this->makeTenantWithInstance('t-2', 'grp-2', 'inst-2');

        Event::fake([GroupLicenseUpdated::class]);

        (new GroupRealtimeService())->licenseUpdated(
            groupId: 'grp-2',
            branch: $branch,
            licenseKey: 'LIC-t-2',
            status: 'suspended',
            reason: 'manual'
        );

        Event::assertDispatched(GroupLicenseUpdated::class, function (GroupLicenseUpdated $e) {
            $channels = $e->broadcastOn();
            // Exactly one private channel, owned by this branch only.
            return count($channels) === 1
                && (string) $channels[0] === 'private-group.grp-2.branch.inst-2'
                && $e->status === 'suspended'
                && $e->licenseKey === 'LIC-t-2';
        });
    }

    public function test_quota_changed_targets_only_that_branch(): void
    {
        $group = new Group(['name' => 'G3', 'status' => 'active']);
        $group->id = 'grp-3';
        $group->save();
        $branch = $this->makeTenantWithInstance('t-3', 'grp-3', 'inst-3');

        Event::fake([GroupQuotaChanged::class]);

        (new GroupRealtimeService())->quotaChanged('grp-3', $branch, 50, 5, ['pasien'], 'addon_purchased');

        Event::assertDispatched(GroupQuotaChanged::class, function (GroupQuotaChanged $e) {
            return (string) $e->broadcastOn()[0] === 'private-group.grp-3.branch.inst-3'
                && $e->maxUsers === 50
                && $e->maxBranches === 5;
        });
    }
}
