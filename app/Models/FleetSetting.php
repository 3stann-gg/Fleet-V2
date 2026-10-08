<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetSetting extends Model
{
    protected $fillable = [
        'settings',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'updated_by'
        );
    }
}