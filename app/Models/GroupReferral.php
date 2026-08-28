<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupReferral extends Model
{
    use HasFactory;
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'group_id', 'source_branch_id', 'destination_branch_id',
        'source_patient_id', 'patient_snapshot', 'reason', 'clinical_summary',
        'note', 'status', 'referred_at',
    ];

    protected $casts = [
        'patient_snapshot' => 'array',
        'referred_at' => 'datetime',
    ];

    /** Status transitions allowed FROM the source branch (who initiated it). */
    public const SOURCE_TRANSITIONS = [
        'requested' => ['cancelled'],
    ];

    /** Status transitions allowed FROM the destination branch (who receives it). */
    public const DESTINATION_TRANSITIONS = [
        'requested' => ['accepted', 'rejected'],
        'accepted' => ['in_progress'],
        'in_progress' => ['completed'],
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'source_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'destination_branch_id');
    }
}
