<?php

namespace App\Console\Commands;

use App\Models\HubAdmin;
use App\Models\Tenant;
use App\Notifications\TenantOfflineNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Monitoring: alert hub admins when an ACTIVE tenant stops phoning home.
 *
 * Deliberately conservative — only alerts ONCE per stale period
 * (tenants.offline_alert_sent_at), reset to null the moment the tenant
 * heartbeats successfully again (see LicenseApiController::heartbeat()).
 * Suspended/terminated tenants are expected to be offline and are skipped.
 */
class CheckTenantHeartbeats extends Command
{
    protected $signature = 'license:check-heartbeats';

    protected $description = 'Alert hub admins about active tenants that have gone offline (no heartbeat past license.max_offline_days)';

    public function handle(): int
    {
        $offline = Tenant::offline()->whereNull('offline_alert_sent_at')->get();

        if ($offline->isEmpty()) {
            $this->info('No newly-offline tenants.');

            return 0;
        }

        $admins = HubAdmin::where('is_active', true)->get();

        if ($admins->isEmpty()) {
            $this->warn("Found {$offline->count()} offline tenant(s) but no active hub admin to notify.");

            return 0;
        }

        foreach ($offline as $tenant) {
            Notification::send($admins, new TenantOfflineNotification($tenant));
            $tenant->update(['offline_alert_sent_at' => now()]);
            $this->line("Alerted admins: {$tenant->client_code} (last seen: ".($tenant->last_heartbeat_at?->toDateTimeString() ?? 'never').')');
        }

        $this->info("Sent {$offline->count()} offline alert(s) to {$admins->count()} admin(s).");

        return 0;
    }
}
