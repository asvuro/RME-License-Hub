<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a force-disable is EXECUTED on a group member (users disabled,
 * modules locked, license suspended) after the warning grace period.
 *
 * Broadcast target: the affected branch's own private channel only. This is the
 * most sensitive enforcement signal and must never leak to sibling members.
 *
 * Security: only the outcome counts and a high-level description are exposed.
 * No user-level detail, no roster, no secrets.
 */
class GroupForceDisableExecuted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $groupId,
        public string $branchInstanceId,
        public string $triggerType,
        public int $usersDisabled,
        public bool $adminProtected = false,  // true if an admin was protected from disabling
        public ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group.{$this->groupId}.branch.{$this->branchInstanceId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GroupForceDisableExecuted';
    }

    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'branch_instance_id' => $this->branchInstanceId,
            'trigger_type' => $this->triggerType,
            'users_disabled' => $this->usersDisabled,
            'admin_protected' => $this->adminProtected,
            'reason' => $this->reason,
            'executed_at' => now()->toIso8601String(),
        ];
    }
}
