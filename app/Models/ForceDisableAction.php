<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForceDisableAction extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'entitlement_id', 'trigger_type',
        'previous_limit', 'new_limit',
        'users_to_disable', 'users_actually_disabled',
        'admin_last_protected', 'status',
        'warning_sent_at', 'executed_at', 'metadata',
        'affected_user_ids', 'last_admin_protected_ids',
        'warning_event_id', 'executed_event_id',
    ];

    protected $casts = [
        'previous_limit' => 'integer',
        'new_limit' => 'integer',
        'users_to_disable' => 'integer',
        'users_actually_disabled' => 'integer',
        'admin_last_protected' => 'boolean',
        'warning_sent_at' => 'datetime',
        'executed_at' => 'datetime',
        'metadata' => 'array',
        'affected_user_ids' => 'array',
        'last_admin_protected_ids' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LicenseEntitlement::class);
    }
}
