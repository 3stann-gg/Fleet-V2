<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\Vehicle;
use Carbon\Carbon;

class FleetNotificationCheckService
{
    public function __construct(
        private MaintenanceScheduleResolver $maintenanceScheduleResolver
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    */
    private function settings(): array
    {
        $record =
            FleetSetting::query()
                ->latest('id')
                ->first();

        $settings =
            $record?->settings ?? [];

        return [
            'licenseWarnDays' => max(
                1,
                min(
                    180,
                    (int) (
                        $settings['drivers']['warnLicenseDays']
                        ?? 30
                    )
                )
            ),

            'maintenanceWarnDays' => max(
                1,
                min(
                    90,
                    (int) (
                        $settings['maintenance']['overdueWarnDays']
                        ?? 3
                    )
                )
            ),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Run Notification Checks
    |--------------------------------------------------------------------------
    */
    public function check(): void
    {
        $user = auth()->user();

        if (! $user) {
            return;
        }

        $settings =
            $this->settings();

        /*
        |--------------------------------------------------------------------------
        | Driver notifications
        |--------------------------------------------------------------------------
        */
        if (
            $user->canViewModule(
                'drivers'
            )
        ) {
            $this->checkDriverLicenses(
                $settings[
                    'licenseWarnDays'
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Maintenance notifications
        |--------------------------------------------------------------------------
        */
        if (
            $user->canViewModule(
                'maintenance'
            )
        ) {
            $this->checkMaintenanceSchedules(
                $settings[
                    'maintenanceWarnDays'
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Driver License Expiry
    |--------------------------------------------------------------------------
    */
    private function checkDriverLicenses(
        int $warningDays
    ): void {
        $user =
            auth()->user();

        if (! $user) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Defensive module RBAC
        |--------------------------------------------------------------------------
        */
        if (
            ! $user->canViewModule(
                'drivers'
            )
        ) {
            return;
        }

        $today =
            now()->startOfDay();

        $maximumDate =
            now()
                ->startOfDay()
                ->addDays(
                    $warningDays
                );

        $driverQuery =
            Driver::query()
                ->whereNotNull(
                    'license_expiry'
                )
                ->whereDate(
                    'license_expiry',
                    '>=',
                    $today->toDateString()
                )
                ->whereDate(
                    'license_expiry',
                    '<=',
                    $maximumDate
                        ->toDateString()
                );

        /*
        |--------------------------------------------------------------------------
        | Driver - own profile only
        |--------------------------------------------------------------------------
        |
        | Prevents a driver from receiving license information for
        | unrelated drivers.
        |--------------------------------------------------------------------------
        */
        if (
            $user->hasRole(
                'driver'
            )
        ) {
            $driverId =
                $user->driverProfile?->id;

            if ($driverId) {
                $driverQuery->where(
                    'id',
                    $driverId
                );
            } else {
                $driverQuery
                    ->whereRaw(
                        '1 = 0'
                    );
            }
        }

        $drivers =
            $driverQuery->get();

        foreach (
            $drivers as $driver
        ) {
            $expiry =
                Carbon::parse(
                    $driver
                        ->license_expiry
                )->startOfDay();

            $days =
                $today->diffInDays(
                    $expiry,
                    false
                );

            $name =
                trim(
                    ($driver->first_name ?? '').
                    ' '.
                    ($driver->last_name ?? '')
                );

            if ($name === '') {
                $name = 'Driver';
            }

            $when =
                $days === 0
                    ? 'today'
                    : "in {$days} day".
                        (
                            $days === 1
                                ? ''
                                : 's'
                        );

            $licenseNumber =
                $driver->license_number
                ?: 'No license number';

            $message =
                "{$name}'s license ({$licenseNumber}) expires {$when}.";

            $eventKey =
                'driver_license_expiring:'.
                $driver->id.
                ':'.
                $expiry->format(
                    'Y-m-d'
                );

            /*
            |--------------------------------------------------------------------------
            | Important
            |--------------------------------------------------------------------------
            |
            | Link allows FleetNotificationService to enforce
            | module-level RBAC before saving the notification.
            |--------------------------------------------------------------------------
            */
            FleetNotificationService::createUniqueWhenEnabled(
                'licenseExpiring',
                (
                    $driver->driver_number
                    ?: 'Driver'
                ).
                ' · Driver License Expiring',
                $message,
                $eventKey,
                true,
                route('driver')
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance Schedule Notifications
    |--------------------------------------------------------------------------
    */
    private function checkMaintenanceSchedules(
        int $warningDays
    ): void {
        $user =
            auth()->user();

        if (! $user) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Defensive module RBAC
        |--------------------------------------------------------------------------
        */
        if (
            ! $user->canViewModule(
                'maintenance'
            )
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Only records with next_schedule can generate reminders
        |--------------------------------------------------------------------------
        */
        $maintenances =
            Maintenance::query()
                ->with('vehicle')
                ->whereNotNull(
                    'next_schedule'
                )
                ->whereNotIn(
                    'status',
                    [
                        'Completed',
                        'Cancelled',
                    ]
                )
                ->get();

        foreach (
            $maintenances as $maintenance
        ) {
            $this->createMaintenanceScheduleNotification(
                $maintenance,
                $maintenance->vehicle,
                $warningDays
            );
        }

        $vehicles =
            Vehicle::query()
                ->whereHas(
                    'lastCompletedMaintenance'
                )
                ->with(
                    'lastCompletedMaintenance'
                )
                ->get();

        foreach ($vehicles as $vehicle) {
            $context =
                $this->maintenanceScheduleResolver
                    ->resolve($vehicle);

            $maintenance =
                $context['maintenance'];

            if (! $maintenance || ! $context['has_next_schedule']) {
                continue;
            }

            $this->createMaintenanceScheduleNotification(
                $maintenance,
                $vehicle,
                $warningDays,
                true
            );
        }
    }

    private function createMaintenanceScheduleNotification(
        Maintenance $maintenance,
        ?Vehicle $vehicle,
        int $warningDays,
        bool $isFutureService = false
    ): void {
        if (! $maintenance->next_schedule) {
            return;
        }

        $today = now()->startOfDay();
        $nextSchedule =
            Carbon::parse(
                $maintenance->next_schedule
            )->startOfDay();
        $days =
            $today->diffInDays(
                $nextSchedule,
                false
            );

        if ($days > $warningDays) {
            return;
        }

        $vehicleName =
            trim(
                ($vehicle?->brand ?? '').
                ' '.
                ($vehicle?->model ?? '')
            );

        if ($vehicleName === '') {
            $vehicleName = 'Vehicle';
        }

        $maintenanceDescription =
            $isFutureService
                ? "next service after maintenance {$maintenance->maintenance_number}"
                : "maintenance {$maintenance->maintenance_number}";

        if ($days < 0) {
            $daysOverdue = abs($days);
            $title = 'Maintenance Overdue';
            $message =
                "{$vehicleName} {$maintenanceDescription} ".
                "is overdue by {$daysOverdue} day".
                ($daysOverdue === 1 ? '' : 's').
                '.';
        } else {
            $when =
                $days === 0
                    ? 'today'
                    : "in {$days} day".
                        ($days === 1 ? '' : 's');
            $title = 'Maintenance Due Soon';
            $message =
                "{$vehicleName} {$maintenanceDescription} ".
                "is due {$when}.";
        }

        $eventKey =
            'maintenance_due:'.
            $maintenance->id.
            ':'.
            $nextSchedule->format('Y-m-d');

        FleetNotificationService::createUniqueWhenEnabled(
            'maintenanceDue',
            $title,
            $message,
            $eventKey,
            true,
            route('maintenance')
        );
    }
}
