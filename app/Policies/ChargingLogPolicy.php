<?php

namespace App\Policies;

use App\Models\ChargingLog;
use App\Models\User;

class ChargingLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('charging.view');
    }

    public function view(User $user, ChargingLog $chargingLog): bool
    {
        if (!$user->hasRole('driver') && $user->hasPermission('charging.view')) {
            return true;
        }

        return $user->hasRole('driver')
            && $user->driverProfile?->id !== null
            && (int) $chargingLog->driver_id === (int) $user->driverProfile->id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('charging.create');
    }

    public function update(User $user, ChargingLog $chargingLog): bool
    {
        return false;
    }

    public function delete(User $user, ChargingLog $chargingLog): bool
    {
        return false;
    }
}
