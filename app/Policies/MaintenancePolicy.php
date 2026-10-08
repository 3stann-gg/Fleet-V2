<?php

namespace App\Policies;

use App\Models\Maintenance;
use App\Models\User;

class MaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('maintenance.view');
    }

    public function view(
        User $user,
        Maintenance $maintenance
    ): bool {
        return $user->hasPermission('maintenance.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('maintenance.create');
    }

    public function update(
        User $user,
        Maintenance $maintenance
    ): bool {
        return $user->hasPermission('maintenance.update');
    }

    public function archive(
        User $user,
        Maintenance $maintenance
    ): bool {
        return $user->hasPermission('maintenance.archive');
    }

    public function archiveAny(
        User $user
    ): bool {
        return $user->hasPermission('maintenance.archive');
    }

    public function restore(
        User $user,
        Maintenance $maintenance
    ): bool {
        return $user->hasPermission('maintenance.archive');
    }
}
