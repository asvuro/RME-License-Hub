<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles the client-reported roster (hub-side cache) used for authoritative
 * force-disable targeting. The client POSTs its user list; the hub never trusts
 * the client to pick disable targets — it only trusts the roster as data.
 */
class RosterService
{
    /**
     * Replace the tenant's cached roster with the client-reported list.
     *
     * @param  array<int,array{user_id:string, is_admin:bool, registered_at:?string, is_active:bool}>  $users
     */
    public function replaceRoster(Tenant $tenant, array $users): void
    {
        DB::transaction(function () use ($tenant, $users) {
            TenantUser::where('tenant_id', $tenant->id)->delete();

            foreach ($users as $u) {
                TenantUser::create([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenant->id,
                    'external_user_id' => (string) ($u['user_id'] ?? $u['external_user_id'] ?? null),
                    'is_admin' => (bool) ($u['is_admin'] ?? false),
                    'registered_at' => isset($u['registered_at']) ? $u['registered_at'] : null,
                    'is_active' => (bool) ($u['is_active'] ?? true),
                ]);
            }
        });
    }

    /**
     * Mark reported users as deactivated by hub order (idempotent).
     *
     * @param  string[]  $externalUserIds
     */
    public function markDeactivated(Tenant $tenant, array $externalUserIds): void
    {
        if (empty($externalUserIds)) {
            return;
        }

        TenantUser::where('tenant_id', $tenant->id)
            ->whereIn('external_user_id', $externalUserIds)
            ->update(['is_active' => false, 'last_deactivated_at' => now()]);
    }

    /**
     * Full roster for target selection.
     */
    public function getRoster(Tenant $tenant)
    {
        return TenantUser::where('tenant_id', $tenant->id)->get();
    }
}
