<?php

namespace App\Http\Controllers;

use App\Models\ChargingLog;
use App\Models\Dispatch;
use App\Models\Driver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DriverPerformanceController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()?->canViewModule('driver_performance'), 403);

        return view('driver-performance.index');
    }

    public function data(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user?->canViewModule('driver_performance'), 403);

        $validated = $request->validate([
            'date_range' => [
                'nullable',
                Rule::in(['today', 'last7', 'last30', 'thisMonth', 'thisYear', 'all']),
            ],
        ]);

        $dateRange = $validated['date_range'] ?? 'last30';
        [$startDate, $endDate] = $this->resolveDateRange($dateRange);

        $driversQuery = Driver::query()
            ->select([
                'id',
                'user_id',
                'driver_number',
                'first_name',
                'last_name',
                'license_number',
                'license_class',
                'license_expiry',
                'photo',
                'assigned_vehicle_id',
                'status',
            ])
            ->with(['vehicle:id,plate_number,brand,model,vehicle_type'])
            ->whereNull('archived_at')
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($user->hasRole('driver')) {
            $driversQuery->where('user_id', $user->id);
        }

        $drivers = $driversQuery->get();
        $driverIds = $drivers->pluck('id');
        $dispatchesByDriver = collect();
        $electricityByDriver = collect();

        if ($driverIds->isNotEmpty()) {
            $dispatches = Dispatch::query()
                ->select([
                    'id',
                    'dispatch_number',
                    'reservation_id',
                    'dispatch_date',
                    'departure_time',
                    'arrival_time',
                    'trip_status',
                ])
                ->with([
                    'reservation:id,driver_id,vehicle_id,pickup_location,destination',
                    'reservation.vehicle:id,plate_number,brand,model,vehicle_type',
                    'tripLog:id,dispatch_id,distance,fuel_used',
                ])
                ->whereHas(
                    'reservation',
                    fn ($query) => $query->whereIn('driver_id', $driverIds)
                )
                ->when(
                    $startDate && $endDate,
                    fn ($query) => $query->whereBetween(
                        'dispatch_date',
                        [$startDate->toDateString(), $endDate->toDateString()]
                    )
                )
                ->orderByDesc('dispatch_date')
                ->orderByDesc('id')
                ->get();

            $dispatchesByDriver = $dispatches->groupBy(
                fn (Dispatch $dispatch) => (string) $dispatch->reservation?->driver_id
            );

            $electricityByDriver = ChargingLog::query()
                ->selectRaw('driver_id, SUM(energy_added_kwh) as electricity_used')
                ->whereIn('driver_id', $driverIds)
                ->when(
                    $startDate && $endDate,
                    fn ($query) => $query->whereBetween(
                        'charging_date',
                        [$startDate->toDateString(), $endDate->toDateString()]
                    )
                )
                ->groupBy('driver_id')
                ->pluck('electricity_used', 'driver_id');
        }

        $records = $drivers
            ->map(function (Driver $driver) use ($dispatchesByDriver, $electricityByDriver): array {
                $dispatches = $dispatchesByDriver->get((string) $driver->id, collect());
                $completedTrips = $dispatches->where('trip_status', 'Completed')->count();
                $cancelledTrips = $dispatches->where('trip_status', 'Cancelled')->count();
                $totalDistance = $dispatches->sum(
                    fn (Dispatch $dispatch): float => (float) ($dispatch->tripLog?->distance ?? 0)
                );
                $fuelUsed = $dispatches->sum(
                    fn (Dispatch $dispatch): float => (float) ($dispatch->tripLog?->fuel_used ?? 0)
                );
                $fullName = Str::squish("{$driver->first_name} {$driver->last_name}");

                return [
                    'id' => $driver->id,
                    'driver_number' => $driver->driver_number,
                    'name' => $fullName,
                    'initials' => Str::upper(
                        Str::substr((string) $driver->first_name, 0, 1).
                        Str::substr((string) $driver->last_name, 0, 1)
                    ),
                    'photo_url' => $driver->photo
                        ? asset('storage/'.Str::of($driver->photo)->trim('/'))
                        : null,
                    'status' => $driver->status,
                    'assigned_vehicle' => $driver->vehicle?->display_label,
                    'assigned_vehicle_id' => $driver->assigned_vehicle_id,
                    'license_number' => $driver->license_number,
                    'license_class' => $driver->license_class,
                    'license_expiry' => $driver->license_expiry?->format('Y-m-d'),
                    'completed_trips' => $completedTrips,
                    'cancelled_trips' => $cancelledTrips,
                    'total_distance' => round($totalDistance, 2),
                    'fuel_used' => round($fuelUsed, 2),
                    'electricity_used' => round(
                        (float) ($electricityByDriver->get($driver->id) ?? 0),
                        2
                    ),
                    'performance_level' => $this->performanceLevel(
                        $completedTrips,
                        $cancelledTrips
                    ),
                    'recent_trips' => $dispatches
                        ->take(5)
                        ->map(fn (Dispatch $dispatch): array => [
                            'dispatch_number' => $dispatch->dispatch_number,
                            'date' => $dispatch->dispatch_date,
                            'status' => $dispatch->trip_status,
                            'vehicle' => $dispatch->reservation?->vehicle?->display_label,
                            'origin' => $dispatch->reservation?->pickup_location,
                            'destination' => $dispatch->reservation?->destination,
                            'distance' => (float) ($dispatch->tripLog?->distance ?? 0),
                            'fuel_used' => (float) ($dispatch->tripLog?->fuel_used ?? 0),
                        ])
                        ->values(),
                ];
            })
            ->sortBy([
                ['completed_trips', 'desc'],
                ['cancelled_trips', 'asc'],
                ['name', 'asc'],
            ])
            ->values();

        return response()->json([
            'records' => $records,
            'date_range' => $dateRange,
        ]);
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateRange(string $dateRange): array
    {
        $today = now()->startOfDay();

        return match ($dateRange) {
            'today' => [$today->copy(), $today->copy()],
            'last7' => [$today->copy()->subDays(6), $today->copy()],
            'thisMonth' => [$today->copy()->startOfMonth(), $today->copy()],
            'thisYear' => [$today->copy()->startOfYear(), $today->copy()],
            'all' => [null, null],
            default => [$today->copy()->subDays(29), $today->copy()],
        };
    }

    private function performanceLevel(int $completedTrips, int $cancelledTrips): string
    {
        $outcomeCount = $completedTrips + $cancelledTrips;

        if ($outcomeCount === 0) {
            return 'Low';
        }

        $completionRate = $completedTrips / $outcomeCount;

        if ($completionRate >= 0.8) {
            return 'High';
        }

        if ($completionRate >= 0.5) {
            return 'Moderate';
        }

        return 'Low';
    }
}
