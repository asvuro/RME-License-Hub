<?php

namespace Database\Factories;

use App\Models\ModuleModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModuleModel>
 */
class ModuleModelFactory extends Factory
{
    protected $model = ModuleModel::class;

    public function definition(): array
    {
        return [
            'slug' => 'mod-'.Str::random(6),
            'name' => fake()->word(),
            'description' => null,
            'is_active' => true,
        ];
    }
}
