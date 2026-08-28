<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'group_id' => null,
            'client_code' => strtoupper(fake()->unique()->bothify('CLIENT-####')),
            'client_name' => fake()->company(),
            'legal_entity_name' => null,
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => null,
            'address' => null,
            'status' => 'active',
            'api_token_hash' => null,
            'webhook_secret_hash' => null,
            'last_heartbeat_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }

    public function terminated(): static
    {
        return $this->state(fn () => ['status' => 'terminated']);
    }
}
