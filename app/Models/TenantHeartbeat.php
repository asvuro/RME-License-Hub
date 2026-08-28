<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantHeartbeat extends Model
{
    protected $fillable = [
        'tenant_id', 'instance_id', 'license_key', 'hardware_id',
        'app_version', 'php_version', 'hostname', 'ip_address', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
