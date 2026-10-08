<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargingLog extends Model
{
    protected $fillable = [
        'vehicle_id',
        'driver_id',
        'charging_date',
        'charging_time',
        'battery_before_percent',
        'battery_after_percent',
        'energy_added_kwh',
        'charging_location',
        'charging_cost',
        'odometer',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'charging_date' => 'date:Y-m-d',
            'battery_before_percent' => 'decimal:2',
            'battery_after_percent' => 'decimal:2',
            'energy_added_kwh' => 'decimal:2',
            'charging_cost' => 'decimal:2',
            'odometer' => 'decimal:2',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
