<?php

namespace App\Broadcasting;

use App\Models\Group;
use App\Models\Tenant;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Authorizes the group presence channel (online status / roster).
 *
 * Secured wire name:  presence-group.{group_id}
 * (the README's informal "presence.group.{group_id}" notation is materialized
 *  with the required "presence-" prefix so the broadcaster enforces auth and
 *  performs presence registration).
 *
 * A connection is authorized only when the authenticated tenant instance belongs
 * to the referenced group. The returned identity is strictly the public branch
 * identity — no license tokens, webhook secrets, or cross-member data.
 */
class GroupPresenceChannel
{
    /**
     * Authenticate the incoming request for the channel and return presence identity.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|Tenant  $user
     * @param  string  $groupId
     * @return array|bool
     */
    public function join(Authenticatable $user, string $groupId): array|bool
    {
        if (! $user instanceof Tenant) {
            return false;
        }

        if (! in_array($user->status, ['active', 'suspended'], true)) {
            return false;
        }

        if ($user->group_id !== $groupId) {
            return false;
        }

        $group = Group::find($groupId);

        return [
            'id' => $user->id,
            'group_id' => $groupId,
            'group_name' => $group?->name,
            'branch_instance_id' => $user->activeLicenseKey?->instance_id,
            'branch_code' => $user->client_code,
            'branch_name' => $user->client_name,
            'authenticated_as' => 'branch',
        ];
    }
}
