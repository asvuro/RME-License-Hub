<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a force-disable WARNING is issued for a group member (grace period
 * before enforcement). The client should surface the warning to the branch admin
 * and, where applicable, start protecting the most-recently-registered users.
 *
 * Broadcast target: the warned branch's own private channel only. Force-disable
 * actions are strictly per-tenant; no other group member must learn about them.
 *
 * Security: contains only the warning metadata and a deadline. No user PII, no
 * roster, no secrets.
 */
class GroupForceDisableWarning implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $groupId,
        public string $branchInstanceId,
        public string $triggerType,        // e.g. "quota_exceeded", "license_expired"
        public int $graceSeconds,           // time until enforcement
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
        return 'GroupForceDisableWarning';
    }

    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'branch_instance_id' => $this->branchInstanceId,
            'trigger_type' => $this->triggerType,
            'grace_seconds' => $this->graceSeconds,
            'reason' => $this->reason,
            'warned_at' => now()->toIso8601String(),
            'enforces_at' => now()->addSeconds($this->graceSeconds)->toIso8601String(),
        ];
    }
}
