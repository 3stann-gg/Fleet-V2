<?php

namespace App\Policies;

use App\Models\TrafficViolation;
use App\Models\User;

class TrafficViolationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('traffic_violations.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, TrafficViolation $trafficViolation): bool
    {
        if (! $user->hasPermission('traffic_violations.view')) {
            return false;
        }

        if ($user->hasRole('driver')) {
            return (int) $trafficViolation->driver_id === (int) $user->driverProfile?->id;
        }

        if ($user->hasRole('maintenance')) {
            return in_array($trafficViolation->violation_type, [
                'registration_document_issue',
                'vehicle_equipment_issue',
            ], true);
        }

        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('traffic_violations.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, TrafficViolation $trafficViolation): bool
    {
        return $user->hasPermission('traffic_violations.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function review(User $user, TrafficViolation $trafficViolation): bool
    {
        return $user->hasPermission('traffic_violations.review');
    }

    public function settlement(User $user, TrafficViolation $trafficViolation): bool
    {
        return $user->hasPermission('traffic_violations.settlement');
    }

    public function archive(User $user, TrafficViolation $trafficViolation): bool
    {
        return $user->hasPermission('traffic_violations.archive');
    }

    public function restore(User $user, TrafficViolation $trafficViolation): bool
    {
        return $user->hasPermission('traffic_violations.archive');
    }
}
