<?php

namespace App\Services;

use App\Events\GroupForceDisableExecuted;
use App\Events\GroupForceDisableWarning;
use App\Events\GroupLicenseUpdated;
use App\Events\GroupMemberJoined;
use App\Events\GroupMemberLeft;
use App\Events\GroupQuotaChanged;
use App\Models\Tenant;
use Illuminate\Support\Facades\Broadcast;

/**
 * Single source of truth for broadcasting the 6 group realtime events defined in
 * the README's "Reverb Channels (for Group Realtime)" contract.
 *
 * Why this exists:
 * - Centralizes channel naming so the hub and every client agree on the exact
 *   wire names (group.{group_id}.branch.{branch_instance_id} + presence-group.{group_id}).
 * - Provides safe, typed methods the Hub Admin / License / Force-disable flows
 *   (built by the core backend agent) call instead of hand-building events.
 * - Guarantees tenant-scoped delivery: a branch only ever receives its own
 *   private events. Cross-group/cross-branch leakage is structurally impossible
 *   because the events themselves target a single branch's private channel.
 *
 * This intentionally REPLACES the earlier divergent relay naming
 * (private-tenant.{id} / private-group.{id}); align GroupRelayController /
 * GroupRelayService to use these helpers.
 */
class GroupRealtimeService
{
    /**
     * Notify a branch that it joined a group.
     */
    public function memberJoined(string $groupId, Tenant $branch): void
    {
        Broadcast::event(new GroupMemberJoined(
            groupId: $groupId,
            branchInstanceId: $this->instanceId($branch),
            branchName: $branch->client_name,
            branchCode: $branch->client_code,
        ));
    }

    /**
     * Notify a branch that it left a group.
     */
    public function memberLeft(string $groupId, Tenant $branch): void
    {
        Broadcast::event(new GroupMemberLeft(
            groupId: $groupId,
            branchInstanceId: $this->instanceId($branch),
            branchName: $branch->client_name,
            branchCode: $branch->client_code,
        ));
    }

    /**
     * Notify a branch that its license changed (extended, modules changed,
     * suspended, revoked, etc.). Private to the branch.
     */
    public function licenseUpdated(
        string $groupId,
        Tenant $branch,
        string $licenseKey,
        string $status,
        ?string $validUntil = null,
        array $changedModules = [],
        ?string $reason = null,
    ): void {
        Broadcast::event(new GroupLicenseUpdated(
            groupId: $groupId,
            branchInstanceId: $this->instanceId($branch),
            licenseKey: $licenseKey,
            status: $status,
            validUntil: $validUntil,
            changedModules: $changedModules,
            reason: $reason,
        ));
    }

    /**
     * Notify a branch that its quota changed (max users/branches/modules).
     * Private to the branch.
     */
    public function quotaChanged(
        string $groupId,
        Tenant $branch,
        int $maxUsers,
        int $maxBranches,
        array $modules = [],
        ?string $reason = null,
    ): void {
        Broadcast::event(new GroupQuotaChanged(
            groupId: $groupId,
            branchInstanceId: $this->instanceId($branch),
            maxUsers: $maxUsers,
            maxBranches: $maxBranches,
            modules: $modules,
            reason: $reason,
        ));
    }

    /**
     * Warn a branch that force-disable is imminent (grace period started).
     * Private to the branch.
     */
    public function forceDisableWarning(
        string $groupId,
        Tenant $branch,
        string $triggerType,
        int $graceSeconds,
        ?string $reason = null,
    ): void {
        Broadcast::event(new GroupForceDisableWarning(
            groupId: $groupId,
            branchInstanceId: $this->instanceId($branch),
            triggerType: $triggerType,
            graceSeconds: $graceSeconds,
            reason: $reason,
        ));
    }

    /**
     * Notify a branch that force-disable was executed on it.
     * Private to the branch.
     */
    public function forceDisableExecuted(
        string $groupId,
        Tenant $branch,
        string $triggerType,
        int $usersDisabled,
        bool $adminProtected = false,
        ?string $reason = null,
    ): void {
        Broadcast::event(new GroupForceDisableExecuted(
            groupId: $groupId,
            branchInstanceId: $this->instanceId($branch),
            triggerType: $triggerType,
            usersDisabled: $usersDisabled,
            adminProtected: $adminProtected,
            reason: $reason,
        ));
    }

    /**
     * Resolve the branch's current instance id from its active license key.
     */
    protected function instanceId(Tenant $branch): string
    {
        return (string) ($branch->activeLicenseKey?->instance_id ?? $branch->id);
    }
}
