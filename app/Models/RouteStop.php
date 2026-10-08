<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends Model
{
    protected $fillable = [
        'route_plan_id',
        'stop_order',
        'location',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'stop_order' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function routePlan(): BelongsTo
    {
        return $this->belongsTo(RoutePlan::class);
    }
}