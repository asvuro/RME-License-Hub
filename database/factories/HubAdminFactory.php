<?php

namespace Database\Factories;

use App\Models\HubAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<HubAdmin>
 */
class HubAdminFactory extends Factory
{
    protected $model = HubAdmin::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => 'operator',
            'is_active' => true,
        ];
    }

    public function superadmin(): static
    {
        return $this->state(fn () => ['role' => 'superadmin']);
    }
}
