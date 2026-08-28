<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a branch (tenant) leaves (or is removed from) a group.
 *
 * Broadcast target: the leaving branch's private channel (so it can tear down
 * its group subscription) and the group's shared presence channel (so remaining
 * members see the roster shrink).
 *
 * Security: only the public identity of the leaving branch is exposed.
 */
class GroupMemberLeft implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $groupId,
        public string $branchInstanceId,
        public string $branchName,
        public string $branchCode,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group.{$this->groupId}.branch.{$this->branchInstanceId}"),
            new Channel("group.{$this->groupId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GroupMemberLeft';
    }

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
