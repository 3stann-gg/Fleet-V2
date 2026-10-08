<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostBudget extends Model
{
    protected $fillable = [
        'overall_budget',
        'category_budgets',
        'period_type',
        'start_date',
        'end_date',
        'notes',
    ];

    protected $casts = [
        'overall_budget' => 'decimal:2',
        'category_budgets' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
}