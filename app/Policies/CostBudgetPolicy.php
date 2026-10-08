<?php

namespace App\Policies;

use App\Models\CostBudget;
use App\Models\User;

class CostBudgetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('cost_analysis.view');
    }

    public function view(
        User $user,
        CostBudget $costBudget
    ): bool {
        return $user->hasPermission('cost_analysis.view');
    }

    public function manage(User $user): bool
    {
        return $user->hasRole(
            'fleet_manager',
            'finance'
        );
    }
}
