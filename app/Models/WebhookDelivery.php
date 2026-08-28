<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'tenant_id', 'event_type', 'event_id', 'payload',
        'url', 'attempts', 'max_attempts',
        'last_response_code', 'last_response_body',
        'delivered_at', 'next_attempt_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'delivered_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    public function canRetry(): bool
    {
        return ! $this->isDelivered() && $this->attempts < $this->max_attempts;
    }
}
