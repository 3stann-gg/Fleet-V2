<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\Driver;
use App\Models\Dispatch;
use App\Models\Maintenance;
use App\Models\FleetNotification;
use App\Models\Reservation;
use App\Models\SanitationLog;
use App\Services\VehicleOperationalHealthService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private VehicleOperationalHealthService $healthService) {}

    private function buildDashboardData(Request $request): array
    {
        $user = $request->user();

        abort_unless(
            $user?->canViewModule('dashboard'),
            403
        );

        $role = $user->role;
        $department = $user->department;

        /*
        |--------------------------------------------------------------------------
        | Dashboard UI Permissions
        |--------------------------------------------------------------------------
        */
        $dashboardPermissions = [
            'role' => $role,

            'canSeeDispatchQueue' =>
                $user->hasRole(
                    'fleet_manager',
                    'dispatcher',
                    'driver',
                    'department_head',
                    'it_admin'
                ),

            'canSeeDriverPool' =>
                $user->hasRole(
                    'fleet_manager',
                    'dispatcher',
                    'it_admin'
                ),

            'canSeeMaintenance' =>
                $user->hasRole(
                    'fleet_manager',
                    'dispatcher',
                    'finance',
                    'maintenance',
                    'it_admin'
                ),

            'canSeeFleetStatus' => true,

            'canOpenVehicles' =>
                $user->canViewModule('vehicles'),

            'canOpenDispatch' =>
                $user->canViewModule('dispatch'),

            'canOpenDrivers' =>
                $user->canViewModule('drivers'),

            'canOpenMaintenance' =>
                $user->canViewModule('maintenance'),
        ];

        /*
        |--------------------------------------------------------------------------
        | Vehicle Scope
        |--------------------------------------------------------------------------
        |
        | Fleet Manager / Dispatcher / Finance / Maintenance / IT Admin
        |     -> fleet-wide
        |
        | Driver
        |     -> assigned vehicle only
        |
        | Department Head
        |     -> own department only
        |--------------------------------------------------------------------------
        */
        $vehicleScope = function () use (
            $user,
            $department
        ) {
            $query = Vehicle::query();

            /*
            |--------------------------------------------------------------------------
            | Driver
            |--------------------------------------------------------------------------
            */
            if ($user->hasRole('driver')) {
                $vehicleId =
                    $user->driverProfile
                        ?->assigned_vehicle_id;

                if ($vehicleId) {
                    $query->where(
                        'id',
                        $vehicleId
                    );
                } else {
                    $query->whereRaw('1 = 0');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Department Head
            |--------------------------------------------------------------------------
            */
            elseif (
                $user->hasRole(
                    'department_head'
                )
            ) {
                if ($department) {
                    $query->where(
                        'department',
                        $department
                    );
                } else {
                    $query->whereRaw('1 = 0');
                }
            }

            return $query;
        };

        /*
        |--------------------------------------------------------------------------
        | Dispatch Scope
        |--------------------------------------------------------------------------
        |
        | Fleet Manager / Dispatcher / Finance / IT Admin
        |     -> fleet-wide read
        |
        | Driver
        |     -> own assignments only
        |
        | Department Head
        |     -> own department only
        |
        | Maintenance
        |     -> no dispatch data on dashboard
        |--------------------------------------------------------------------------
        */
        $dispatchScope = function () use (
            $user,
            $department
        ) {
            $query = Dispatch::query();

            /*
            |--------------------------------------------------------------------------
            | Driver
            |--------------------------------------------------------------------------
            */
            if ($user->hasRole('driver')) {
                $driverId =
                    $user->driverProfile?->id;

                if ($driverId) {
                    $query->whereHas(
                        'reservation',
                        function ($reservationQuery) use ($driverId) {
                            $reservationQuery->where(
                                'driver_id',
                                $driverId
                            );
                        }
                    );
                } else {
                    $query->whereRaw('1 = 0');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Department Head
            |--------------------------------------------------------------------------
            */
            elseif (
                $user->hasRole(
                    'department_head'
                )
            ) {
                if (!$department) {
                    $query->whereRaw('1 = 0');

                    return $query;
                }

                $query->whereHas(
                    'reservation',
                    function ($reservationQuery) use ($department) {
                        $reservationQuery->where('department', $department);
                    }
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Maintenance
            |--------------------------------------------------------------------------
            */
            elseif (
                $user->hasRole('maintenance', 'finance')
            ) {
                $query->whereRaw('1 = 0');
            }

            return $query;
        };

        $reservationScope = function () use ($user, $department) {
            $query = Reservation::query()->whereNull('archived_at');

            if ($user->hasRole('driver')) {
                $driverId = $user->driverProfile?->id;
                $driverId
                    ? $query->where('driver_id', $driverId)
                    : $query->whereRaw('1 = 0');
            } elseif ($user->hasRole('department_head')) {
                $department
                    ? $query->where('department', $department)
                    : $query->whereRaw('1 = 0');
            } elseif ($user->hasRole('maintenance', 'finance')) {
                $query->whereRaw('1 = 0');
            }

            return $query;
        };

        /*
        |--------------------------------------------------------------------------
        | Primary KPI - Available Vehicles
        |--------------------------------------------------------------------------
        */
        $readyVehicles =
            $vehicleScope()
                ->where(
                    'status',
                    'Available'
                )
                ->where(function ($query) {
                    $query
                        ->where('readiness_status', 'ready')
                        ->orWhereNull('readiness_status');
                })
                ->count();

        $vehiclesNeedingAttention =
            $vehicleScope()
                ->where(function ($query) {
                    $query
                        ->whereIn('readiness_status', [
                            'maintenance',
                            'refueling',
                            'charging',
                            'cleaning',
                            'unavailable',
                        ])
                        ->orWhere(function ($legacy) {
                            $legacy
                                ->whereNull('readiness_status')
                                ->whereIn('status', ['Maintenance', 'Out of Service']);
                        });
                })
                ->count();

        $emergencyRequests =
            $reservationScope()
                ->whereIn('priority', ['emergency', 'Emergency'])
                ->whereIn('status', ['Pending', 'Approved', 'Scheduled'])
                ->count();

        /*
        |--------------------------------------------------------------------------
        | Primary KPI - Active Dispatches
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Must use scoped dispatch query.
        |--------------------------------------------------------------------------
        */
        $activeDispatches =
            $dispatchScope()
                ->whereIn(
                    'trip_status',
                    [
                        'Assigned',
                        'En Route',
                        'Arrived',
                    ]
                )
                ->count();

        $failedPreTripChecks = $dispatchScope()
            ->whereHas('preTripChecklist', function ($query) {
                $query->where('result', 'failed');
            })
            ->count();

        $maintenanceRequiredPostTripChecks = $dispatchScope()
            ->whereHas('postTripChecklist', function ($query) {
                $query->where('maintenance_required', true);
            })
            ->count();

        $activeSanitationRecords = SanitationLog::query()
            ->whereIn('vehicle_id', $vehicleScope()->select('id'))
            ->whereIn('status', ['pending', 'in_progress'])
            ->count();

        $healthVehicles = $vehicleScope()
            ->with([
                'lastCompletedMaintenance',
                'latestPreTripChecklist',
                'latestPostTripChecklist',
                'activeSanitationLog',
            ])
            ->get();
        $healthSummaries = $healthVehicles
            ->map(fn (Vehicle $vehicle) => $this->healthService->summarize($vehicle));
        $expiredComplianceItems = $healthSummaries->sum(function (array $summary): int {
            return (int) ($summary['registration']['state'] === 'expired')
                + (int) ($summary['insurance']['state'] === 'expired');
        });
        $expiringWithinThirtyDays = $healthSummaries->sum(function (array $summary): int {
            $states = ['expiring_7_days', 'expiring_30_days'];

            return (int) in_array($summary['registration']['state'], $states, true)
                + (int) in_array($summary['insurance']['state'], $states, true);
        });

        $driverComplianceQuery = Driver::query();
        if ($user->hasRole('driver')) {
            $driverComplianceQuery->whereKey($user->driverProfile?->id ?? 0);
        } elseif ($user->hasRole('department_head')) {
            $driverComplianceQuery->whereIn('assigned_vehicle_id', $healthVehicles->pluck('id'));
        }

        $driverCompliance = $driverComplianceQuery->get(['id', 'license_expiry']);
        foreach ($driverCompliance as $driver) {
            $license = $this->healthService->driverLicenseAdvisory($driver);
            $expiredComplianceItems += (int) ($license['state'] === 'expired');
            $expiringWithinThirtyDays += (int) in_array(
                $license['state'],
                ['expiring_7_days', 'expiring_30_days'],
                true,
            );
        }

        $maintenanceDueOrOverdue = $healthSummaries
            ->whereIn('maintenance.state', ['due', 'overdue'])
            ->count();
        $batteryHealthReviewNeeded = $healthSummaries
            ->where('battery_health.state', 'service_review')
            ->count();
        $vehiclesWithOperationalWarnings = $healthSummaries
            ->whereIn('state', ['ready_with_advisory', 'attention_required', 'unavailable'])
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Primary KPI - Drivers On Duty
        |--------------------------------------------------------------------------
        */
        if (
            $user->hasRole(
                'fleet_manager',
                'dispatcher',
                'it_admin'
            )
        ) {
            $driversOnDuty =
                Driver::query()
                    ->where(
                        'status',
                        'On Duty'
                    )
                    ->count();
        }

        /*
        |--------------------------------------------------------------------------
        | Driver - own duty status only
        |--------------------------------------------------------------------------
        */
        elseif (
            $user->hasRole('driver')
        ) {
            $driverProfile =
                $user->driverProfile;

            $driversOnDuty =
                $driverProfile &&
                $driverProfile->status === 'On Duty'
                    ? 1
                    : 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Department Head - department vehicle drivers only
        |--------------------------------------------------------------------------
        */
        elseif (
            $user->hasRole(
                'department_head'
            )
        ) {
            if ($department) {
                $driversOnDuty =
                    Driver::query()
                        ->where(
                            'status',
                            'On Duty'
                        )
                        ->whereHas(
                            'vehicle',
                            function ($query) use ($department) {
                                $query->where(
                                    'department',
                                    $department
                                );
                            }
                        )
                        ->count();
            } else {
                $driversOnDuty = 0;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Maintenance
        |--------------------------------------------------------------------------
        */
        else {
            $driversOnDuty = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Average Fuel Level
        |--------------------------------------------------------------------------
        */
        $vehiclesWithFuel =
            $vehicleScope()
                ->whereNotNull(
                    'tank_capacity'
                )
                ->where(
                    'tank_capacity',
                    '>',
                    0
                )
                ->whereNotNull(
                    'current_fuel'
                )
                ->get([
                    'tank_capacity',
                    'current_fuel',
                ]);

        $averageFuelLevel =
            $vehiclesWithFuel->isNotEmpty()
                ? (int) round(
                    $vehiclesWithFuel->avg(
                        function ($vehicle) {
                            return min(
                                100,
                                max(
                                    0,
                                    (
                                        (float) $vehicle->current_fuel
                                        /
                                        (float) $vehicle->tank_capacity
                                    ) * 100
                                )
                            );
                        }
                    )
                )
                : 0;

        /*
        |--------------------------------------------------------------------------
        | Today's Dispatch Queue
        |--------------------------------------------------------------------------
        */
        $dispatchQueue =
            $dispatchScope()
                ->with([
                    'reservation.vehicle',
                    'reservation.driver',
                ])
                ->whereDate(
                    'dispatch_date',
                    today()
                )
                ->whereIn(
                    'trip_status',
                    [
                        'Pending',
                        'Assigned',
                        'En Route',
                        'Arrived',
                    ]
                )
                ->orderByRaw("
                    CASE trip_status
                        WHEN 'En Route' THEN 1
                        WHEN 'Arrived' THEN 2
                        WHEN 'Assigned' THEN 3
                        WHEN 'Pending' THEN 4
                        ELSE 5
                    END
                ")
                ->orderByRaw("
                    CASE
                        WHEN trip_status = 'Pending' THEN
                            CASE (
                                SELECT priority
                                FROM reservations
                                WHERE reservations.id = dispatch.reservation_id
                            )
                                WHEN 'emergency' THEN 1
                                WHEN 'Emergency' THEN 1
                                WHEN 'urgent' THEN 2
                                WHEN 'High' THEN 2
                                WHEN 'routine' THEN 3
                                WHEN 'Normal' THEN 3
                                WHEN 'scheduled' THEN 4
                                WHEN 'Low' THEN 4
                                ELSE 5
                            END
                        ELSE 0
                    END
                ")
                ->orderBy(
                    'departure_time'
                )
                ->limit(5)
                ->get();
        /*
        |--------------------------------------------------------------------------
        | Dashboard Map Dispatch Data
        |--------------------------------------------------------------------------
        | Lightweight data only for Leaflet map markers.
        | Uses the already-scoped dispatch queue, so Driver and Department Head
        | still receive only the dispatch records they are allowed to see.
        |--------------------------------------------------------------------------
        */
        $dashboardMapDispatches =
            $dispatchQueue
                ->map(function ($dispatch) {
                    $reservation =
                        $dispatch->reservation;

                    if (!$reservation) {
                        return null;
                    }
                    return [
                        'id' =>
                            $dispatch->id,

                        'dispatch_number' =>
                            $dispatch->dispatch_number,
                        'status' =>
                            $dispatch->trip_status,
                        'pickup' =>
                            $reservation->pickup_location,
                        'destination' =>
                            $reservation->destination,
                        'vehicle' =>
                            $reservation->vehicle
                                ?->display_label,
                        'driver' =>
                            trim(
                                ($reservation->driver
                                    ?->first_name ?? '')
                                . ' ' .
                                ($reservation->driver
                                    ?->last_name ?? '')
                            ),
                    ];
                })
                ->filter()
                ->values();
        /*
        |--------------------------------------------------------------------------
        | Vehicle Status
        |--------------------------------------------------------------------------
        */
        $vehicles =
            $vehicleScope()
                ->with('drivers')
                ->orderByRaw("
                    CASE status
                        WHEN 'On Trip' THEN 1
                        WHEN 'Maintenance' THEN 2
                        WHEN 'Available' THEN 3
                        WHEN 'Out of Service' THEN 4
                        ELSE 5
                    END
                ")
                ->latest('id')
                ->limit(5)
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Maintenance Alerts
        |--------------------------------------------------------------------------
        */
        $maintenanceQuery =
            Maintenance::query()
                ->with('vehicle')
                ->whereIn(
                    'status',
                    [
                        'Scheduled',
                        'In Progress',
                    ]
                );

        /*
        |--------------------------------------------------------------------------
        | Driver
        |--------------------------------------------------------------------------
        |
        | Dashboard currently does not display Maintenance Alerts
        | for Driver, so do not load unrelated service records.
        |--------------------------------------------------------------------------
        */
        if (
            $user->hasRole('driver')
        ) {
            $maintenanceQuery
                ->whereRaw('1 = 0');
        }

        /*
        |--------------------------------------------------------------------------
        | Department Head
        |--------------------------------------------------------------------------
        */
        elseif (
            $user->hasRole(
                'department_head'
            )
        ) {
            $maintenanceQuery
                ->whereRaw('1 = 0');
        }

        $maintenanceAlerts =
            $maintenanceQuery
                ->orderByRaw("
                    CASE priority
                        WHEN 'Emergency' THEN 1
                        WHEN 'High' THEN 2
                        WHEN 'Normal' THEN 3
                        WHEN 'Low' THEN 4
                        ELSE 5
                    END
                ")
                ->orderBy(
                    'maintenance_date'
                )
                ->limit(5)
                ->get();

        /*
        |--------------------------------------------------------------------------
        | Weekly Fleet Activity
        |--------------------------------------------------------------------------
        */
        $weekStart =
            now()->startOfWeek(
                Carbon::MONDAY
            );

        $weekEnd =
            now()->endOfWeek(
                Carbon::SUNDAY
            );

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Your previous version accidentally repeated $dispatchQueue here.
        | This must be $weeklyRaw.
        |--------------------------------------------------------------------------
        */
        $weeklyRaw =
            $dispatchScope()
                ->selectRaw(
                    'dispatch_date, COUNT(*) AS total'
                )
                ->whereBetween(
                    'dispatch_date',
                    [
                        $weekStart->toDateString(),
                        $weekEnd->toDateString(),
                    ]
                )
                ->groupBy(
                    'dispatch_date'
                )
                ->pluck(
                    'total',
                    'dispatch_date'
                );

        $weeklyActivity =
            collect();

        for (
            $day = 0;
            $day < 7;
            $day++
        ) {
            $date =
                $weekStart
                    ->copy()
                    ->addDays($day);

            $dateKey =
                $date->toDateString();

            $weeklyActivity->push([
                'date' =>
                    $dateKey,

                'day' =>
                    $date->format('D'),

                'total' =>
                    (int) (
                        $weeklyRaw[
                            $dateKey
                        ] ?? 0
                    ),
            ]);
        }

        $weeklyActivityMax =
            max(
                1,
                (int)
                $weeklyActivity->max(
                    'total'
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Recent Activity
        |--------------------------------------------------------------------------
        |
        | User-specific notifications only.
        |--------------------------------------------------------------------------
        */
        $recentActivity =
            FleetNotification::query()
                ->where(
                    'user_id',
                    $user->id
                )
                ->latest('id')
                ->limit(5)
                ->get();

        return compact(
            'readyVehicles',
            'vehiclesNeedingAttention',
            'emergencyRequests',
            'activeDispatches',
            'failedPreTripChecks',
            'maintenanceRequiredPostTripChecks',
            'activeSanitationRecords',
            'expiredComplianceItems',
            'expiringWithinThirtyDays',
            'maintenanceDueOrOverdue',
            'batteryHealthReviewNeeded',
            'vehiclesWithOperationalWarnings',
            'driversOnDuty',
            'averageFuelLevel',
            'dispatchQueue',
            'dashboardMapDispatches',
            'vehicles',
            'maintenanceAlerts',
            'weeklyActivity',
            'weeklyActivityMax',
            'recentActivity',
            'dashboardPermissions'
        );
    }

    public function index(Request $request)
    {
        return view(
            'dashboard.index',
            $this->buildDashboardData($request)
        );
    }

    public function data(Request $request)
    {
        /*
        |--------------------------------------------------------------------------
        | Reuse the exact same scoped Dashboard data
        |--------------------------------------------------------------------------
        |
        | This prevents the live endpoint from drifting away from the RBAC
        | rules already enforced by index().
        |--------------------------------------------------------------------------
        */

        $data =
            $this->buildDashboardData($request);

        $dispatchQueue =
            collect(
                $data['dispatchQueue'] ?? []
            )
                ->map(function ($dispatch) {
                    $reservation =
                        $dispatch->reservation;

                    $vehicle =
                        $reservation?->vehicle;

                    $driver =
                        $reservation?->driver;

                    return [
                        'id' =>
                            $dispatch->id,

                        'dispatch_number' =>
                            $dispatch->dispatch_number,

                        'status' =>
                            $dispatch->trip_status,

                        'title' =>
                            $reservation?->request_type_label
                            ?: $dispatch->dispatch_number,

                        'priority' =>
                            $reservation?->priority_label,

                        'vehicle' =>
                            $vehicle?->display_label
                            ?? 'No vehicle',

                        'driver' =>
                            trim(
                                ($driver?->first_name ?? '') .
                                ' ' .
                                ($driver?->last_name ?? '')
                            ),
                    ];
                })
                ->values();

        $vehicles =
            collect(
                $data['vehicles'] ?? []
            )
                ->map(function ($vehicle) {
                    $driver =
                        $vehicle->drivers
                            ->first();

                    $driverName =
                        $driver
                            ? trim(
                                ($driver->first_name ?? '') .
                                ' ' .
                                ($driver->last_name ?? '')
                            )
                            : 'Unassigned';

                    $vehicleLabel =
                        trim(
                            ($vehicle->brand ?? '') .
                            ' ' .
                            ($vehicle->model ?? '')
                        );

                    if ($vehicleLabel === '') {
                        $vehicleLabel =
                            $vehicle->vehicle_type
                            ?? 'Vehicle';
                    }

                    $fuelPercent = null;

                    if (
                        $vehicle->tank_capacity !== null &&
                        (float) $vehicle->tank_capacity > 0 &&
                        $vehicle->current_fuel !== null
                    ) {
                        $fuelPercent =
                            min(
                                100,
                                max(
                                    0,
                                    round(
                                        (
                                            (float) $vehicle->current_fuel
                                            /
                                            (float) $vehicle->tank_capacity
                                        ) * 100
                                    )
                                )
                            );
                    }

                    return [
                        'id' =>
                            $vehicle->id,

                        'label' =>
                            $vehicleLabel,

                        'vehicle_type' =>
                            $vehicle->vehicle_type,

                        'driver' =>
                            $driverName,

                        'status' =>
                            $vehicle->status,

                        'readiness' =>
                            $vehicle->readiness_label,

                        'fuel_percent' =>
                            $fuelPercent,
                    ];
                })
                ->values();

        $maintenanceAlerts =
            collect(
                $data['maintenanceAlerts'] ?? []
            )
                ->map(function ($maintenance) {
                    return [
                        'id' =>
                            $maintenance->id,

                        'maintenance_type' =>
                            $maintenance->maintenance_type,

                        'status' =>
                            $maintenance->status,

                        'priority' =>
                            $maintenance->priority,

                        'maintenance_date' =>
                            $maintenance->maintenance_date
                                ? Carbon::parse(
                                    $maintenance->maintenance_date
                                )->toDateString()
                                : null,

                        'vehicle' =>
                            $maintenance->vehicle
                                ?->display_label
                            ?? 'Vehicle',
                    ];
                })
                ->values();

        $recentActivity =
            collect(
                $data['recentActivity'] ?? []
            )
                ->map(function ($activity) {
                    return [
                        'id' =>
                            $activity->id,

                        'title' =>
                            $activity->title,

                        'link' =>
                            $activity->link,

                        'created_at_timestamp' =>
                            $activity->created_at
                                ?->getTimestamp(),
                    ];
                })
                ->values();

        return response()->json([
            'ready_vehicles' =>
                $data['readyVehicles'] ?? 0,

            'vehicles_needing_attention' =>
                $data['vehiclesNeedingAttention'] ?? 0,

            'emergency_requests' =>
                $data['emergencyRequests'] ?? 0,

            'active_dispatches' =>
                $data['activeDispatches'] ?? 0,

            'failed_pre_trip_checks' =>
                $data['failedPreTripChecks'] ?? 0,

            'maintenance_required_post_trip_checks' =>
                $data['maintenanceRequiredPostTripChecks'] ?? 0,

            'active_sanitation_records' =>
                $data['activeSanitationRecords'] ?? 0,

            'expired_compliance_items' =>
                $data['expiredComplianceItems'] ?? 0,

            'expiring_within_30_days' =>
                $data['expiringWithinThirtyDays'] ?? 0,

            'maintenance_due_or_overdue' =>
                $data['maintenanceDueOrOverdue'] ?? 0,

            'battery_health_review_needed' =>
                $data['batteryHealthReviewNeeded'] ?? 0,

            'vehicles_with_operational_warnings' =>
                $data['vehiclesWithOperationalWarnings'] ?? 0,

            'drivers_on_duty' =>
                $data['driversOnDuty'] ?? 0,

            'average_fuel_level' =>
                $data['averageFuelLevel'] ?? 0,

            'dispatch_queue' =>
                $dispatchQueue,

            'vehicles' =>
                $vehicles,

            'maintenance_alerts' =>
                $maintenanceAlerts,

            'weekly_activity' =>
                collect(
                    $data['weeklyActivity'] ?? []
                )->values(),

            'weekly_activity_max' =>
                $data['weeklyActivityMax'] ?? 1,

            'recent_activity' =>
                $recentActivity,

            'map_dispatches' =>
                collect(
                    $data['dashboardMapDispatches'] ?? []
                )->values(),
        ]);
    }

}
