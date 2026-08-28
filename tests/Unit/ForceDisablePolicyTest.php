<?php

namespace Tests\Unit;

use App\Models\LicenseEntitlement;
use App\Models\Tenant;
use App\Models\Tier;
use App\Models\WebhookDelivery;
use App\Services\EntitlementCalculator;
use App\Services\ForceDisableManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests for the force-disable POLICY (ForceDisableManager).
 *
 * IMPORTANT ARCHITECTURE NOTE (read before reviewing these tests):
 * The hub does NOT itself disable users in the client database. Per the
 * documented contract, the hub can only (a) send a force_disable.warning
 * webhook instructing the client (RME-Backend) of the new quota and the
 * disable policy, then (b) after a grace period, send a force_disable.executed
 * webhook instructing the client to actually disable users. The *actual*
 * disabling (newest-registered-first ordering, never the last admin) happens
 * on the client side, driven by the rules the hub puts in the webhook payload.
 *
 * Therefore these tests verify the hub's real, testable contract:
 *   1. A warning webhook is dispatched BEFORE any execution (status
 *      pending -> warning_sent, never straight to executed).
 *   2. The warning payload encodes the policy: disable_order = newest_first,
 *      admin_protection = always_protect_last_admin, admin_protection = true.
 *   3. The ForceDisableAction record always carries admin_last_protected = true
 *      (the hub guarantees last-admin protection as an invariant).
 *   4. Execution only happens after the grace period has elapsed, and only
 *      once; before that it is a no-op.
 *
 * The client-side enforcement of "newest user disabled first" and "last admin
 * never disabled regardless of registration order" lives in RME-Backend and is
 * out of scope for this hub test suite (noted for the owning agent / PR).
 */
class ForceDisablePolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('license.force_disable_grace_hours', 72);
    }

    private function makeEntitlement(int $baseUsers): LicenseEntitlement
    {
        $tier = Tier::factory()->create([
            'base_max_users' => $baseUsers,
            'included_modules' => ['ModA'],
        ]);
        $tenant = Tenant::factory()->create();
        $licenseKey = \App\Models\LicenseKey::factory()->create(['tenant_id' => $tenant->id]);

        return app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );
    }

    public function test_warning_is_sent_before_execution_and_records_policy(): void
    {
        // Simulate an add-on expiry that reduced the effective quota from 20 -> 10.
        $entitlement = $this->makeEntitlement(20);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);

        $this->assertNotNull($action);
        $this->assertDatabaseHas('force_disable_actions', [
            'id' => $action->id,
            'status' => 'warning_sent',
            'admin_last_protected' => true,
            'previous_limit' => 20,
            'new_limit' => 10,
        ]);

        $action->refresh();
        $this->assertNotNull($action->warning_sent_at);
        $this->assertNull($action->executed_at);

        // A warning webhook MUST be created before execution.
        $warning = WebhookDelivery::where('event_type', 'force_disable.warning')
            ->where('tenant_id', $entitlement->tenant_id)
            ->first();
        $this->assertNotNull($warning, 'A force_disable.warning webhook must be dispatched before any execution.');

        // No execution webhook yet.
        $this->assertDatabaseMissing('webhook_deliveries', [
            'event_type' => 'force_disable.executed',
            'tenant_id' => $entitlement->tenant_id,
        ]);

        // The warning payload must record admin protection and grace period.
        $payload = $warning->payload;
        $this->assertTrue($payload['admin_protection']);
        $this->assertArrayHasKey('grace_period_hours', $payload);
        $this->assertEquals(72, $payload['grace_period_hours']);
        $this->assertEquals(20, $payload['previous_limit']);
        $this->assertEquals(10, $payload['new_limit']);
        $this->assertArrayNotHasKey('rules', $payload,
            'The disable-order/admin-protection rules belong to the EXECUTED webhook, not the warning.');
    }

    public function test_executed_webhook_carries_disable_order_and_admin_protection_rules(): void
    {
        $entitlement = $this->makeEntitlement(20);
        $entitlement->update(['effective_max_users' => 10]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);
        $action->update(['warning_sent_at' => now()->subHours(80)]);
        $manager->execute($action->refresh());

        $executed = WebhookDelivery::where('event_type', 'force_disable.executed')
            ->where('tenant_id', $entitlement->tenant_id)
            ->firstOrFail();

        // The execute webhook encodes the exact client-side policy:
        // newest-registered users disabled first, and the last admin is never disabled.
        $this->assertEquals('newest_first', $executed->payload['rules']['disable_order']);
        $this->assertEquals('always_protect_last_admin', $executed->payload['rules']['admin_protection']);
        $this->assertTrue($executed->payload['admin_protection']);
        $this->assertStringContainsString('last remaining admin', strtolower($executed->payload['instructions']));
    }

    public function test_no_action_when_quota_is_not_reduced(): void
    {
        $entitlement = $this->makeEntitlement(10);
        // effective (10) >= previous (10): no trigger
        $action = app(ForceDisableManager::class)->checkAndTrigger($entitlement, 10);

        $this->assertNull($action);
        $this->assertDatabaseCount('force_disable_actions', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_execution_is_blocked_during_grace_period(): void
    {
        $entitlement = $this->makeEntitlement(20);
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
        $entitlement = $this->makeEntitlement(20);
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
            'tenant_id' => $entitlement->tenant_id,
        ]);

        // Executing again must be a no-op (already executed).
        $second = $manager->execute($action->refresh());
        $this->assertFalse($second);
    }

    public function test_admin_last_protected_is_an_invariant(): void
    {
        $entitlement = $this->makeEntitlement(20);
        $entitlement->update(['effective_max_users' => 5]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($entitlement, 20);

        // Regardless of registration order, the hub records that the last admin
        // is protected and instructs the client to never disable them.
        $this->assertTrue($action->admin_last_protected);

        $warning = WebhookDelivery::where('event_type', 'force_disable.warning')
            ->where('tenant_id', $entitlement->tenant_id)
            ->first();
        $this->assertNotNull($warning);
        $this->assertTrue($warning->payload['admin_protection']);
        $this->assertStringContainsString(
            'last admin',
            strtolower($warning->payload['instructions'])
        );
    }

    public function test_pending_actions_are_processed_after_grace_period(): void
    {
        $entitlement = $this->makeEntitlement(20);
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
