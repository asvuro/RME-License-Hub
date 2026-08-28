<?php

namespace Database\Factories;

use App\Models\LicenseEntitlement;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicenseEntitlement>
 */
class LicenseEntitlementFactory extends Factory
{
    protected $model = LicenseEntitlement::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'license_key_id' => LicenseKey::factory(),
            'tenant_id' => Tenant::factory(),
            'tier_id' => Tier::factory(),
            'status' => 'active',
            'base_max_users' => 0,
            'base_max_branches' => 1,
            'effective_max_users' => 0,
            'effective_max_branches' => 1,
            'effective_modules' => [],
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addYear(),
            'last_recalculated_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => 'active', 'valid_until' => now()->subDay()]);
    }
}
