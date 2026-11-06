<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;

class MaintenanceScheduleResolver
{
    /**
     * @return array{
     *     vehicle_id: int,
     *     maintenance: Maintenance|null,
     *     maintenance_id: int|null,
     *     completion_date: string|null,
     *     next_schedule: string|null,
     *     has_next_schedule: bool
     * }
     */
    public function resolve(Vehicle $vehicle): array
    {
        $maintenance = $this->authoritativeMaintenance($vehicle);

        return [
            'vehicle_id' => (int) $vehicle->getKey(),
            'maintenance' => $maintenance,
            'maintenance_id' => $maintenance?->getKey(),
            'completion_date' => $maintenance?->completion_date?->toDateString(),
            'next_schedule' => $maintenance?->next_schedule?->toDateString(),
            'has_next_schedule' => $maintenance?->next_schedule !== null,
        ];
    }

    private function authoritativeMaintenance(Vehicle $vehicle): ?Maintenance
    {
        if ($vehicle->relationLoaded('lastCompletedMaintenance')) {
            return $vehicle->lastCompletedMaintenance;
        }

        return $vehicle->lastCompletedMaintenance()->first();
    }
}
