<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupReferral;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupReferral>
 */
class GroupReferralFactory extends Factory
{
    protected $model = GroupReferral::class;

    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'source_branch_id' => Tenant::factory(),
            'destination_branch_id' => Tenant::factory(),
            'source_patient_id' => (string) fake()->numberBetween(1, 99999),
            'patient_snapshot' => ['name' => fake()->name(), 'birth_date' => fake()->date()],
            'reason' => fake()->sentence(10),
            'clinical_summary' => fake()->paragraph(),
            'status' => 'requested',
            'referred_at' => now(),
        ];
    }
}
