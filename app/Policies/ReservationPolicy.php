<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('reservations.view');
    }

    public function view(
        User $user,
        Reservation $reservation
    ): bool {
        /*
        |--------------------------------------------------------------------------
        | Full / View-only system-wide roles
        |--------------------------------------------------------------------------
        */
        if (
            !$user->hasRole('driver', 'department_head')
            && $user->hasPermission('reservations.view')
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | Driver - assigned reservations only
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('driver')) {
            $driverId = $user->driverProfile?->id;

            return $driverId !== null
                && (int) $reservation->driver_id === (int) $driverId;
        }

        /*
        |--------------------------------------------------------------------------
        | Department Head - own department only
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('department_head')) {
            return
                !empty($user->department) &&
                !empty($reservation->department) &&
                $reservation->department === $user->department;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    | Department Head only
    |--------------------------------------------------------------------------
    */
    public function create(User $user): bool
    {
        return $user->hasPermission('reservations.create');
    }
    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    | Dispatcher:
    | - Can edit active reservations
    | - Cannot edit archived reservations
    |
    | Department Head:
    | - Own department only
    | - Can edit active reservations
    | - Cannot edit archived reservations
    |--------------------------------------------------------------------------
    */
    public function update(
        User $user,
        Reservation $reservation
    ): bool {

        if ($user->hasPermission('reservations.manage_all')) {
            return $reservation->archived_at === null;
        }

        if ($user->hasRole('department_head')) {
            return
                $reservation->archived_at === null &&
                $reservation->status === 'Pending' &&
                !empty($user->department) &&
                $reservation->department === $user->department;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Archive
    |--------------------------------------------------------------------------
    | Fleet Manager only
    |--------------------------------------------------------------------------
    */
    public function archive(
        User $user,
        Reservation $reservation
    ): bool {
        return $user->hasPermission('reservations.archive');
    }

    public function archiveAny(User $user): bool
    {
        return $user->hasPermission('reservations.archive');
    }

    /*
    |--------------------------------------------------------------------------
    | Restore
    |--------------------------------------------------------------------------
    | Fleet Manager only
    |--------------------------------------------------------------------------
    */
    public function restore(
        User $user,
        Reservation $reservation
    ): bool {
        return $user->hasPermission('reservations.archive');
    }

    /*
    |--------------------------------------------------------------------------
    | Approve
    |--------------------------------------------------------------------------
    | Dispatcher + Fleet Manager
    |--------------------------------------------------------------------------
    */
    public function approve(
        User $user,
        Reservation $reservation
    ): bool {
        return $user->hasPermission('reservations.manage_all');
    }

    /*
    |--------------------------------------------------------------------------
    | Reject
    |--------------------------------------------------------------------------
    | Dispatcher + Fleet Manager
    |--------------------------------------------------------------------------
    */
    public function reject(
        User $user,
        Reservation $reservation
    ): bool {
        return $user->hasPermission('reservations.manage_all');
    }
}
