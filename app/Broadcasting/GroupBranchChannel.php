<?php

namespace App\Broadcasting;

use App\Models\Group;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes the group branch private channel.
 *
 * Secured wire name:  private-group.{group_id}.branch.{branch_instance_id}
 * (the README's informal "group.{group_id}.branch.{branch_instance_id}" notation
 *  is materialized with the required "private-" prefix so the broadcaster actually
 *  enforces authentication).
 *
 * A connection is authorized only when the authenticated tenant instance BOTH:
 *   - belongs to the referenced group, AND
 *   - matches the referenced branch_instance_id (its own license's instance_id).
 *
 * This guarantees a branch can ONLY subscribe to its own per-branch channel and
 * can never read another member's private events (license changes, quota,
 * force-disable). Cross-group access is impossible.
 */
class GroupBranchChannel
{
    /**
     * Authenticate the incoming request for the channel.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|Tenant  $user   Resolved via the "tenant" guard.
     * @param  string  $groupId            Group UUID from the channel name.
     * @param  string  $branchInstanceId   Branch instance ID from the channel name.
     * @return array|bool  The presence/member info when authorized, false otherwise.
     */
    public function join(Authenticatable $user, string $groupId, string $branchInstanceId): array|bool
    {
        // The connecting principal MUST be a Tenant resolved by the tenant guard.
        if (! $user instanceof Tenant) {
            return false;
        }

        // Must be an active, non-terminated tenant.
        if (! in_array($user->status, ['active', 'suspended'], true)) {
            return false;
        }

        // Tenant must belong to the referenced group.
        if ($user->group_id !== $groupId) {
            return false;
        }

        // Must be the tenant's OWN branch channel (match against its license instance_id).
        $ownsInstance = $user->licenseKeys()
            ->where('instance_id', $branchInstanceId)
            ->exists();

        if (! $ownsInstance) {
            return false;
        }

        $group = Group::find($groupId);

        return [
            'group_id' => $groupId,
            'group_name' => $group?->name,
            'branch_instance_id' => $branchInstanceId,
            'branch_code' => $user->client_code,
            'branch_name' => $user->client_name,
            'authenticated_as' => 'branch',
        ];
    }
}
