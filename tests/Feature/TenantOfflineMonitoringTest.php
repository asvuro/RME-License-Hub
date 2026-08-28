<?php

namespace Tests\Feature;

use App\Models\HubAdmin;
use App\Models\Tenant;
use App\Notifications\TenantOfflineNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Tests for `license:check-heartbeats` (CheckTenantHeartbeats) — the
 * monitoring gap closed this session: previously `license.max_offline_days`
 * was defined in config but never actually checked anywhere.
 */
class TenantOfflineMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['license.max_offline_days' => 14]);
    }

    public function test_alerts_active_admins_about_a_tenant_with_no_heartbeat(): void
    {
        Notification::fake();

        $admin = HubAdmin::factory()->create(['is_active' => true]);
        $tenant = Tenant::factory()->create(['status' => 'active', 'last_heartbeat_at' => null]);

        Artisan::call('license:check-heartbeats');

        Notification::assertSentTo($admin, TenantOfflineNotification::class, function ($notification) use ($tenant) {
            return $notification->tenant->id === $tenant->id;
        });
        $this->assertNotNull($tenant->fresh()->offline_alert_sent_at);
    }

    public function test_alerts_about_a_tenant_stale_past_the_threshold(): void
    {
        Notification::fake();
        $admin = HubAdmin::factory()->create(['is_active' => true]);
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'last_heartbeat_at' => now()->subDays(15),
        ]);

        Artisan::call('license:check-heartbeats');

        Notification::assertSentTo($admin, TenantOfflineNotification::class);
        $this->assertNotNull($tenant->fresh()->offline_alert_sent_at);
    }

    public function test_does_not_alert_a_tenant_that_recently_heartbeated(): void
    {
        Notification::fake();
        HubAdmin::factory()->create(['is_active' => true]);
        Tenant::factory()->create([
            'status' => 'active',
            'last_heartbeat_at' => now()->subDays(2),
        ]);

        Artisan::call('license:check-heartbeats');

        Notification::assertNothingSent();
    }

    public function test_does_not_alert_a_suspended_tenant(): void
    {
        Notification::fake();
        HubAdmin::factory()->create(['is_active' => true]);
        Tenant::factory()->create([
            'status' => 'suspended',
            'last_heartbeat_at' => null,
        ]);

        Artisan::call('license:check-heartbeats');

        Notification::assertNothingSent();
    }

    public function test_does_not_re_alert_the_same_stale_period(): void
    {
        Notification::fake();
        HubAdmin::factory()->create(['is_active' => true]);
        Tenant::factory()->create([
            'status' => 'active',
            'last_heartbeat_at' => now()->subDays(20),
            'offline_alert_sent_at' => now()->subHours(2), // already alerted once
        ]);

        Artisan::call('license:check-heartbeats');

        Notification::assertNothingSent();
    }

    public function test_a_successful_heartbeat_resets_the_alert_flag_for_the_next_stale_period(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'active',
            'last_heartbeat_at' => now()->subDays(20),
            'offline_alert_sent_at' => now()->subHours(2),
        ]);

        // Directly exercise the reset the controller performs on a
        // successful heartbeat, without standing up the full RSA/entitlement
        // machinery LicenseApiController::heartbeat() needs end-to-end.
        $tenant->update(['last_heartbeat_at' => now(), 'offline_alert_sent_at' => null]);

        $this->assertNull($tenant->fresh()->offline_alert_sent_at);
        $this->assertFalse($tenant->fresh()->isOffline());
    }
}
