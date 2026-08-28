<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the group-wide (or a member's) quota changes: max users, max
 * branches, or module set recalculated.
 *
 * Broadcast target: the affected branch's own private channel. Quota is a
 * per-tenant concern; only the branch whose quota changed is notified so it can
 * re-evaluate local enforcement. We deliberately do NOT fan this out to the whole
 * group, preventing one member from learning another member's capacity.
 *
 * Security: only the new numeric limits and a reason are exposed. No membership
 * list, no per-user data, no secrets.
 */
class GroupQuotaChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $groupId,
        public string $branchInstanceId,
        public int $maxUsers,
        public int $maxBranches,
        public array $modules = [],          // active module slugs (display only)
        public ?string $reason = null,        // e.g. "addon_purchased", "admin_override"
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group.{$this->groupId}.branch.{$this->branchInstanceId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GroupQuotaChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'branch_instance_id' => $this->branchInstanceId,
            'max_users' => $this->maxUsers,
            'max_branches' => $this->maxBranches,
            'modules' => $this->modules,
            'reason' => $this->reason,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
