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
        $secret = Str::random(48);

        return [
            'id' => (string) Str::uuid(),
            'group_id' => null,
            'client_code' => strtoupper(fake()->unique()->bothify('CLIENT-####')),
            'client_name' => fake()->company(),
            'legal_entity_name' => fake()->company(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => null,
            'address' => null,
            'status' => 'active',
            'api_token_hash' => hash('sha256', Str::random(48)),
            'webhook_secret_hash' => hash('sha256', $secret),
            'webhook_secret' => $secret, // encrypted cast
            'instance_url' => 'https://rs-'.Str::random(4).'.example.test',
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

    /**
     * Returns the plaintext token that pairs with the stored api_token_hash,
     * so tests can authenticate as this tenant.
     */
    public function withPlainToken(?string &$plain = null): static
    {
        return $this->afterMaking(function (Tenant $tenant) use (&$plain) {
            $plain = Str::random(48);
            $tenant->api_token_hash = hash('sha256', $plain);
        });
    }
}
