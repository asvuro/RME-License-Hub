<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'legal_entity_name', 'contact_email', 'contact_phone',
        'status', 'notes',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function activeTenants(): HasMany
    {
        return $this->hasMany(Tenant::class)->where('status', 'active');
    }
}
