<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LicenseKey extends Model
{
    use HasFactory;
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'license_key', 'status',
        'issued_at', 'valid_until', 'last_synced_at',
        'hardware_id', 'instance_id', 'hostname',
        'app_version', 'php_version',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'valid_until' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function entitlement(): HasOne
    {
        return $this->hasOne(LicenseEntitlement::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
