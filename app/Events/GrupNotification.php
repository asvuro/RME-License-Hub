<?php

namespace App\Events;

use App\Enums\GroupRealtimeEventType;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Broadcast source of truth for the RME-Backend `Grup` realtime contract.
 *
 * - Channel:  `private-grup.instance.{instance_id}` (one per branch installation)
 * - Event:    `grup.notification` (Pusher/Reverb wire name via broadcastAs())
 * - Payload:  non-PHI signal only; clients refetch data through the REST relay.
 *
 * The channel prefix MUST stay in lockstep with the Grup module's
 * `GRUP_REVERB_CHANNEL_PREFIX` default (`private-grup.instance.`).
 *
 * See docs/reconciliation-with-grup-module.md (Decisions 1 & 2).
 */
class GrupNotification implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /** Shared channel prefix with the RME-Backend Grup module. */
    public const CHANNEL_PREFIX = 'grup.instance.';

    /** Contract version emitted in every payload (`version` field). */
    public const CONTRACT_VERSION = 1;

    public function __construct(
        /** Hub-issued instance id (license_keys.instance_id). */
        public readonly string $instanceId,
        public readonly GroupRealtimeEventType $type,
        /** UUID; generated when null so every event is de-duplicable. */
        public readonly ?string $eventId = null,
        public readonly ?string $resourceId = null,
        public readonly ?string $sourceBranchId = null,
        /** ISO-8601; defaults to now() when null. */
        public readonly ?string $occurredAt = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL_PREFIX.$this->instanceId)];
    }

    /**
     * Wire event name the client listens for (Pusher/Reverb `event`).
     */
    public function broadcastAs(): string
    {
        return 'grup.notification';
    }

    /**
     * Non-PHI payload. Field names/types are fixed by the reconciled contract
     * (must pass RealtimeEventProcessor validation on the client).
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId ?? Str::uuid()->toString(),
            'type' => $this->type->value,
            'resource_id' => $this->resourceId,
            'source_branch_id' => $this->sourceBranchId,
            'version' => self::CONTRACT_VERSION,
            'occurred_at' => $this->occurredAt ?? now()->toIso8601String(),
        ];
    }
}
