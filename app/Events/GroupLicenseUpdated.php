<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a group member's license changes (extended, modules changed,
 * add-ons added/removed, suspended, revoked, etc.).
 *
 * Broadcast target: the affected branch's own private channel only. Each branch
 * receives only its own license changes — never another member's — which keeps
 * per-tenant license data (tokens, module entitlements) strictly partitioned.
 *
 * Security: the payload carries only non-secret, display-safe license metadata.
 * The signed token, webhook secret, and raw entitlement record are intentionally
 * excluded; the client refreshes its own state via the REST license API.
 */
class GroupLicenseUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $groupId,
        public string $branchInstanceId,
        public string $licenseKey,        // opaque key string, not the cryptographic token
        public string $status,            // active|suspended|expired|revoked
        public ?string $validUntil,       // ISO-8601 or null
        public array $changedModules = [], // module slugs that changed (display only)
        public ?string $reason = null,     // human-readable reason, no secrets
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group.{$this->groupId}.branch.{$this->branchInstanceId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'GroupLicenseUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'group_id' => $this->groupId,
            'branch_instance_id' => $this->branchInstanceId,
            'license_key' => $this->licenseKey,
            'status' => $this->status,
            'valid_until' => $this->validUntil,
            'changed_modules' => $this->changedModules,
            'reason' => $this->reason,
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
