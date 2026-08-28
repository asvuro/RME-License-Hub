<?php

namespace App\Console\Commands;

use App\Models\LicenseEntitlement;
use App\Services\EntitlementCalculator;
use App\Services\ForceDisableManager;
use App\Services\ModuleSyncService;
use Illuminate\Console\Command;

class ProcessScheduledTasks extends Command
{
    protected $signature = 'license:process-scheduled';

    protected $description = 'Process pending force-disable actions, expire add-ons, and push module syncs';

    public function handle(
        ForceDisableManager $forceDisableManager,
        ModuleSyncService $moduleSyncService,
    ): int {
        $this->info('Processing scheduled license tasks...');

        // 1. Process pending force-disable actions
        $executed = $forceDisableManager->processPendingActions();
        $this->info("Force-disable actions executed: {$executed}");

        // 2. Expire due add-ons for all active entitlements
        $entitlements = LicenseEntitlement::where('status', 'active')->get();
        $expiredCount = 0;
        $calculator = app(EntitlementCalculator::class);

        foreach ($entitlements as $entitlement) {
            $expired = $calculator->expireDueAddons($entitlement);
            if ($expired > 0) {
                $expiredCount += $expired;
                $this->line("  Expired {$expired} add-ons for tenant {$entitlement->tenant->client_code}");
            }
        }
        $this->info("Total add-ons expired: {$expiredCount}");

        // 3. Push module sync to all active tenants (optional, can be frequent)
        // Comment out if too resource-intensive for daily runs
        // $this->info('Pushing module sync to all tenants...');
        // $synced = $moduleSyncService->pushToAllTenants();
        // $this->info("Module sync pushed to {$synced} tenants.");

        return 0;
    }
}
