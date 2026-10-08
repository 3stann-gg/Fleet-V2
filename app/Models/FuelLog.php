<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelLog extends Model
{
    protected $fillable = [
        'fuel_number',
        'vehicle_id',
        'driver_id',
        'fuel_amount',
        'cost_per_liter',
        'cost',
        'odometer',
        'date',
        'refuel_time',
        'fuel_type',
        'fuel_station',
        'receipt_number',
        'payment_method',
        'notes',
    ];

    protected $casts = [
        'fuel_amount' => 'decimal:2',
        'cost_per_liter' => 'decimal:2',
        'cost' => 'decimal:2',
        'odometer' => 'decimal:2',
        'date' => 'date:Y-m-d',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
