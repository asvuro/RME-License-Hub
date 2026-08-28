<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'group_id', 'client_code', 'client_name', 'legal_entity_name',
        'contact_email', 'contact_phone', 'address', 'status',
        'api_token_hash', 'webhook_secret_hash', 'last_heartbeat_at',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function licenseKeys(): HasMany
    {
        return $this->hasMany(LicenseKey::class);
    }

    public function activeLicenseKey(): HasOne
    {
        return $this->hasOne(LicenseKey::class)->where('status', 'active')->latest();
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(LicenseEntitlement::class);
    }

    public function activeEntitlement(): HasOne
    {
        return $this->hasOne(LicenseEntitlement::class)->where('status', 'active')->latest();
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(TenantHeartbeat::class);
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function forceDisableActions(): HasMany
    {
        return $this->hasMany(ForceDisableAction::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(HubAuditLog::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isInGroup(): bool
    {
        return $this->group_id !== null;
    }
}
