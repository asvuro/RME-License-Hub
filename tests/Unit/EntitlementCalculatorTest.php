<?php

namespace Tests\Unit;

use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use Illuminate\Support\Str;
use Tests\DatabaseTestCase;

class EntitlementCalculatorTest extends DatabaseTestCase
{
    private function tier(array $overrides = []): Tier
    {
        return Tier::create(array_merge([
            'slug' => 'pro-'.uniqid(),
            'name' => 'Pro',
            'base_max_users' => 10,
            'default_duration_days' => 365,
            'included_modules' => ['core', 'billing'],
            'is_active' => true,
        ], $overrides));
    }

    private function licenseKey(Tenant $tenant): LicenseKey
    {
        return LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'status' => 'active',
        ]);
    }

    public function test_base_entitlement_matches_tier(): void
    {
        $tenant = Tenant::factory()->create();
        $tier = $this->tier(['base_max_users' => 10, 'included_modules' => ['core', 'billing']]);
        $lk = $this->licenseKey($tenant);

        $calc = app(EntitlementCalculator::class);
        $entitlement = $calc->createEntitlement(
            licenseKeyId: $lk->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );

        $this->assertSame(10, $entitlement->effective_max_users);
        $this->assertEqualsCanonicalizing(['core', 'billing'], $entitlement->effective_modules);
    }

    public function test_user_quota_addon_increases_effective_max_users(): void
    {
        $tenant = Tenant::factory()->create();
        $tier = $this->tier(['base_max_users' => 10]);
        $lk = $this->licenseKey($tenant);
        $calc = app(EntitlementCalculator::class);
        $ent = $calc->createEntitlement($lk->id, $tenant->id, $tier->id);

        $calc->addAddon($ent, 'user_quota', 5);
        $ent->refresh();
        $this->assertSame(15, $ent->effective_max_users);
    }

    public function test_module_addon_appends_to_effective_modules(): void
    {
        $tenant = Tenant::factory()->create();
        $tier = $this->tier(['included_modules' => ['core']]);
        $lk = $this->licenseKey($tenant);
        $calc = app(EntitlementCalculator::class);
        $ent = $calc->createEntitlement($lk->id, $tenant->id, $tier->id);

        $calc->addAddon($ent, 'module', 1, 'radiologi');
        $ent->refresh();
        $this->assertEqualsCanonicalizing(['core', 'radiologi'], $ent->effective_modules);
    }

    public function test_branch_quota_addon_increases_effective_branches(): void
    {
        $tenant = Tenant::factory()->create();
        $tier = $this->tier();
        $lk = $this->licenseKey($tenant);
        $calc = app(EntitlementCalculator::class);
        $ent = $calc->createEntitlement($lk->id, $tenant->id, $tier->id);

        $calc->addAddon($ent, 'branch_quota', 3);
        $ent->refresh();
        $this->assertSame(4, $ent->effective_max_branches);
    }

    public function test_time_extension_addon_extends_valid_until(): void
    {
        $tenant = Tenant::factory()->create();
        $tier = $this->tier(['default_duration_days' => 365]);
        $lk = $this->licenseKey($tenant);
        $calc = app(EntitlementCalculator::class);
        $ent = $calc->createEntitlement($lk->id, $tenant->id, $tier->id);

        $base = $ent->valid_until->copy();
        $calc->addAddon($ent, 'time_extension', 30);
        $ent->refresh();
        $this->assertEquals(30, (int) $base->diffInDays($ent->valid_until));
    }

    public function test_expired_addon_is_dropped_from_recalculation(): void
    {
        $tenant = Tenant::factory()->create();
        $tier = $this->tier(['base_max_users' => 10]);
        $lk = $this->licenseKey($tenant);
        $calc = app(EntitlementCalculator::class);
        $ent = $calc->createEntitlement($lk->id, $tenant->id, $tier->id);

        $calc->addAddon($ent, 'user_quota', 5, effectiveUntil: now()->subDay());
        $count = $calc->expireDueAddons($ent);
        $ent->refresh();

        $this->assertSame(1, $count);
        $this->assertSame(10, $ent->effective_max_users); // back to base
    }
}
