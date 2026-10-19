<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SanitationLog extends Model
{
    use HasFactory;

    protected $appends = [
        'reason_label',
        'cleaning_type_label',
        'status_label',
    ];

    public const REASON_LABELS = [
        'post_patient_transport' => 'Post Patient Transport',
        'routine_end_of_trip' => 'Routine End of Trip',
        'contamination_concern' => 'Contamination Concern',
        'other' => 'Other',
    ];

    public const CLEANING_TYPE_LABELS = [
        'routine_cleaning' => 'Routine Cleaning',
        'disinfection' => 'Disinfection',
        'deep_cleaning' => 'Deep Cleaning',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
    ];

    public const TRANSITIONS = [
        'pending' => ['in_progress'],
        'in_progress' => ['completed'],
        'completed' => [],
    ];

    protected $fillable = [
        'vehicle_id',
        'dispatch_id',
        'reason',
        'cleaning_type',
        'status',
        'started_at',
        'completed_at',
        'performed_by',
        'remarks',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function getReasonLabelAttribute(): string
    {
        return self::REASON_LABELS[$this->reason] ?? $this->reason;
    }

    public function getCleaningTypeLabelAttribute(): string
    {
        return self::CLEANING_TYPE_LABELS[$this->cleaning_type] ?? $this->cleaning_type;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
