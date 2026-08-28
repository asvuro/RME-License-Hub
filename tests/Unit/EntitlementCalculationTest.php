<?php

namespace Tests\Unit;

use App\Models\LicenseEntitlement;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the core entitlement calculation logic (EntitlementCalculator).
 *
 * Effective entitlement = tier base + sum of all ACTIVE add-ons.
 *   - user_quota   add-on  -> effective_max_users
 *   - branch_quota add-on  -> effective_max_branches
 *   - module       add-on  -> effective_modules (merged, de-duplicated)
 *   - time_extension add-on -> extends valid_until
 *
 * An add-on is "active" only when its status === 'active' AND it is not past
 * its effective_until AND it is past its effective_from. Expired / future /
 * revoked add-ons must NOT contribute to the effective totals.
 */
class EntitlementCalculationTest extends TestCase
{
    use RefreshDatabase;

    private function makeEntitlement(int $baseUsers, array $includedModules, ?int $durationDays = 365): LicenseEntitlement
    {
        $tier = Tier::factory()->create([
            'base_max_users' => $baseUsers,
            'included_modules' => $includedModules,
            'default_duration_days' => $durationDays,
        ]);
        $tenant = Tenant::factory()->create();
        $licenseKey = LicenseKey::factory()->create(['tenant_id' => $tenant->id]);

        return app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );
    }

    private function calculator(): EntitlementCalculator
    {
        return app(EntitlementCalculator::class);
    }

    public function test_base_tier_values_are_effective_without_addons(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA', 'ModB']);
        $entitlement->refresh();

        $this->assertEquals(10, $entitlement->effective_max_users);
        $this->assertEquals(1, $entitlement->effective_max_branches);
        $this->assertEqualsCanonicalizing(['ModA', 'ModB'], $entitlement->effective_modules);
    }

    public function test_active_user_quota_addons_accumulate_on_top_of_base(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'user_quota', 5);
        $calculator->addAddon($entitlement, 'user_quota', 3);
        $entitlement->refresh();

        // 10 (base) + 5 + 3
        $this->assertEquals(18, $entitlement->effective_max_users);
    }

    public function test_single_addon_with_multiple_quantity_accumulates(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'user_quota', 7);
        $entitlement->refresh();

        $this->assertEquals(17, $entitlement->effective_max_users);
    }

    public function test_branch_quota_addons_accumulate_on_top_of_base_one(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'branch_quota', 4);
        $calculator->addAddon($entitlement, 'branch_quota', 2);
        $entitlement->refresh();

        // 1 (base) + 4 + 2
        $this->assertEquals(7, $entitlement->effective_max_branches);
    }

    public function test_module_addons_are_merged_with_tier_modules_without_duplicates(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA', 'ModB']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'module', 1, 'ModC');
        $calculator->addAddon($entitlement, 'module', 1, 'ModA'); // duplicate of tier module

        $entitlement->refresh();

        $this->assertEqualsCanonicalizing(['ModA', 'ModB', 'ModC'], $entitlement->effective_modules);
        $this->assertCount(3, $entitlement->effective_modules);
    }

    public function test_expired_addon_is_not_counted(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'user_quota', 5); // active
        // expired add-on (effective_until in the past) must be ignored
        $calculator->addAddon($entitlement, 'user_quota', 99, effectiveUntil: now()->subDay()->toIso8601String());

        $entitlement->refresh();

        // 10 + 5 only; the 99 expired add-on is dropped
        $this->assertEquals(15, $entitlement->effective_max_users);
    }

    public function test_future_dated_addon_is_not_counted_yet(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'user_quota', 50, effectiveFrom: now()->addDay()->toIso8601String());

        $entitlement->refresh();

        $this->assertEquals(10, $entitlement->effective_max_users);
    }

    public function test_revoked_addon_is_not_counted(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $addon = $calculator->addAddon($entitlement, 'user_quota', 40);
        $addon->update(['status' => 'revoked']);
        $calculator->recalculate($entitlement);

        $entitlement->refresh();

        $this->assertEquals(10, $entitlement->effective_max_users);
    }

    public function test_time_extension_addon_extends_valid_until(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA'], 365);
        $calculator = $this->calculator();

        $original = $entitlement->valid_until->copy();
        $calculator->addAddon($entitlement, 'time_extension', 30);

        $entitlement->refresh();

        $expected = $original->copy()->addDays(30);
        $this->assertEquals($expected->timestamp, $entitlement->valid_until->timestamp);
    }

    public function test_expire_due_addons_flips_status_and_recalculates(): void
    {
        $entitlement = $this->makeEntitlement(10, ['ModA']);
        $calculator = $this->calculator();

        $calculator->addAddon($entitlement, 'user_quota', 5);
        // This add-on is already past effective_until but still status 'active'
        $calculator->addAddon($entitlement, 'user_quota', 99, effectiveUntil: now()->subDay()->toIso8601String());

        $entitlement->refresh();
        $this->assertEquals(15, $entitlement->effective_max_users);

        $count = $calculator->expireDueAddons($entitlement);

        $this->assertEquals(1, $count);
        $entitlement->refresh();
        // the expired one was never counted, so effective total is unchanged
        $this->assertEquals(15, $entitlement->effective_max_users);
    }
}
