<?php

namespace Tests\Feature;

use App\Models\HubAdmin;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Verifies that HubAdminController writes audit-log rows for the three state-
 * changing actions that previously had NO audit trail:
 *   - createGroup()       -> event_type `group.created`
 *   - pushModuleSync()     -> event_type `modules.synced`
 *   - pushAllModuleSync()  -> event_type `modules.synced_all`
 *
 * The five already-audited actions (addTenantToGroup, createTenant,
 * suspendTenant, addAddon, issueLicenseKey) are intentionally left untouched —
 * these tests only cover the previously-missing gap. Mirrors the exact
 * `HubAuditLog::create([...])` shape used in the untouched methods.
 */
class HubAdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function authToken(HubAdmin $admin): string
    {
        return $admin->createToken('test')->plainTextToken;
    }

    private function buildTenantWithActiveEntitlement(): Tenant
    {
        $tier = Tier::factory()->create([
            'base_max_users' => 10,
            'included_modules' => ['ModA'],
        ]);
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $licenseKey = LicenseKey::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'unused',
        ]);

        app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );

        return $tenant;
    }

    public function test_create_group_writes_group_created_audit_log(): void
    {
        Queue::fake();
        Http::fake();
        $admin = HubAdmin::factory()->create();
        $token = $this->authToken($admin);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hub/groups', [
                'name' => 'Regional Clinics',
                'legal_entity_name' => 'PT Regional Sehat',
            ]);

        $response->assertStatus(201);

        // Group-level action: no single tenant is affected, so tenant_id is null.
        $this->assertDatabaseHas('hub_audit_logs', [
            'tenant_id' => null,
            'event_type' => 'group.created',
            'actor_id' => $admin->id,
            'actor_type' => 'admin',
        ]);

        $row = \App\Models\HubAuditLog::where('event_type', 'group.created')->first();
        $this->assertNotNull($row);
        $this->assertArrayHasKey('group_id', $row->details);
        $this->assertSame('Regional Clinics', $row->details['name']);
    }

    public function test_push_module_sync_writes_modules_synced_audit_log(): void
    {
        Queue::fake();
        Http::fake();
        $admin = HubAdmin::factory()->create();
        $token = $this->authToken($admin);
        $tenant = $this->buildTenantWithActiveEntitlement();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hub/sync/tenant/'.$tenant->id);

        $response->assertStatus(200);

        $this->assertDatabaseHas('hub_audit_logs', [
            'tenant_id' => $tenant->id,
            'event_type' => 'modules.synced',
            'actor_id' => $admin->id,
            'actor_type' => 'admin',
        ]);

        $row = \App\Models\HubAuditLog::where('event_type', 'modules.synced')->first();
        $this->assertNotNull($row);
        $this->assertSame($tenant->id, $row->details['tenant_id']);
        $this->assertSame($tenant->client_code, $row->details['tenant_code']);
        $this->assertIsInt($row->details['modules_pushed']);
    }

    public function test_push_all_module_sync_writes_modules_synced_all_audit_log(): void
    {
        Queue::fake();
        Http::fake();
        $admin = HubAdmin::factory()->create();
        $token = $this->authToken($admin);

        // Create an active tenant so that at least one tenant is synced.
        $this->buildTenantWithActiveEntitlement();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/hub/sync/all');

        $response->assertStatus(200);

        // Affects ALL tenants (not one) -> tenant_id null, count in details.
        $this->assertDatabaseHas('hub_audit_logs', [
            'tenant_id' => null,
            'event_type' => 'modules.synced_all',
            'actor_id' => $admin->id,
            'actor_type' => 'admin',
        ]);

        $row = \App\Models\HubAuditLog::where('event_type', 'modules.synced_all')->first();
        $this->assertNotNull($row);
        $this->assertGreaterThanOrEqual(1, $row->details['tenants_synced']);
    }
}
