<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLicenseCode extends Model
{
    protected $fillable = [
        'driver_id',
        'code',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
