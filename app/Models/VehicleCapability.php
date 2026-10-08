<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VehicleCapability extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(
            Vehicle::class,
            'vehicle_capability_vehicle',
            'vehicle_capability_id',
            'vehicle_id'
        );
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(
            Reservation::class,
            'reservation_required_capabilities',
            'vehicle_capability_id',
            'reservation_id'
        );
    }
}

