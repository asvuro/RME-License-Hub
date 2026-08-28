<?php

namespace App\Services;

use App\Models\LicenseEntitlement;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Handles synchronization of modules_statuses.json to client installations.
 *
 * The client (RME-Backend) uses nwidart/laravel-modules which reads
 * modules_statuses.json to determine which modules are enabled.
 * Format: { "ModuleName": true, "OtherModule": false }
 *
 * Sync strategy: PUSH from hub via webhook.
 * - Hub sends a 'modules.sync' webhook event with the module list.
 * - Client receives the webhook and writes modules_statuses.json.
 * - Client also receives license tokens that include allowed_modules,
 *   which the CheckModuleAccessMiddleware enforces at runtime.
 *
 * The webhook is authenticated with HMAC-SHA256 (see WebhookDispatcher).
 */
class ModuleSyncService
{
    public function __construct(
        protected WebhookDispatcher $webhookDispatcher,
        protected EntitlementCalculator $calculator
    ) {}

    /**
     * Build the modules_statuses.json content for a tenant.
     * All modules in the system are listed; only entitled ones are true.
     */
    public function buildModuleStatuses(LicenseEntitlement $entitlement): array
    {
        $allModules = \App\Models\ModuleModel::where('is_active', true)->pluck('slug');
        $allowedModules = $entitlement->effective_modules ?? [];

        $statuses = [];
        foreach ($allModules as $slug) {
            $statuses[$slug] = in_array($slug, $allowedModules) || in_array('*', $allowedModules);
        }

        return $statuses;
    }

    /**
     * Push the current module statuses to a tenant via webhook.
     */
    public function pushToTenant(Tenant $tenant): void
    {
        $entitlement = $tenant->activeEntitlement;
        if (!$entitlement) {
            Log::warning("ModuleSyncService: No active entitlement for tenant {$tenant->client_code}");
            return;
        }

        $this->calculator->recalculate($entitlement);
        $statuses = $this->buildModuleStatuses($entitlement);

        $this->webhookDispatcher->dispatchModulesSync($tenant, $statuses);

        Log::info("ModuleSyncService: Pushed ".count($statuses)." modules to tenant {$tenant->client_code}");
    }

    /**
     * Push module statuses to all active tenants.
     * Called when a tier definition changes or modules are added/removed globally.
     */
    public function pushToAllTenants(): int
    {
        $tenants = Tenant::where('status', 'active')->get();
        $count = 0;

        foreach ($tenants as $tenant) {
            try {
                $this->pushToTenant($tenant);
                $count++;
            } catch (\Throwable $e) {
                Log::error("ModuleSyncService: Failed to push to tenant {$tenant->client_code}: {$e->getMessage()}");
            }
        }

        return $count;
    }

    /**
     * Get the current allowed modules for a tenant (for token payload).
     */
    public function getAllowedModules(LicenseEntitlement $entitlement): array
    {
        return $entitlement->effective_modules ?? [];
    }
}
