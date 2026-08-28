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
}
