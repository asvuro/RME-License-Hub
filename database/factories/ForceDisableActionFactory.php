<?php

namespace Database\Factories;

use App\Models\ForceDisableAction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ForceDisableAction>
 */
class ForceDisableActionFactory extends Factory
{
    protected $model = ForceDisableAction::class;

    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => \App\Models\Tenant::factory(),
            'entitlement_id' => null,
            'trigger_type' => 'user_quota_exceeded',
            'previous_limit' => 20,
            'new_limit' => 10,
            'users_to_disable' => 0,
            'users_actually_disabled' => 0,
            'admin_last_protected' => true,
            'status' => 'pending',
            'warning_sent_at' => null,
            'executed_at' => null,
            'metadata' => null,
        ];
    }

    public function warningSent(): static
    {
        return $this->state(fn () => [
            'status' => 'warning_sent',
            'warning_sent_at' => now()->subHours(1),
        ]);
    }

    public function executed(): static
    {
        return $this->state(fn () => [
            'status' => 'executed',
            'warning_sent_at' => now()->subHours(80),
            'executed_at' => now(),
        ]);
    }
}
