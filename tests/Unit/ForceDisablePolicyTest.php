<?php

namespace Tests\Unit;

use App\Models\LicenseEntitlement;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tier;
use App\Models\WebhookDelivery;
use App\Services\EntitlementCalculator;
use App\Services\ForceDisableManager;
use App\Services\RosterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\DatabaseTestCase;
use Illuminate\Support\Str;

/**
 * Tests for the force-disable POLICY (ForceDisableManager).
 *
 * The hub is the AUTHORITATIVE actor: it selects the exact users to disable
 * (newest-registered active non-admins first, never the last admin) and
 * delivers the ordered target list to the client via signed webhooks. The
 * client then applies exactly that list to modules_statuses.json.
 *
 * Policy invariants verified here:
 *   1. A warning webhook (force_disable.warning) is dispatched BEFORE any
 *      execution (status warning_sent, never straight to executed).
 *   2. The warning carries admin_protection = true and the grace period.
 *   3. The executed webhook carries the explicit disable_order (newest_first),
 *      the admin_protection rule, and the concrete affected_user_ids list.
 *   4. admin_last_protected_ids is always populated when the last admin would
 *      otherwise be disabled (the hub guarantees last-admin protection).
 *   5. Execution only happens after the grace period, and only once.
 */
class ForceDisablePolicyTest extends DatabaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('license.force_disable_grace_hours', 72);
    }

    private function makeEntitlementAndTenant(int $baseUsers): array
    {
        $tier = Tier::factory()->create([
            'base_max_users' => $baseUsers,
            'included_modules' => ['ModA'],
        ]);
        $tenant = Tenant::factory()->create();
        $licenseKey = \App\Models\LicenseKey::factory()->create(['tenant_id' => $tenant->id]);

        $entitlement = app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );

        return [$tenant, $entitlement];
    }

    private function seedRoster(Tenant $tenant, int $activeUsers, int $adminCount): void
    {
        $svc = app(RosterService::class);
        $users = [];
        for ($i = 0; $i < $activeUsers; $i++) {
            $users[] = [
                'external_user_id' => 'U'.Str::random(6),
                'is_admin' => false,
                'registered_at' => now()->subDays(100 - $i)->toDateTimeString(),
                'is_active' => true,
            ];
        }
        for ($i = 0; $i < $adminCount; $i++) {
            $users[] = [
                'external_user_id' => 'A'.Str::random(6),
                'is_admin' => true,
                'registered_at' => now()->subDays(50 - $i)->toDateTimeString(),
                'is_active' => true,
            ];
        }
        $svc->replaceRoster($tenant, $users);
    }

    public function test_warning_is_sent_before_execution_and_records_policy(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(20);
        $this->seedRoster($tenant, 10, 1);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);

        $this->assertNotNull($action);
        $this->assertDatabaseHas('force_disable_actions', [
            'id' => $action->id,
            'status' => 'warning_sent',
            'trigger_type' => 'user_quota_exceeded',
            'previous_limit' => 20,
            'new_limit' => 10,
        ]);

        $action->refresh();
        $this->assertNotNull($action->warning_sent_at);
        $this->assertNull($action->executed_at);

        // A warning webhook MUST be created before execution.
        $warning = WebhookDelivery::where('event_type', 'force_disable.warning')
            ->where('tenant_id', $tenant->id)
            ->first();
        $this->assertNotNull($warning, 'A force_disable.warning webhook must be dispatched before any execution.');

        // No execution webhook yet.
        $this->assertDatabaseMissing('webhook_deliveries', [
            'event_type' => 'force_disable.executed',
            'tenant_id' => $tenant->id,
        ]);

        // The warning payload must record admin protection and grace period.
        $payload = $warning->payload;
        $this->assertTrue($payload['admin_protection']);
        $this->assertArrayHasKey('grace_period_hours', $payload);
        $this->assertEquals(72, $payload['grace_period_hours']);
        $this->assertEquals(20, $payload['previous_limit']);
        $this->assertEquals(10, $payload['new_limit']);
    }

    public function test_executed_webhook_carries_disable_order_and_admin_protection_rules(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(20);
        // Roster: 1 admin (oldest) + 10 non-admins; quota drops 20 -> 10, so the
        // newest non-admins are disabled (the admin is protected by being older,
        // but never the last admin either way).
        $roster = [];
        $roster[] = ['external_user_id' => 'A0', 'is_admin' => true, 'registered_at' => now()->subDays(200)->toDateTimeString(), 'is_active' => true];
        for ($i = 0; $i < 10; $i++) {
            $roster[] = ['external_user_id' => 'U'.$i, 'is_admin' => false, 'registered_at' => now()->subDays(100 - $i)->toDateTimeString(), 'is_active' => true];
        }
        app(RosterService::class)->replaceRoster($tenant, $roster);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);
        $action->update(['warning_sent_at' => now()->subHours(80)]);
        $manager->execute($action->refresh());

        $executed = WebhookDelivery::where('event_type', 'force_disable.executed')
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        // The execute webhook encodes the exact client-side policy:
        // newest-registered users disabled first, and the last admin is never disabled.
        $this->assertEquals('newest_first', $executed->payload['rules']['disable_order']);
        $this->assertEquals('always_protect_last_admin', $executed->payload['rules']['admin_protection']);
        $this->assertTrue($executed->payload['admin_protection']);
        $this->assertNotEmpty($executed->payload['disable_user_ids']);
    }

    public function test_no_action_when_quota_is_not_reduced(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(10);
        $this->seedRoster($tenant, 5, 1);
        // effective (10) >= previous (10): no trigger
        $action = app(ForceDisableManager::class)->checkAndTrigger($entitlement, 10);

        $this->assertNull($action);
        $this->assertDatabaseCount('force_disable_actions', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_execution_is_blocked_during_grace_period(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(20);
        $this->seedRoster($tenant, 10, 1);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);

        // Grace period (72h) not elapsed yet -> execute() is a no-op.
        $result = $manager->execute($action);

        $this->assertFalse($result);
        $action->refresh();
        $this->assertEquals('warning_sent', $action->status);
        $this->assertNull($action->executed_at);
        $this->assertDatabaseMissing('webhook_deliveries', [
            'event_type' => 'force_disable.executed',
        ]);
    }

    public function test_execution_fires_after_grace_period_and_only_once(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(20);
        $this->seedRoster($tenant, 10, 1);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);

        // Backdate the warning beyond the grace period.
        $action->update(['warning_sent_at' => now()->subHours(80)]);
        $action->refresh();

        $first = $manager->execute($action);
        $this->assertTrue($first);
        $action->refresh();
        $this->assertEquals('executed', $action->status);
        $this->assertNotNull($action->executed_at);

        $this->assertDatabaseHas('webhook_deliveries', [
            'event_type' => 'force_disable.executed',
            'tenant_id' => $tenant->id,
        ]);

        // Executing again must be a no-op (already executed).
        $second = $manager->execute($action->refresh());
        $this->assertFalse($second);
    }

    public function test_admin_last_protected_is_an_invariant(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(20);
        // Only ONE admin among 10 users; a quota drop to 5 forces selection of
        // 5 newest, which would include the admin -> must be protected.
        $this->seedRoster($tenant, 9, 1);
        $entitlement->update(['effective_max_users' => 5]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);

        // The hub records the protected last admin.
        $this->assertNotEmpty($action->last_admin_protected_ids);

        $warning = WebhookDelivery::where('event_type', 'force_disable.warning')
            ->where('tenant_id', $tenant->id)
            ->first();
        $this->assertNotNull($warning);
        $this->assertTrue($warning->payload['admin_protection']);
        $this->assertStringContainsString(
            'admin',
            strtolower($warning->payload['instructions'])
        );
    }

    public function test_pending_actions_are_processed_after_grace_period(): void
    {
        [$tenant, $entitlement] = $this->makeEntitlementAndTenant(20);
        $this->seedRoster($tenant, 10, 1);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);
        // Make it eligible for processing.
        $action->update(['warning_sent_at' => now()->subHours(80)]);

        $executed = $manager->processPendingActions();

        $this->assertGreaterThanOrEqual(1, $executed);
        $action->refresh();
        $this->assertEquals('executed', $action->status);
    }
}
