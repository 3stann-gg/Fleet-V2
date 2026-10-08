<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreTripChecklist extends Model
{
    use HasFactory;

    protected $appends = ['result_label'];

    protected $fillable = [
        'dispatch_id',
        'vehicle_id',
        'driver_id',
        'checked_by',
        'result',
        'remarks',
        'checklist_data',
        'checked_at',
    ];

    protected $casts = [
        'checklist_data' => 'array',
        'checked_at' => 'datetime',
    ];

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(Dispatch::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function getResultLabelAttribute(): string
    {
        return match ($this->result) {
            'passed' => 'Passed',
            'passed_with_remarks' => 'Passed with Remarks',
            'failed' => 'Failed',
            default => 'Not Specified',
        };
    }
}
