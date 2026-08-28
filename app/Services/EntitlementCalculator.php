<?php

namespace App\Services;

use App\Models\LicenseAddon;
use App\Models\LicenseEntitlement;
use App\Models\Tier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Calculates effective entitlements from tier base + active add-ons.
 *
 * Model: 5 dimensions
 *   1. Tier (bundle of modules)
 *   2. Included modules from tier
 *   3. Add-ons (generic line items: module, user_quota, branch_quota, time_extension)
 *   4. User quota (base from tier + add-on user_quota)
 *   5. Duration (base from tier + add-on time_extension)
 *
 * Effective entitlement = base tier + sum of all active add-ons.
 */
class EntitlementCalculator
{
    /**
     * Recalculate effective entitlement for a LicenseEntitlement.
     * Updates effective_max_users, effective_max_branches, effective_modules,
     * and valid_until (for time_extension add-ons).
     */
    public function recalculate(LicenseEntitlement $entitlement): LicenseEntitlement
    {
        $tier = $entitlement->tier;

        // Base values from tier
        $effectiveMaxUsers = $entitlement->base_max_users;
        $effectiveMaxBranches = $entitlement->base_max_branches;
        $effectiveModules = is_array($tier->included_modules) ? $tier->included_modules : [];

        // Base valid_until is already set on the entitlement when it was created.
        // Time extensions ADD days to the original valid_until.
        $baseValidUntil = $entitlement->valid_until ?? now()->addDays($tier->default_duration_days);

        // Apply active add-ons.
        // NOTE: use the query builder form (->activeAddons()) rather than the
        // magic property (->activeAddons) to avoid the cached relation snapshot
        // — otherwise add-ons created in the same request (e.g. via addAddon)
        // are invisible to the recalculation.
        foreach ($entitlement->activeAddons()->get() as $addon) {
            if (!$addon->isCurrentlyActive()) {
                continue;
            }

            switch ($addon->addon_type) {
                case 'module':
                    if ($addon->target_module_slug && !in_array($addon->target_module_slug, $effectiveModules)) {
                        $effectiveModules[] = $addon->target_module_slug;
                    }
                    break;

                case 'user_quota':
                    $effectiveMaxUsers += $addon->quantity;
                    break;

                case 'branch_quota':
                    $effectiveMaxBranches += $addon->quantity;
                    break;

                case 'time_extension':
                    $baseValidUntil = Carbon::parse($baseValidUntil)->addDays($addon->quantity);
                    break;
            }
        }

        $entitlement->effective_max_users = $effectiveMaxUsers;
        $entitlement->effective_max_branches = $effectiveMaxBranches;
        $entitlement->effective_modules = array_values(array_unique($effectiveModules));
        $entitlement->valid_until = $baseValidUntil;
        $entitlement->last_recalculated_at = now();
        $entitlement->save();

        return $entitlement->refresh();
    }

    /**
     * Create a new entitlement from a tier for a license key.
     */
    public function createEntitlement(
        string $licenseKeyId,
        string $tenantId,
        int $tierId,
        ?string $validFrom = null,
        ?string $validUntil = null,
    ): LicenseEntitlement {
        $tier = Tier::findOrFail($tierId);

        $validFrom = $validFrom ? Carbon::parse($validFrom) : now();
        $validUntil = $validUntil ? Carbon::parse($validUntil) : $validFrom->copy()->addDays($tier->default_duration_days);

        $entitlement = LicenseEntitlement::create([
            'id' => Str::uuid()->toString(),
            'license_key_id' => $licenseKeyId,
            'tenant_id' => $tenantId,
            'tier_id' => $tierId,
            'status' => 'active',
            'base_max_users' => $tier->base_max_users,
            'base_max_branches' => 1,
            'effective_max_users' => $tier->base_max_users,
            'effective_max_branches' => 1,
            'effective_modules' => $tier->included_modules ?? [],
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'last_recalculated_at' => now(),
        ]);

        return $this->recalculate($entitlement);
    }

    /**
     * Add an add-on to an entitlement and recalculate.
     */
    public function addAddon(
        LicenseEntitlement $entitlement,
        string $addonType,
        int $quantity = 1,
        ?string $targetModuleSlug = null,
        ?string $label = null,
        ?string $effectiveFrom = null,
        ?string $effectiveUntil = null,
    ): LicenseAddon {
        $addon = LicenseAddon::create([
            'id' => Str::uuid()->toString(),
            'entitlement_id' => $entitlement->id,
            'addon_type' => $addonType,
            'target_module_slug' => $targetModuleSlug,
            'quantity' => $quantity,
            'label' => $label ?? $this->generateAddonLabel($addonType, $quantity, $targetModuleSlug),
            'effective_from' => $effectiveFrom,
            'effective_until' => $effectiveUntil,
            'status' => 'active',
        ]);

        $this->recalculate($entitlement);

        return $addon;
    }

    private function generateAddonLabel(string $type, int $qty, ?string $module): string
    {
        return match ($type) {
            'module' => "Modul: {$module}",
            'user_quota' => "{$qty} User Tambahan",
            'branch_quota' => "{$qty} Cabang Tambahan",
            'time_extension' => "Perpanjangan {$qty} Hari",
            default => "Add-on: {$type}",
        };
    }

    /**
     * Expire add-ons that have passed their effective_until date.
     * Returns count of expired add-ons.
     */
    public function expireDueAddons(LicenseEntitlement $entitlement): int
    {
        $expiredCount = 0;

        foreach ($entitlement->addons()->get() as $addon) {
            if ($addon->status === 'active' && $addon->isExpired()) {
                $addon->update(['status' => 'expired']);
                $expiredCount++;
            }
        }

        if ($expiredCount > 0) {
            $this->recalculate($entitlement);
        }

        return $expiredCount;
    }
}
