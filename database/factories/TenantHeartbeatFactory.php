<?php

namespace Database\Factories;

use App\Models\TenantHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantHeartbeat>
 */
class TenantHeartbeatFactory extends Factory
{
    protected $model = TenantHeartbeat::class;

    public function definition(): array
    {
        return [
            'tenant_id' => \App\Models\Tenant::factory(),
            'instance_id' => 'INST-'.strtoupper(Str::random(16)),
            'license_key' => 'LIC-'.strtoupper(Str::random(20)),
            'hardware_id' => 'HW-'.strtoupper(Str::random(8)),
            'app_version' => '1.0.0',
            'php_version' => '8.3',
            'hostname' => fake()->domainName(),
            'ip_address' => fake()->ipv4(),
            'metadata' => null,
        ];
    }
}
