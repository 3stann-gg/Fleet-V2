<?php

namespace App\Policies;

use App\Models\FuelLog;
use App\Models\User;

class FuelLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('fuel.view');
    }

    public function view(
        User $user,
        FuelLog $fuelLog
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | System-wide viewers
        |--------------------------------------------------------------------------
        */

        if (!$user->hasRole('driver') && $user->hasPermission('fuel.view')) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Driver - own fuel records only
        |--------------------------------------------------------------------------
        */

        if ($user->hasRole('driver')) {
            $driverId =
                $user->driverProfile?->id;

            return
                $driverId !== null &&
                (int) $fuelLog->driver_id ===
                    (int) $driverId;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('fuel.create');
    }

    public function update(
        User $user,
        FuelLog $fuelLog
    ): bool {
        return $user->hasPermission('fuel.update');
    }

    /*
    |--------------------------------------------------------------------------
    | Fuel transactions are non-deletable
    |--------------------------------------------------------------------------
    */

    public function delete(
        User $user,
        FuelLog $fuelLog
    ): bool {
        return false;
    }

    public function deleteAny(
        User $user
    ): bool {
        return false;
    }
}
