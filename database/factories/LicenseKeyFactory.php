<?php

namespace Database\Factories;

use App\Models\LicenseKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LicenseKey>
 */
class LicenseKeyFactory extends Factory
{
    protected $model = LicenseKey::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'license_key' => 'LIC-'.strtoupper(Str::random(20)),
            'status' => 'unused',
            'issued_at' => null,
            'valid_until' => null,
            'last_synced_at' => null,
            'hardware_id' => null,
            'instance_id' => null,
            'hostname' => null,
            'app_version' => null,
            'php_version' => null,
        ];
    }

    public function active(string $hardwareId = 'HW-DEFAULT'): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'hardware_id' => $hardwareId,
            'instance_id' => 'INST-'.strtoupper(Str::random(16)),
            'issued_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => 'revoked']);
    }
}
