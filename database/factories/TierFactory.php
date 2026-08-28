<?php

namespace Database\Factories;

use App\Models\Tier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tier>
 */
class TierFactory extends Factory
{
    protected $model = Tier::class;

    public function definition(): array
    {
        return [
            'slug' => 'tier-'.Str::random(8),
            'name' => fake()->word(),
            'description' => null,
            'base_max_users' => 10,
            'default_duration_days' => 365,
            'included_modules' => ['BaseModule'],
            'metadata' => null,
            'is_active' => true,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(fn () => ['base_max_users' => 0, 'included_modules' => ['*']]);
    }
}
