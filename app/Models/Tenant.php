<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'group_id', 'client_code', 'client_name', 'legal_entity_name',
        'contact_email', 'contact_phone', 'address', 'status',
        'api_token_hash', 'webhook_secret_hash', 'last_heartbeat_at',
        'instance_url', 'webhook_secret', 's2s_token', 'offline_alert_sent_at',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
        'offline_alert_sent_at' => 'datetime',
        // Encrypted at rest so the hub can recover the plaintext to sign pushes
        // to this client. Never expose these via API responses.
        'webhook_secret' => 'encrypted',
        's2s_token' => 'encrypted',
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

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
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

    /**
     * True when this tenant has never phoned home, or hasn't in longer than
     * `license.max_offline_days` — the threshold CheckTenantHeartbeats alerts
     * on. Kept as a single source of truth (with scopeOffline() below) so the
     * command and the dashboard widget can never silently disagree on the
     * definition of "offline".
     */
    public function isOffline(): bool
    {
        $maxDays = (int) config('license.max_offline_days', 14);

        return $this->last_heartbeat_at === null
            || $this->last_heartbeat_at->lt(now()->subDays($maxDays));
    }

    /**
     * Query-builder form of isOffline(), scoped to active tenants only
     * (suspended/terminated tenants are expected to be offline).
     */
    public function scopeOffline($query)
    {
        $threshold = now()->subDays((int) config('license.max_offline_days', 14));

        return $query->where('status', 'active')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('last_heartbeat_at')->orWhere('last_heartbeat_at', '<', $threshold);
            });
    }
}
