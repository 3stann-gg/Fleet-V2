<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Maintenance extends Model
{
    use HasFactory;

    public const STATUSES = [
        'Scheduled',
        'In Progress',
        'Completed',
        'Cancelled',
    ];

    public const TRANSITIONS = [
        'Scheduled' => ['In Progress', 'Cancelled'],
        'In Progress' => ['Completed', 'Cancelled'],
        'Completed' => [],
        'Cancelled' => [],
    ];

    protected $fillable = [
        'maintenance_number',
        'vehicle_id',
        'request_type',
        'maintenance_type',
        'description',
        'maintenance_date',
        'completion_date',
        'next_schedule',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_preserve_month_end',
        'technician',
        'priority',
        'odometer',
        'parts_used',
        'cost',
        'status',
        'notes',
        'archived_at',
        'archived_by',
    ];

    protected $casts = [
        'maintenance_date' => 'date:Y-m-d',
        'completion_date' => 'date:Y-m-d',
        'next_schedule' => 'date:Y-m-d',
        'recurrence_frequency' => 'string',
        'recurrence_interval' => 'integer',
        'recurrence_preserve_month_end' => 'boolean',
        'cost' => 'decimal:2',
        'odometer' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function archivedBy()
    {
        return $this->belongsTo(
            User::class,
            'archived_by'
        );
    }
}
