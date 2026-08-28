<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Services\ForceDisableTargetSelector;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class ForceDisableTargetSelectorTest extends DatabaseTestCase
{
    use WithFaker;

    private function user(Tenant $tenant, int $daysAgo, bool $admin, bool $active = true): TenantUser
    {
        return TenantUser::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'external_user_id' => Str::random(8),
            'is_admin' => $admin,
            'is_active' => $active,
            'registered_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_newest_registered_active_users_selected_first(): void
    {
        $tenant = Tenant::factory()->create();
        // 5 active users, newest registered last.
        $oldest = $this->user($tenant, 100, false);
        $this->user($tenant, 80, false);
        $this->user($tenant, 60, false);
        $mid = $this->user($tenant, 40, false);
        $newest = $this->user($tenant, 5, false);

        // Quota drops to 3 -> must disable the 2 newest (registered 5 and 40 days ago).
        $sel = (new ForceDisableTargetSelector)->select(3, 5, $tenant->tenantUsers()->get());

        $this->assertSame(['disable' => [$newest->external_user_id, $mid->external_user_id], 'admins_protected' => []], [
            'disable' => $sel['disable'],
            'admins_protected' => $sel['admins_protected'],
        ]);
        $this->assertNotContains($oldest->external_user_id, $sel['disable']);
    }

    public function test_inactive_users_are_not_selected(): void
    {
        $tenant = Tenant::factory()->create();
        $this->user($tenant, 100, false, false); // already disabled on client
        $activeNew = $this->user($tenant, 10, false);
        $activeOld = $this->user($tenant, 90, false);

        // Quota 1 -> only the single active newest is disabled.
        $sel = (new ForceDisableTargetSelector)->select(1, 3, $tenant->tenantUsers()->get());
        $this->assertSame([$activeNew->external_user_id], $sel['disable']);
    }

    public function test_last_remaining_admin_is_never_disabled(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->user($tenant, 2, true);   // admin, newest
        $user1 = $this->user($tenant, 50, false); // oldest active non-admin
        $user2 = $this->user($tenant, 30, false);

        // Quota drops to 2. The newest active user is the admin; disabling it
        // would leave zero admins. It MUST be protected, and the over-quota
        // cannot be fully resolved without breaking the rule.
        $sel = (new ForceDisableTargetSelector)->select(2, 3, $tenant->tenantUsers()->get());

        $this->assertContains($admin->external_user_id, $sel['admins_protected']);
        $this->assertNotContains($admin->external_user_id, $sel['disable']);
    }

    public function test_multiple_admins_protected_only_when_all_would_be_removed(): void
    {
        $tenant = Tenant::factory()->create();
        $adminOld = $this->user($tenant, 90, true);
        $adminNew = $this->user($tenant, 1, true);   // newest, but not the only admin
        $user1 = $this->user($tenant, 50, false);
        $user2 = $this->user($tenant, 40, false);

        // Quota 1 -> 4 active users over by 3. The 2 admins remain (>=1 admin
        // survives regardless); the newest NON-admin and the newest ADMIN are
        // both disabled (the newest admin leaves 1 admin standing, so it is NOT
        // protected). The 3 newest are disabled: adminNew, user2, user1.
        $sel = (new ForceDisableTargetSelector)->select(1, 4, $tenant->tenantUsers()->get());

        $this->assertNotContains($adminOld->external_user_id, $sel['disable']);
        $this->assertContains($adminNew->external_user_id, $sel['disable']);
        $this->assertEmpty($sel['admins_protected']); // no admin was at risk
        $this->assertCount(3, $sel['disable']);
    }

    public function test_unlimited_quota_disables_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->user($tenant, 10, false);
        $sel = (new ForceDisableTargetSelector)->select(0, 5, $tenant->tenantUsers()->get());
        $this->assertSame([], $sel['disable']);
    }

    public function test_fits_quota_disables_nothing(): void
    {
        $tenant = Tenant::factory()->create();
        $this->user($tenant, 10, false);
        $this->user($tenant, 20, false);
        $sel = (new ForceDisableTargetSelector)->select(5, 2, $tenant->tenantUsers()->get());
        $this->assertSame([], $sel['disable']);
    }
}
