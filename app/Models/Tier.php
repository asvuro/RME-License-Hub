<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tier extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'description',
        'base_max_users', 'default_duration_days',
        'included_modules', 'metadata', 'is_active',
    ];

    protected $casts = [
        'base_max_users' => 'integer',
        'default_duration_days' => 'integer',
        'included_modules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function entitlements(): HasMany
    {
        return $this->hasMany(LicenseEntitlement::class);
    }
}
