<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;

class VehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('vehicles.view');
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        if ($user->hasRole('driver')) {
            $vehicleId = $user->driverProfile?->assigned_vehicle_id;

            return $vehicleId !== null
                && (int) $vehicleId === (int) $vehicle->id;
        }

        return $user->hasPermission('vehicles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicles.create');
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('vehicles.update');
    }

    public function archive(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('vehicles.archive');
    }

    public function archiveAny(User $user): bool
    {
        return $user->hasPermission('vehicles.archive');
    }

    public function restore(User $user, Vehicle $vehicle): bool
    {
        return $user->hasPermission('vehicles.archive');
    }
}
