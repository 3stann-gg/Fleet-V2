<?php

namespace App\Policies;

use App\Models\RoutePlan;
use App\Models\User;

class RoutePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('routes.view');
    }

    public function view(
        User $user,
        RoutePlan $routePlan
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | System-wide viewers
        |--------------------------------------------------------------------------
        */
        if (!$user->hasRole('driver') && $user->hasPermission('routes.view')) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Driver - own assigned route only
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('driver')) {
            $driverId =
                $user->driverProfile?->id;

            return
                $driverId !== null &&
                (int) $routePlan->reservation?->driver_id ===
                    (int) $driverId;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    | Route Planning creation is handled by Dispatcher.
    |--------------------------------------------------------------------------
    */
    public function create(User $user): bool
    {
        return $user->hasPermission('routes.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    | Fleet Manager is monitoring only.
    |--------------------------------------------------------------------------
    */
    public function update(
        User $user,
        RoutePlan $routePlan
    ): bool {
        return $user->hasPermission('routes.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    */
    public function archive(
        User $user,
        RoutePlan $routePlan
    ): bool {
        return $user->hasPermission('routes.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    */
    public function restore(
        User $user,
        RoutePlan $routePlan
    ): bool {
        return $user->hasPermission('routes.manage');
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    | Route plans are intentionally non-deletable.
    |--------------------------------------------------------------------------
    */
    public function delete(
        User $user,
        RoutePlan $routePlan
    ): bool {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
