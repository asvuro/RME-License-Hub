<?php

namespace Tests\Feature;

use App\Models\ForceDisableAction;
use App\Models\LicenseEntitlement;
use App\Models\LicenseKey;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Tier;
use App\Services\EntitlementCalculator;
use App\Services\ForceDisableManager;
use App\Services\RosterService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\DatabaseTestCase;
use Illuminate\Support\Str;

class LicenseActivateForceDisableTest extends DatabaseTestCase
{
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
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $privPath = $dir.'/test_license_private.pem';
        $pubPath = $dir.'/test_license_public.pem';
        file_put_contents($privPath, $private);
        file_put_contents($pubPath, $public);

        Config::set('license.private_key_path', $privPath);
        Config::set('license.public_key_path', $pubPath);
    }

    private function issueEntitlement(Tenant $tenant, int $baseUsers, array $modules = ['core']): LicenseEntitlement
    {
        $tier = Tier::create([
            'slug' => 'tier-'.uniqid(),
            'name' => 'T',
            'base_max_users' => $baseUsers,
            'default_duration_days' => 365,
            'included_modules' => $modules,
            'is_active' => true,
        ]);
        $licenseKey = LicenseKey::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'license_key' => 'LIC-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(4)).'-'.strtoupper(Str::random(8)),
            'status' => 'active',
            'instance_id' => 'INST-'.strtoupper(Str::random(8)),
        ]);

        return app(EntitlementCalculator::class)->createEntitlement($licenseKey->id, $tenant->id, $tier->id);
    }

    private function reportRoster(Tenant $tenant, array $specs): void
    {
        $rows = [];
        foreach ($specs as [$daysAgo, $isAdmin]) {
            $rows[] = [
                'user_id' => Str::random(6),
                'is_admin' => $isAdmin,
                'is_active' => true,
                'registered_at' => now()->subDays($daysAgo)->toIso8601String(),
            ];
        }
        app(RosterService::class)->replaceRoster($tenant, $rows);
    }

    public function test_activate_returns_signed_token_and_persists_instance(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $ent = $this->issueEntitlement($tenant, 50);

        $response = $this->postJson('/api/v1/licenses/activate', [
            'license_key' => $ent->licenseKey->license_key,
            'hardware_id' => 'HWID-TEST',
            'hostname' => 'srv-1',
            'app_version' => '2.1.0',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'status', 'instance_id']);
        $response->assertJson(['status' => 'active']);
        $this->assertNotNull($response->json('instance_id'));
    }

    public function test_activate_unknown_license_key_rejected(): void
    {
        $this->postJson('/api/v1/licenses/activate', [
            'license_key' => 'LIC-NOPE-NOPE-NOPE',
            'hardware_id' => 'HWID-TEST',
        ])->assertStatus(422);
    }

    public function test_force_disable_warning_never_targets_last_admin(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $ent = $this->issueEntitlement($tenant, 5); // base quota 5

        // Report 7 active users: newest (day 1) is an admin, others non-admin.
        // Order by registration: [100,90,80,70,60,50 days] non-admins + [1 day] admin
        $this->reportRoster($tenant, [
            [100, false], [90, false], [80, false], [70, false], [60, false], [50, false], [1, true],
        ]);

        $previous = $ent->effective_max_users; // 5
        // Admin manually reduces quota to 3 (simulate add-on expiry downstream).
        $ent->update(['effective_max_users' => 3, 'base_max_users' => 3]);

        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($ent, $previous);

        $this->assertNotNull($action);
        $this->assertSame('warning_sent', $action->status);
        // 7 active, quota 3 => 4 in the newest tail; the newest is the only
        // admin, so it is protected and only 3 non-admins are ordered disabled.
        $this->assertCount(3, $action->affected_user_ids);
        $this->assertCount(1, $action->last_admin_protected_ids);

        // The protected admin id must NOT appear in the disable list.
        foreach ($action->last_admin_protected_ids as $adminId) {
            $this->assertNotContains($adminId, $action->affected_user_ids);
        }
    }

    public function test_force_disable_execute_orders_disable_and_marks_roster(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $ent = $this->issueEntitlement($tenant, 5);
        $this->reportRoster($tenant, [
            [100, false], [90, false], [80, false], [70, false], [60, false], [50, false], [1, true],
        ]);

        $previous = $ent->effective_max_users;
        $ent->update(['effective_max_users' => 3, 'base_max_users' => 3]);
        $manager = app(ForceDisableManager::class);
        $action = $manager->checkAndTrigger($ent, $previous);
        $this->assertNotNull($action);

        // Force the grace period to have elapsed.
        $action->update(['warning_sent_at' => now()->subHours(80)]);
        $ok = $manager->execute($action);
        $this->assertTrue($ok);
        $action->refresh();
        $this->assertSame('executed', $action->status);

        // The ordered users are now inactive in the hub's cached roster.
        // 7 active, 3 disabled (last admin protected) => 4 remain active.
        $stillActive = TenantUser::where('tenant_id', $tenant->id)->where('is_active', true)->count();
        $this->assertSame(4, $stillActive);
    }

    public function test_no_force_disable_when_roster_fits_quota(): void
    {
        $tenant = Tenant::factory()->create();
        $ent = $this->issueEntitlement($tenant, 10);
        $this->reportRoster($tenant, [[100, false], [90, false], [80, false]]);

        $previous = $ent->effective_max_users; // 10
        $ent->update(['effective_max_users' => 8, 'base_max_users' => 8]);
        $action = app(ForceDisableManager::class)->checkAndTrigger($ent, $previous);
        $this->assertNull($action);
    }
}
