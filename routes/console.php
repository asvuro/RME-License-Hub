<?php

use App\Jobs\RetryFailedWebhooks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Process scheduled license tasks: force-disable, addon expiry
Schedule::command('license:process-scheduled')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Retry failed webhook deliveries
Schedule::job(new RetryFailedWebhooks)
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Monitoring: alert hub admins about tenants that stopped phoning home.
Schedule::command('license:check-heartbeats')
    ->hourly()
    ->withoutOverlapping();

// Backup: daily database dump (see DEPLOYMENT.md for the RSA key backup
// procedure — deliberately NOT automated into this rotating dump directory).
Schedule::command('hub:backup-database')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();
