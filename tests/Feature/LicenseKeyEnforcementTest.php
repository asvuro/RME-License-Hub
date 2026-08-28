<?php

namespace Tests\Feature;

use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression / security tests for enforcing LicenseKey.status on the
 * client-facing endpoints (heartbeat, validate, activate).
 *
 * Business context: the admin dashboard can revoke or suspend a LicenseKey
 * (LicenseKey.status -> "revoked"/"suspended"). Those actions USED to be
 * purely cosmetic because LicenseApiController::heartbeat()/validate() only
 * checked the LicenseEntitlement (activeEntitlement) + entitlement expiry,
 * never the parent LicenseKey.status. So a client with a still-`active`
 * entitlement kept phoning home and minting fresh tokens forever.
 *
 * These tests pin the corrected behavior:
 *   - LicenseKey revoked  -> heartbeat/validate reject (status "revoked", 200)
 *   - LicenseKey suspended -> heartbeat/validate reject (status "suspended", 200)
 *   - LicenseKey active   -> heartbeat/validate still succeed (regression guard)
 *   - activate() rejects a revoked OR suspended LicenseKey with 422.
 *
 * Verified real behavior (no mocks): the entitlement is created through the
 * real EntitlementCalculator over the real FK chain so the gate actually
 * resolves $entitlement->licenseKey->status.
 */
class LicenseKeyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $keyPair = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyPair, $private);
        $public = openssl_pkey_get_details($keyPair)['key'];

        $dir = storage_path('keys');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $privPath = $dir.'/test_license_private.pem';
        $pubPath = $dir.'/test_license_public.pem';
        file_put_contents($privPath, $private);
        file_put_contents($pubPath, $public);

        Config::set('license.private_key_path', $privPath);
        Config::set('license.public_key_path', $pubPath);
    }

    /**
     * Build an activated tenant whose LicenseKey is `active` with a real
     * entitlement hanging off it via license_key_id (FK). Returns the tuple
     * plus the s2s token used for heartbeat/validate auth.
     */
    private function setupActivatedTenant(string $licenseStatus = 'active'): array
    {
        $tier = Tier::factory()->create([
            'base_max_users' => 10,
            'included_modules' => ['ModA'],
        ]);
        $tenant = Tenant::factory()->create(['status' => 'active']);
        $licenseKey = LicenseKey::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => $licenseStatus,
            'hardware_id' => 'HW-HB',
            'instance_id' => 'INST-HB',
        ]);

        $entitlement = app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );

        $s2sToken = 'rme_hub_'.Str::random(48);
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

    // ---------------------------------------------------------------------
    // HEARTBEAT
    // ---------------------------------------------------------------------

    public function test_heartbeat_accepts_active_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('active');

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'status' => 'active']);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_heartbeat_rejects_revoked_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('revoked');

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        // Must NOT mint a new token and must NOT 500. Shape mirrors the
        // existing "unlicensed"/"expired" responses.
        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'status' => 'revoked',
            'message' => 'License key has been revoked.',
        ]);
        // No new token is minted — the client is cut off (not merely flagged).
        $this->assertNull($response->json('token'));
    }

    public function test_heartbeat_rejects_suspended_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('suspended');

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/heartbeat', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'status' => 'suspended',
            'message' => 'License key has been suspended.',
        ]);
        $this->assertNull($response->json('token'));
    }

    // ---------------------------------------------------------------------
    // VALIDATE
    // ---------------------------------------------------------------------

    public function test_validate_accepts_active_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('active');

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/validate', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'status' => 'active']);
    }

    public function test_validate_rejects_revoked_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('revoked');

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/validate', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'status' => 'revoked',
            'message' => 'License key has been revoked.',
        ]);
    }

    public function test_validate_rejects_suspended_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('suspended');

        $response = $this->withHeader('Authorization', 'Bearer '.$s2sToken)
            ->postJson('/api/v1/licenses/validate', $this->heartbeatPayload($s2sToken));

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'status' => 'suspended',
            'message' => 'License key has been suspended.',
        ]);
    }

    // ---------------------------------------------------------------------
    // ACTIVATE
    // ---------------------------------------------------------------------

    public function test_activate_rejects_revoked_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('revoked');

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-ACTIVATE',
            'hostname' => 'srv-1',
            'app_version' => '1.0.0',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'License key has been revoked.',
        ]);
    }

    public function test_activate_rejects_suspended_license_key(): void
    {
        [$tenant, $licenseKey, $entitlement, $s2sToken] = $this->setupActivatedTenant('suspended');

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-ACTIVATE',
            'hostname' => 'srv-1',
            'app_version' => '1.0.0',
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
            'message' => 'License key has been suspended.',
        ]);
    }
}
