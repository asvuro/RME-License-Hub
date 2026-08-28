<?php

use App\Broadcasting\GroupBranchChannel;
use App\Broadcasting\GroupPresenceChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channel Routes
|--------------------------------------------------------------------------
|
| Here you may register all of the channels that your application is able to
| broadcast to. The given channel authorization callbacks are used to check
| if a tenant instance is allowed to listen on a given channel.
|
| Channel naming (README contract):
|   - Group branch (private):   group.{group_id}.branch.{branch_instance_id}
|   - Group presence:           presence.group.{group_id}
|
| IMPORTANT (security): the Pusher/Reverb broadcaster only enforces
| authentication on channels whose wire name starts with "private-" or
| "presence-". Laravel materializes the dotted route name above into the
| properly-prefixed secured wire name ("private-group.{id}.branch.{iid}" and
| "presence-group.{id}") automatically. We therefore register the channel
| ROUTE names below WITHOUT the prefix and bind them to the "tenant" guard so
| that:
|   - a tenant instance authenticates with its service-to-service token, and
|   - the authorizer verifies the instance belongs to the group AND owns the
|     referenced branch_instance_id (private) — never another member's channel.
*/

// Guard used to resolve the authenticated principal for channel auth.
$tenantGuard = ['guards' => ['tenant']];

// Private per-branch channel: only the branch itself may subscribe.
Broadcast::channel('group.{groupId}.branch.{branchInstanceId}', GroupBranchChannel::class, $tenantGuard);

// Presence (online status) channel for a group: only members of the group may join.
Broadcast::channel('group.{groupId}', GroupPresenceChannel::class, $tenantGuard);
