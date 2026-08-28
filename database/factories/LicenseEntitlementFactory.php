<?php

namespace Database\Factories;

use App\Models\LicenseEntitlement;
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
            'license_key_id' => \App\Models\LicenseKey::factory(),
            'tenant_id' => \App\Models\Tenant::factory(),
            'tier_id' => \App\Models\Tier::factory(),
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
