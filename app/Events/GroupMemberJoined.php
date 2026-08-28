<?php

namespace App\Events;

use App\Models\Tenant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a branch (tenant) joins a group.
 *
 * Broadcast target: the private channel of the *target* branch instance so that
 * only that specific branch receives its own confirmation, plus the shared
 * presence channel of the group so other online members see the roster change.
 *
 * Security: the payload only contains the public identity of the joining branch.
 * No cross-tenant secrets (license tokens, webhook secrets, entitlement details)
 * are ever included.
 */
class GroupMemberJoined implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $groupId         UUID of the group the member joined.
     * @param  string  $branchInstanceId  Instance ID of the branch that joined.
     * @param  string  $branchName       Human-readable branch name (display only).
     * @param  string  $branchCode       Stable branch code (display only).
     */
    public function __construct(
        public string $groupId,
        public string $branchInstanceId,
        public string $branchName,
        public string $branchCode,
    ) {}

    /**
     * Broadcast to the joining branch's private channel and the group presence channel.
     *
     * Channel names follow the README contract (dotted) which the broadcaster
     * secures by prefixing with private-/presence- internally:
     *   - group.{group_id}.branch.{branch_instance_id}   (private)
     *   - group.{group_id}                              (presence, shared)
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group.{$this->groupId}.branch.{$this->branchInstanceId}"),
            new Channel("group.{$this->groupId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GroupMemberJoined';
    }

    /**
     * Only the public identity of the joining branch is exposed.
     */
    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'branch_instance_id' => $this->branchInstanceId,
            'branch_name' => $this->branchName,
            'branch_code' => $this->branchCode,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
