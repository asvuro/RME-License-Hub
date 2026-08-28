<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForceDisableAction extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'entitlement_id', 'trigger_type',
        'previous_limit', 'new_limit',
        'users_to_disable', 'users_actually_disabled',
        'admin_last_protected', 'status',
        'warning_sent_at', 'executed_at', 'metadata',
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
