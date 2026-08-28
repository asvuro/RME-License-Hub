<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LicenseEntitlement extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'license_key_id', 'tenant_id', 'tier_id', 'status',
        'base_max_users', 'base_max_branches',
        'effective_max_users', 'effective_max_branches',
        'effective_modules', 'valid_from', 'valid_until',
        'last_recalculated_at',
    ];

    protected $casts = [
        'base_max_users' => 'integer',
        'base_max_branches' => 'integer',
        'effective_max_users' => 'integer',
        'effective_max_branches' => 'integer',
        'effective_modules' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'last_recalculated_at' => 'datetime',
    ];

    public function licenseKey(): BelongsTo
    {
        return $this->belongsTo(LicenseKey::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(Tier::class);
    }

    public function addons(): HasMany
    {
        return $this->hasMany(LicenseAddon::class, 'entitlement_id');
    }

    public function activeAddons(): HasMany
    {
        return $this->hasMany(LicenseAddon::class, 'entitlement_id')->where('status', 'active');
    }

    public function forceDisableActions(): HasMany
    {
        return $this->hasMany(ForceDisableAction::class);
    }

    public function isExpired(): bool
    {
        return $this->valid_until !== null && now()->greaterThan($this->valid_until);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->isExpired();
    }
}
