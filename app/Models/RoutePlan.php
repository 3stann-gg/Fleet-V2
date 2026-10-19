<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutePlan extends Model
{
    public const STATUSES = [
        'Draft',
        'Planned',
        'Ready For Dispatch',
        'Completed',
        'Archived',
    ];

    public const WORKFLOW_TRANSITIONS = [
        'Draft' => ['Planned'],
        'Planned' => ['Draft', 'Ready For Dispatch'],
        'Ready For Dispatch' => [],
        'Completed' => [],
        'Archived' => [],
    ];

    protected $fillable = [
        'route_number',
        'reservation_id',

        'origin',
        'origin_latitude',
        'origin_longitude',

        'destination',
        'destination_latitude',
        'destination_longitude',

        'priority',
        'department',
        'status',
        'departure_date',
        'departure_time',
        'estimated_distance',
        'estimated_time',
        'optimization_strategy',
        'optimization_score',
        'purpose',
        'notes',
    ];

    protected $casts = [
        'departure_date' => 'date:Y-m-d',
        'estimated_distance' => 'decimal:2',
        'optimization_score' => 'decimal:2',
        'estimated_time' => 'integer',

        'origin_latitude' => 'decimal:7',
        'origin_longitude' => 'decimal:7',
        'destination_latitude' => 'decimal:7',
        'destination_longitude' => 'decimal:7',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)
            ->orderBy('stop_order');
    }
}
