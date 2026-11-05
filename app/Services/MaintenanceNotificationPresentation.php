<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use Carbon\Carbon;
use LogicException;

final class MaintenanceNotificationPresentation
{
    /** @return array{title: string, message: string} */
    public static function fromLockedSource(
        Maintenance $maintenance,
        Vehicle $vehicle
    ): array {
        if (
            (int) $maintenance->vehicle_id !== (int) $vehicle->getKey()
            || $maintenance->next_schedule === null
        ) {
            throw new LogicException(
                'Authoritative maintenance notification presentation requires a matching scheduled source.'
            );
        }

        $today = now()->startOfDay();
        $nextSchedule = Carbon::parse($maintenance->next_schedule)->startOfDay();
        $days = $today->diffInDays($nextSchedule, false);
        $vehicleName = trim(
            ($vehicle->brand ?? '').' '.($vehicle->model ?? '')
        );

        if ($vehicleName === '') {
            $vehicleName = 'Vehicle';
        }

        $maintenanceDescription = $maintenance->status === 'Completed'
            ? "next service after maintenance {$maintenance->maintenance_number}"
            : "maintenance {$maintenance->maintenance_number}";

        if ($days < 0) {
            $daysOverdue = abs($days);

            return [
                'title' => 'Maintenance Overdue',
                'message' => "{$vehicleName} {$maintenanceDescription} ".
                    "is overdue by {$daysOverdue} day".
                    ($daysOverdue === 1 ? '' : 's').
                    '.',
            ];
        }

        $when = $days === 0
            ? 'today'
            : "in {$days} day".($days === 1 ? '' : 's');

        return [
            'title' => 'Maintenance Due Soon',
            'message' => "{$vehicleName} {$maintenanceDescription} is due {$when}.",
        ];
    }
}
