<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookDelivery>
 */
class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'event_type' => 'license.updated',
            'event_id' => 'evt-'.Str::uuid()->toString(),
            'payload' => [],
            'url' => null,
            'attempts' => 0,
            'max_attempts' => 5,
            'delivered_at' => null,
            'next_attempt_at' => null,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['delivered_at' => now()]);
    }
}
