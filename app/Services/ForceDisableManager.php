<?php

namespace App\Services;

use App\Models\ForceDisableAction;
use App\Models\LicenseEntitlement;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hub-authoritative force-disable orchestration.
 *
 * Policy (fixed):
 *  1. When an add-on expires and recalculation reduces effective_max_users, if
 *     the client currently has MORE active users than the new limit, the
 *     NEWEST-registered active users must be disabled first (no grandfathering).
 *  2. BEFORE executing, send a `force_disable.warning` webhook with the EXPLICIT
 *     list of user ids to disable (computed by ForceDisableTargetSelector). Never
 *     a silent cutoff.
 *  3. The hub NEVER orders the last remaining admin disabled. If that would
 *     happen, those admin ids are reported in `admins_protected` and withheld
 *     from the disable list.
 *
 * The hub computes the targets from the client's reported roster
 * (RosterService / tenant_users). The client only executes the order.
 */
class ForceDisableManager
{
    public function __construct(
        protected WebhookDispatcher $webhookDispatcher,
        protected EntitlementCalculator $calculator,
        protected ForceDisableTargetSelector $selector,
        protected RosterService $rosterService,
    ) {}

    /**
     * Called after recalculation shows the effective quota dropped.
     * Creates a pending action and immediately sends the warning (with targets).
     */
    public function checkAndTrigger(LicenseEntitlement $entitlement, int $previousMaxUsers): ?ForceDisableAction
    {
        $newMaxUsers = $entitlement->effective_max_users;

        if ($newMaxUsers >= $previousMaxUsers) {
            return null; // Quota increased or stayed same, no action needed.
        }

        $tenant = $entitlement->tenant;

        $roster = $this->rosterService->getRoster($tenant);
        $selection = $this->selector->select($newMaxUsers, $previousMaxUsers, $roster);

        // Nothing to disable (fits, or roster not yet reported).
        if (empty($selection['disable']) && empty($selection['admins_protected'])) {
            Log::info("ForceDisableManager: quota drop for {$tenant->client_code} but no targets to disable (active users fit or roster empty).");

            return null;
        }

        $action = ForceDisableAction::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'entitlement_id' => $entitlement->id,
            'trigger_type' => 'user_quota_exceeded',
            'previous_limit' => $previousMaxUsers,
            'new_limit' => $newMaxUsers,
            'users_to_disable' => count($selection['disable']),
            'users_actually_disabled' => 0,
            'admin_last_protected' => ! empty($selection['admins_protected']),
            'affected_user_ids' => $selection['disable'],
            'last_admin_protected_ids' => $selection['admins_protected'],
            'status' => 'pending',
            'metadata' => [
                'previous_max_users' => $previousMaxUsers,
                'new_max_users' => $newMaxUsers,
                'tier' => $entitlement->tier->slug,
                'over_limit_by' => $selection['over_limit_by'],
            ],
        ]);

        // BEFORE executing: send warning WITH explicit targets.
        $warningEventId = 'evt-'.Str::uuid()->toString();
        $this->webhookDispatcher->dispatchForceDisableWarning($tenant, [
            'event_id' => $warningEventId,
            'action_id' => $action->id,
            'trigger' => 'user_quota_exceeded',
            'previous_limit' => $previousMaxUsers,
            'new_limit' => $newMaxUsers,
            'disable_user_ids' => $selection['disable'],
            'admins_protected' => $selection['admins_protected'],
            'admin_protection' => true,
            'grace_period_hours' => config('license.force_disable_grace_hours', 72),
            'instructions' => 'Disable the listed newest-registered active users to meet the new quota. NEVER disable the protected admin ids; always keep at least one active admin.',
            'rules' => [
                'disable_order' => 'newest_first',
                'admin_protection' => 'always_protect_last_admin',
            ],
        ]);

        $action->update([
            'status' => 'warning_sent',
            'warning_sent_at' => now(),
            'warning_event_id' => $warningEventId,
        ]);

        Log::info("ForceDisableManager: warning sent for {$tenant->client_code}. Targets=".count($selection['disable']).' protected_admins='.count($selection['admins_protected']));

        return $action;
    }

    /**
     * Execute the force-disable after the grace period has elapsed. Sends the
     * final execution webhook with the same explicit targets.
     */
    public function execute(ForceDisableAction $action): bool
    {
        if ($action->status !== 'warning_sent') {
            return false;
        }

        $graceHours = config('license.force_disable_grace_hours', 72);
        if ($action->warning_sent_at && abs(now()->diffInHours($action->warning_sent_at)) < $graceHours) {
            return false;
        }

        $tenant = $action->tenant;

        // Re-verify against the latest roster: clients may have deleted users
        // or added admins. Re-select to avoid a stale/over-broad disable order.
        $roster = $this->rosterService->getRoster($tenant);
        $selection = $this->selector->select(
            $action->new_limit,
            $action->previous_limit,
            $roster
        );

        $disableIds = $selection['disable'];
        $protectedIds = $selection['admins_protected'];

        $executedEventId = 'evt-'.Str::uuid()->toString();
        $this->webhookDispatcher->dispatchForceDisableExecuted($tenant, [
            'event_id' => $executedEventId,
            'action_id' => $action->id,
            'trigger' => $action->trigger_type,
            'previous_limit' => $action->previous_limit,
            'new_limit' => $action->new_limit,
            'disable_user_ids' => $disableIds,
            'admins_protected' => $protectedIds,
            'admin_protection' => true,
            'instructions' => 'Execute force-disable now. Disable the listed newest-registered active users to meet the new quota. NEVER disable a protected admin id; always keep at least one active admin remaining.',
            'rules' => [
                'disable_order' => 'newest_first',
                'admin_protection' => 'always_protect_last_admin',
                'check_before_disable' => 'Verify at least one active admin remains after disabling.',
            ],
        ]);

        // Mark the ordered users as deactivated in the hub's cached roster.
        $this->rosterService->markDeactivated($tenant, $disableIds);

        $action->update([
            'status' => 'executed',
            'executed_at' => now(),
            'executed_event_id' => $executedEventId,
            'affected_user_ids' => $disableIds,
            'last_admin_protected_ids' => $protectedIds,
            'users_actually_disabled' => count($disableIds),
            'metadata' => array_merge($action->metadata ?? [], [
                'executed_disable_count' => count($disableIds),
                'executed_protected_count' => count($protectedIds),
            ]),
        ]);

        Log::info("ForceDisableManager: executed for {$tenant->client_code}. Disabled=".count($disableIds));

        return true;
    }

    /**
     * Process all warning_sent actions whose grace period has elapsed.
     */
    public function processPendingActions(): int
    {
        $grace = config('license.force_disable_grace_hours', 72);

        $actions = ForceDisableAction::where('status', 'warning_sent')
            ->where('warning_sent_at', '<', now()->subHours($grace))
            ->get();

        $executed = 0;
        foreach ($actions as $action) {
            if ($this->execute($action)) {
                $executed++;
            }
        }

        return $executed;
    }

    /**
     * Cancel a pending/warned action (e.g., quota restored by a new add-on).
     */
    public function cancel(ForceDisableAction $action, string $reason = ''): bool
    {
        if (in_array($action->status, ['executed', 'cancelled'], true)) {
            return false;
        }

        $action->update([
            'status' => 'cancelled',
            'metadata' => array_merge($action->metadata ?? [], ['cancel_reason' => $reason]),
        ]);

        Log::info("ForceDisableManager: cancelled action {$action->id} for {$action->tenant->client_code}. Reason: {$reason}");

        return true;
    }
}
