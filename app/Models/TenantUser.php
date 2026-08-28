<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Client-reported roster snapshot used by the hub to compute force-disable
 * targets. The hub is authoritative: it orders WHICH newest-registered active
 * users to disable and NEVER lets the client pick targets (fail-closed policy).
 */
class TenantUser extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'external_user_id', 'is_admin', 'registered_at',
        'is_active', 'last_deactivated_at',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
        'is_active' => 'boolean',
        'registered_at' => 'datetime',
        'last_deactivated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
