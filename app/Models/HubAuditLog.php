<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubAuditLog extends Model
{
    protected $fillable = [
        'tenant_id', 'event_type', 'details',
        'ip_address', 'user_agent', 'actor_id', 'actor_type',
    ];

    protected $casts = [
        'details' => 'array',
        'actor_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Convenience recorder used by the admin dashboard.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(
        string $eventType,
        ?Model $actor = null,
        array $details = [],
        ?string $tenantId = null
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'details' => $details,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_type' => $actor ? get_class($actor) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
