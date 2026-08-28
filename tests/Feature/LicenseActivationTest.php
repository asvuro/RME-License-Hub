<?php

namespace Tests\Feature;

use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests for POST /api/v1/licenses/activate (LicenseApiController@activate).
 *
 * Verified against the actual controller implementation:
 *   - 422 when license_key not found
 *   - 422 when the tenant is suspended/terminated
 *   - 422 when the license key is revoked
 *   - 422 when the license is already active on a DIFFERENT machine (HWID mismatch)
 *   - 422 when there is no entitlement for the key
 *   - 422 when the entitlement has expired (license is then marked expired)
 *   - 200 with a signed token when license_key + hardware_id are valid
 *
 * NOTE: there is NO per-seat / quota check at activation time in the current
 * implementation (quota is enforced later via force-disable). The above is the
 * exhaustive real set of 422 conditions.
 */
class LicenseActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate an ephemeral RSA keypair so the license token signer can run.
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

    private function buildActiveSetup(string $hardwareId = 'HW-ACTIVATE'): array
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

        $entitlement = app(EntitlementCalculator::class)->createEntitlement(
            licenseKeyId: $licenseKey->id,
            tenantId: $tenant->id,
            tierId: $tier->id,
        );

        return [$tenant, $licenseKey, $entitlement];
    }

    private function decodeToken(string $token): array
    {
        [$payload] = explode('.', $token);

        return json_decode(base64_decode($payload), true);
    }

    public function test_activation_succeeds_with_valid_license_key_and_hardware_id(): void
    {
        [$tenant, $licenseKey] = $this->buildActiveSetup();

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-ACTIVATE',
            'hostname' => 'clinic-01',
            'app_version' => '1.2.3',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'status', 'instance_id', 's2s_token']);
        $response->assertJson(['status' => 'active']);

        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $payload = $this->decodeToken($token);
        $this->assertEquals($licenseKey->license_key, $payload['license_key']);
        $this->assertEquals('HW-ACTIVATE', $payload['hardware_id']);
        $this->assertEquals(10, $payload['max_users']);

        // License key is now active and bound to the hardware id.
        $licenseKey->refresh();
        $this->assertEquals('active', $licenseKey->status);
        $this->assertEquals('HW-ACTIVATE', $licenseKey->hardware_id);

        // A service-to-service token was issued to the tenant.
        $tenant->refresh();
        $this->assertNotNull($tenant->api_token_hash);

        // An audit log event was recorded.
        $this->assertDatabaseHas('hub_audit_logs', [
            'tenant_id' => $tenant->id,
            'event_type' => 'license.activated',
        ]);
    }

    public function test_activation_fails_422_when_license_key_not_found(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => 'LIC-DOES-NOT-EXIST',
            'hardware_id' => 'HW-X',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $response->assertJsonPath('message', 'License key not found.');
    }

    public function test_activation_fails_422_when_tenant_is_suspended(): void
    {
        [, $licenseKey] = $this->buildActiveSetup();
        $licenseKey->tenant->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-X',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Tenant account is suspended or terminated.');
    }

    public function test_activation_fails_422_when_license_key_is_revoked(): void
    {
        [, $licenseKey] = $this->buildActiveSetup();
        $licenseKey->update(['status' => 'revoked']);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-X',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'License key has been revoked.');
    }

    public function test_activation_fails_422_on_hardware_id_mismatch(): void
    {
        [$tenant, $licenseKey] = $this->buildActiveSetup();
        // Already activated on a different machine.
        $licenseKey->update([
            'status' => 'active',
            'hardware_id' => 'HW-OTHER-MACHINE',
        ]);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-NEW-MACHINE',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'License is already activated on a different machine.');
    }

    public function test_activation_fails_422_when_no_entitlement(): void
    {
        $tenant = Tenant::factory()->create();
        $licenseKey = LicenseKey::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'unused',
        ]);
        // Intentionally NO entitlement created for this key.

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-X',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No entitlement found for this license key.');
    }

    public function test_activation_fails_422_when_license_expired(): void
    {
        [$tenant, $licenseKey, $entitlement] = $this->buildActiveSetup();
        $entitlement->update(['valid_until' => now()->subDay()]);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $licenseKey->license_key,
            'hardware_id' => 'HW-ACTIVATE',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'License has expired.');

        // License key is marked expired as a side effect.
        $licenseKey->refresh();
        $this->assertEquals('expired', $licenseKey->status);
    }

    public function test_missing_required_fields_returns_validation_error(): void
    {
        $response = $this->postJson('/api/v1/licenses/activate', [
            'hardware_id' => 'HW-X',
            // license_key missing
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['license_key']);
    }
}
