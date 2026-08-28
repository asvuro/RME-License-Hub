<?php

namespace App\Services;

use App\Models\TenantUser;
use Illuminate\Support\Collection;

/**
 * Hub-authoritative target selection for force-disable.
 *
 * Rules (fixed business policy):
 *  1. Disable the NEWEST-registered ACTIVE non-admin users first (not
 *     grandfathered) until the active-user count fits the new quota.
 *  2. NEVER disable the last remaining admin. If disabling the newest users
 *     would leave the client with zero active admins, those admins are kept
 *     and the admin-last protection flag fires.
 *  3. The hub computes the explicit target list; the client only executes it.
 *
 * This class is intentionally pure (no HTTP, no DB writes beyond reads) so it
 * can be exhaustively unit-tested.
 */
class ForceDisableTargetSelector
{
    /**
     * @param  int  $newLimit  New effective max users after the add-on expired.
     * @param  int  $oldLimit  Effective max users before the change (for reporting).
     * @param  Collection<int,TenantUser>  $users  Reported roster.
     * @return array{disable: string[], admins_protected: string[], over_limit_by: int, fit: bool}
     */
    public function select(int $newLimit, int $oldLimit, $users): array
    {
        // Unlimited quota (enterprise). Nothing to disable.
        if ($newLimit <= 0) {
            return ['disable' => [], 'admins_protected' => [], 'over_limit_by' => 0, 'fit' => true];
        }

        // Active users only.
        $active = $users->filter(fn (TenantUser $u) => $u->is_active)->values();

        $totalActive = $active->count();
        $overBy = max(0, $totalActive - $newLimit);

        if ($overBy === 0) {
            return ['disable' => [], 'admins_protected' => [], 'over_limit_by' => 0, 'fit' => true];
        }

        // Newest first: sort by registered_at descending (nulls last), then by id
        // descending as a stable tiebreaker.
        $ordered = $active->sort(function (TenantUser $a, TenantUser $b) {
            $ra = $a->registered_at?->getTimestamp() ?? 0;
            $rb = $b->registered_at?->getTimestamp() ?? 0;
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }

            return strcmp((string) $b->id, (string) $a->id);
        })->values();

        // Admins that are active and would fall into the "to disable" tail if we
        // simply took the newest N. We must protect the LAST admin standing.
        $adminCount = $active->where('is_admin', true)->count();

        $disable = [];
        $adminsProtected = [];

        // The tail (newest `overBy` users) are candidates for disable.
        $tail = $ordered->slice(0, $overBy);
        $adminsSeenInTail = $tail->where('is_admin', true)->count();

        // Admins remaining if we disabled everything in the tail. We must NEVER
        // leave the client with zero active admins, so as we iterate the tail we
        // track how many admins would survive; an admin is protected only when
        // disabling it would drop the surviving admin count to zero.
        $survivingAdmins = $adminCount - $adminsSeenInTail;

        foreach ($tail as $u) {
            if ($u->is_admin) {
                // If disabling this admin would leave no admins standing, protect it.
                // With >=2 active admins this never triggers (rule is "last admin only").
                if ($survivingAdmins <= 0) {
                    $adminsProtected[] = $u->external_user_id;

                    continue;
                }
                // Otherwise it's safe to disable: it leaves at least one admin.
                $survivingAdmins--;
            }
            $disable[] = $u->external_user_id;
        }

        // Edge: if protecting admins left us still over the limit (because the
        // tail was mostly admins and we couldn't disable them), we cannot fit
        // the quota without breaking the admin-last rule — report the residual.
        $fit = (count($disable) + count($adminsProtected)) >= $overBy
            && (count($disable)) >= max(0, $overBy - count($adminsProtected));

        return [
            'disable' => $disable,
            'admins_protected' => $adminsProtected,
            'over_limit_by' => $overBy,
            'fit' => $fit,
        ];
    }
}
