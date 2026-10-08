<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTripChecklist extends Model
{
    use HasFactory;

    protected $fillable = [
        'dispatch_id',
        'vehicle_id',
        'driver_id',
        'submitted_by',
        'final_odometer',
        'fuel_level_remaining',
        'battery_level_remaining',
        'visible_damage',
        'tire_condition',
        'lights_condition',
        'brake_condition',
        'issue_reported',
        'maintenance_required',
        'cleaning_required',
        'remarks',
        'submitted_at',
    ];

    protected $casts = [
        'final_odometer' => 'decimal:2',
        'fuel_level_remaining' => 'decimal:2',
        'battery_level_remaining' => 'decimal:2',
        'visible_damage' => 'boolean',
        'issue_reported' => 'boolean',
        'maintenance_required' => 'boolean',
        'cleaning_required' => 'boolean',
        'submitted_at' => 'datetime',
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

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}

