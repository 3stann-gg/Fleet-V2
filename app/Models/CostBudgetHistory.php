<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostBudgetHistory extends Model
{
    protected $fillable = [
        'action',
        'previous_value',
        'new_value',
        'period_type',
    ];

    protected $casts = [
        'previous_value' => 'decimal:2',
        'new_value' => 'decimal:2',
    ];
}