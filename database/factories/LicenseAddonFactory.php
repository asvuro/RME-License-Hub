<?php

namespace Database\Factories;

use App\Models\LicenseAddon;
use App\Models\LicenseEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicenseAddon>
 */
class LicenseAddonFactory extends Factory
{
    protected $model = LicenseAddon::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'entitlement_id' => LicenseEntitlement::factory(),
            'addon_type' => 'module',
            'target_module_slug' => null,
            'quantity' => 1,
            'label' => null,
            'effective_from' => null,
            'effective_until' => null,
            'status' => 'active',
        ];
    }

    public function userQuota(int $quantity = 1): static
    {
        return $this->state(fn () => ['addon_type' => 'user_quota', 'quantity' => $quantity]);
    }

    public function branchQuota(int $quantity = 1): static
    {
        return $this->state(fn () => ['addon_type' => 'branch_quota', 'quantity' => $quantity]);
    }

    public function module(?string $slug = null): static
    {
        return $this->state(fn () => [
            'addon_type' => 'module',
            'target_module_slug' => $slug ?? fake()->word(),
        ]);
    }

    public function timeExtension(int $days = 30): static
    {
        return $this->state(fn () => ['addon_type' => 'time_extension', 'quantity' => $days]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['effective_until' => now()->subDay()]);
    }

    public function future(): static
    {
        return $this->state(fn () => ['effective_from' => now()->addDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => 'revoked']);
    }
}
