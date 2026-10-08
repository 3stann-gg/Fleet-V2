<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Dispatch;
use Illuminate\Http\Request;

class FleetSearchController extends Controller
{
    public function search(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user !== null,
            401
        );

        $search = trim(
            (string) $request->get(
                'q',
                ''
            )
        );

        if ($search === '') {
            return response()->json([
                'results' => [],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Protect search input size
        |--------------------------------------------------------------------------
        */
        $search = mb_substr(
            $search,
            0,
            100
        );

        $results = collect();

        $department =
            $user->department;

        $driverProfile =
            $user->driverProfile;

        /*
        |--------------------------------------------------------------------------
        | Vehicles
        |--------------------------------------------------------------------------
        */
        if (
            $user->canViewModule(
                'vehicles'
            )
        ) {
            $vehicleQuery =
                Vehicle::query();

            /*
            |--------------------------------------------------------------------------
            | Driver - assigned vehicle only
            |--------------------------------------------------------------------------
            */
            if (
                $user->hasRole(
                    'driver'
                )
            ) {
                $vehicleId =
                    $driverProfile
                        ?->assigned_vehicle_id;

                if ($vehicleId) {
                    $vehicleQuery->where(
                        'id',
                        $vehicleId
                    );
                } else {
                    $vehicleQuery
                        ->whereRaw('1 = 0');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Department Head - own department only
            |--------------------------------------------------------------------------
            */
            elseif (
                $user->hasRole(
                    'department_head'
                )
            ) {
                if ($department) {
                    $vehicleQuery->where(
                        'department',
                        $department
                    );
                } else {
                    $vehicleQuery
                        ->whereRaw('1 = 0');
                }
            }

            $vehicles =
                $vehicleQuery
                    ->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'plate_number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'brand',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'model',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    )
                    ->limit(5)
                    ->get();

            foreach (
                $vehicles as $vehicle
            ) {
                $vehicleName =
                    trim(
                        ($vehicle->brand ?? '') .
                        ' ' .
                        ($vehicle->model ?? '')
                    );

                $results->push([
                    'type' =>
                        'Vehicle',

                    'module' =>
                        'vehicles',

                    'label' =>
                        $vehicleName !== ''
                            ? $vehicleName
                            : 'Vehicle',

                    'detail' =>
                        $vehicle->plate_number
                        ?? '',

                    'url' =>
                        route('fleet'),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Drivers
        |--------------------------------------------------------------------------
        */
        if (
            $user->canViewModule(
                'drivers'
            )
        ) {
            $driverQuery =
                Driver::query();

            /*
            |--------------------------------------------------------------------------
            | Driver role
            |--------------------------------------------------------------------------
            |
            | Driver can access the Drivers module in View mode,
            | but Global Search should not expose unrelated personnel records.
            |--------------------------------------------------------------------------
            */
            if (
                $user->hasRole(
                    'driver'
                )
            ) {
                if ($driverProfile) {
                    $driverQuery->where(
                        'id',
                        $driverProfile->id
                    );
                } else {
                    $driverQuery
                        ->whereRaw('1 = 0');
                }
            }

            $drivers =
                $driverQuery
                    ->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'driver_number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'first_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'last_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'license_number',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    )
                    ->limit(5)
                    ->get();

            foreach (
                $drivers as $driver
            ) {
                $driverName =
                    trim(
                        ($driver->first_name ?? '') .
                        ' ' .
                        ($driver->last_name ?? '')
                    );

                $results->push([
                    'type' =>
                        'Driver',

                    'module' =>
                        'drivers',

                    'label' =>
                        $driverName !== ''
                            ? $driverName
                            : 'Driver',

                    'detail' =>
                        trim(
                            ($driver->driver_number ?? '') .
                            (
                                $driver->license_number
                                    ? ' · ' .
                                        $driver->license_number
                                    : ''
                            )
                        ),

                    'url' =>
                        route('driver'),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Reservations
        |--------------------------------------------------------------------------
        */
        if (
            $user->canViewModule(
                'reservations'
            )
        ) {
            $reservationQuery =
                Reservation::query();

            /*
            |--------------------------------------------------------------------------
            | Driver - own assignments only
            |--------------------------------------------------------------------------
            */
            if (
                $user->hasRole(
                    'driver'
                )
            ) {
                $driverId =
                    $driverProfile?->id;

                if ($driverId) {
                    $reservationQuery
                        ->where(
                            'driver_id',
                            $driverId
                        );
                } else {
                    $reservationQuery
                        ->whereRaw('1 = 0');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Department Head - own department only
            |--------------------------------------------------------------------------
            */
            elseif (
                $user->hasRole(
                    'department_head'
                )
            ) {
                if (!$department) {
                    $reservationQuery
                        ->whereRaw('1 = 0');
                } else {
                    $reservationQuery->where('department', $department);
                }
            }

            $reservations =
                $reservationQuery
                    ->where(
                        function ($query) use ($search) {
                            $query
                                ->where(
                                    'reservation_number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'patient_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'pickup_location',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'destination',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    )
                    ->limit(5)
                    ->get();

            foreach (
                $reservations as $reservation
            ) {
                $results->push([
                    'type' =>
                        'Reservation',

                    'module' =>
                        'reservations',

                    'label' =>
                        $reservation
                            ->reservation_number,

                    'detail' =>
                        $reservation
                            ->patient_name
                        ??
                        $reservation->status
                        ??
                        '',

                    'url' =>
                        route(
                            'reservation.index'
                        ),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Dispatch
        |--------------------------------------------------------------------------
        */
        if (
            $user->canViewModule(
                'dispatch'
            )
        ) {
            $dispatchQuery =
                Dispatch::query();

            /*
            |--------------------------------------------------------------------------
            | Driver - own dispatch assignments only
            |--------------------------------------------------------------------------
            */
            if (
                $user->hasRole(
                    'driver'
                )
            ) {
                $driverId =
                    $driverProfile?->id;

                if ($driverId) {
                    $dispatchQuery
                        ->whereHas(
                            'reservation',
                            function ($reservationQuery) use ($driverId) {
                                $reservationQuery
                                    ->where(
                                        'driver_id',
                                        $driverId
                                    );
                            }
                        );
                } else {
                    $dispatchQuery
                        ->whereRaw('1 = 0');
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Department Head - own department only
            |--------------------------------------------------------------------------
            */
            elseif (
                $user->hasRole(
                    'department_head'
                )
            ) {
                if (!$department) {
                    $dispatchQuery
                        ->whereRaw('1 = 0');
                } else {
                    $dispatchQuery
                        ->whereHas(
                            'reservation',
                            function ($reservationQuery) use ($department) {
                                $reservationQuery->where('department', $department);
                            }
                        );
                }
            }

            $dispatches =
                $dispatchQuery
                    ->where(
                        'dispatch_number',
                        'like',
                        "%{$search}%"
                    )
                    ->limit(5)
                    ->get();

            foreach (
                $dispatches as $dispatch
            ) {
                $results->push([
                    'type' =>
                        'Dispatch',

                    'module' =>
                        'dispatch',

                    'label' =>
                        $dispatch
                            ->dispatch_number,

                    'detail' =>
                        $dispatch
                            ->trip_status
                        ?? '',

                    'url' =>
                        route('dispatch'),
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Final Results
        |--------------------------------------------------------------------------
        */
        return response()->json([
            'results' =>
                $results
                    ->take(8)
                    ->values(),
        ]);
    }
}
