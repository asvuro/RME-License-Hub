<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Broadcast::routes();

        // Channel authorization for private tenant channels
        // Tenants authenticate to Reverb using their service-to-service token
        // The token is passed in the auth request and verified against the DB
        Broadcast::channel('tenant.{tenantId}', function ($user, string $tenantId) {
            // This is for hub admin auth via Sanctum
            // Tenant instances authenticate via the custom auth endpoint
            return true;
        });
    }
}
