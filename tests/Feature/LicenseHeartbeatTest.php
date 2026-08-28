<?php

namespace Tests\Feature;

use App\Models\LicenseKey;
use App\Models\LicenseEntitlement;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for POST /api/v1/licenses/heartbeat (LicenseApiController@heartbeat).
 *
 * Authenticated via the service-to-service token issued at activation
 * (Authorization: Bearer <s2s_token>), verified by the
 * AuthenticateServiceToken middleware (hash matches tenant.api_token_hash).
 *
 * Verified real behavior:
 *   - A heartbeat with a valid token and active entitlement returns
 *     status "active" and a freshly signed license token.
 *   - When an add-on expires and the effective quota is reduced, the controller
 *     triggers the force-disable warning and the NEXT token reflects the new
 *     (reduced) effective_max_users -- i.e. the new token is different and
 *     carries the updated values.
 *   - A suspended ENTITLEMENT is reported back with status "unlicensed" (200),
 *     because the client only needs to know it no longer holds a valid license.
 *   - A suspended/terminated TENANT is rejected with 403 by the auth middleware
 *     (the account itself is disabled), before the controller runs.
 *   - An unauthenticated request (no/invalid token) is rejected with 401.
 */
class LicenseHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function setupActivatedTenant(string $hardwareId = 'HW-HB'): array
    {
        $tier = Tier::factory()->create([
            'base_max_users' => 10,
            'included_modules' => ['ModA'],
        ]);
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $licenseKey = LicenseKey::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'active',
            'hardware_id' => $hardwareId,
            'instance_id' => 'INST-HB',
        ]);

        $entitlement = app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );

        $s2sToken = 'rme_hub_'.\Illuminate\Support\Str::random(48);
        $tenant->update(['api_token_hash' => hash('sha256', $s2sToken)]);

        return [$tenant, $licenseKey, $entitlement, $s2sToken];
    }

    private function heartbeatPayload(string $s2sToken, string $hw = 'HW-HB'): array
    {
        return [
            'instance_id' => 'INST-HB',
            'client_code' => '',
            'license_key' => '',
            'hardware_id' => $hw,
            'app_version' => '1.0.0',
            'php_version' => '8.3',
            'timestamp' => time(),
        ];
    }

    public function test_heartbeat_returns_active_status_and_token(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant();

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'status' => 'active']);
        $response->assertJsonStructure(['token', 'effective_max_users', 'effective_modules']);
        $this->assertNotEmpty($response->json('token'));

        // Heartbeat was recorded and tenant's last_heartbeat_at updated.
        $this->assertDatabaseHas('tenant_heartbeats', ['tenant_id' => $tenant->id]);
        $tenant->refresh();
        $this->assertNotNull($tenant->last_heartbeat_at);
    }

    public function test_heartbeat_fresh_token_reflects_reduced_quota_after_addon_expiry(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant();
        $calculator = app(EntitlementCalculator::class);

        // Active user_quota add-on lifts the cap from 10 to 15.
        $calculator->addAddon($entitlement, 'user_quota', 5);
        $entitlement->refresh();
        $this->assertEquals(15, $entitlement->effective_max_users);

        // First heartbeat: token reflects 15 users.
        $first = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));
        $first->assertStatus(200);
        $this->assertEquals(15, $first->json('effective_max_users'));

        // Now expire that add-on (effective_until in the past). The controller
        // recalculates on every heartbeat.
        $entitlement->addons()->update(['effective_until' => now()->subDay()]);

        $second = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));
        $second->assertStatus(200);
        $this->assertEquals(10, $second->json('effective_max_users'));

        // The token actually changed because the license changed (quota reduced).
        $this->assertNotEquals($first->json('token'), $second->json('token'));

        // The force-disable warning was triggered by the quota reduction.
        $this->assertDatabaseHas('force_disable_actions', [
            'tenant_id' => $tenant->id,
            'trigger_type' => 'user_quota_exceeded',
            'previous_limit' => 15,
            'new_limit' => 10,
            'status' => 'warning_sent',
        ]);
    }

    public function test_heartbeat_reports_unlicensed_for_suspended_entitlement(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant();
        $entitlement->update(['status' => 'suspended']);

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        // Real behavior: an inactive entitlement yields status "unlicensed".
        $response->assertStatus(200);
        $response->assertJson(['status' => 'unlicensed', 'success' => false]);
    }

    public function test_heartbeat_reports_expired_after_entitlement_expiry(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant();
        $entitlement->update(['valid_until' => now()->subDay()]);

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'expired', 'success' => false]);
    }

    public function test_heartbeat_rejected_403_when_tenant_suspended(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant();
        $tenant->update(['status' => 'suspended']);

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        // Auth middleware rejects the suspended tenant account with 403.
        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Tenant account is suspended or terminated.');
    }

    public function test_heartbeat_rejected_401_without_token(): void
    {
        $response = $this->postJson('/api/v1/licenses/heartbeat', [
            'hardware_id' => 'HW-X',
        ]);

        $response->assertStatus(401);
    }

    public function test_heartbeat_rejected_401_with_invalid_token(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->postJson('/api/v1/licenses/heartbeat', ['hardware_id' => 'HW-X']);

        $response->assertStatus(401);
    }
}
