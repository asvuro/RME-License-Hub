<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LicenseAddon extends Model
{
    use HasUuids;
    use HasFactory;
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'entitlement_id', 'addon_type', 'target_module_slug',
        'quantity', 'label', 'effective_from', 'effective_until',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'effective_from' => 'datetime',
        'effective_until' => 'datetime',
    ];

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LicenseEntitlement::class, 'entitlement_id');
    }

    public function isExpired(): bool
    {
        return $this->effective_until !== null && now()->greaterThan($this->effective_until);
    }

    public function isCurrentlyActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if ($this->effective_from && now()->lessThan($this->effective_from)) {
            return false;
        }

        return true;
    }
}
