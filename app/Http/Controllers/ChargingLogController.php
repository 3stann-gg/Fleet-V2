<?php

namespace App\Http\Controllers;

use App\Models\ChargingLog;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class ChargingLogController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChargingLog::class);

        $query = ChargingLog::query()
            ->with([
                'vehicle:id,plate_number,brand,model,vehicle_type,powertrain_type',
                'driver:id,first_name,last_name,driver_number',
            ])
            ->orderByDesc('charging_date')
            ->orderByDesc('charging_time')
            ->orderByDesc('id');

        if ($request->user()->hasRole('driver')) {
            $driverId = $request->user()->driverProfile?->id;

            $driverId
                ? $query->where('driver_id', $driverId)
                : $query->whereRaw('1 = 0');
        }

        return response()->json([
            'chargingLogs' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ChargingLog::class);

        $isDriver = $request->user()->hasRole('driver');
        $validator = Validator::make($request->all(), [
            'vehicle_id' => $isDriver
                ? ['nullable', 'exists:vehicles,id']
                : ['required', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'exists:drivers,id'],
            'charging_date' => ['required', 'date'],
            'charging_time' => ['nullable', 'date_format:H:i'],
            'battery_before_percent' => ['required', 'numeric', 'between:0,100'],
            'battery_after_percent' => [
                'required',
                'numeric',
                'between:0,100',
                'gte:battery_before_percent',
            ],
            'energy_added_kwh' => ['required', 'numeric', 'min:0'],
            'charging_location' => ['required', 'string', 'max:255'],
            'charging_cost' => ['required', 'numeric', 'min:0'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the charging transaction information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $chargingLog = DB::transaction(function () use ($request, $validator): ChargingLog {
                $validated = $validator->validated();

                if ($request->user()->hasRole('driver')) {
                    $driver = $request->user()->driverProfile;

                    if (! $driver?->assigned_vehicle_id) {
                        throw new RuntimeException('You do not have an assigned vehicle.');
                    }

                    $validated['driver_id'] = $driver->id;
                    $validated['vehicle_id'] = $driver->assigned_vehicle_id;
                }

                $vehicle = Vehicle::query()
                    ->lockForUpdate()
                    ->findOrFail($validated['vehicle_id']);

                if (! $vehicle->supportsExternalCharging()) {
                    throw new RuntimeException(
                        'Only plug-in hybrid and electric vehicles can receive charging transactions.'
                    );
                }

                if (
                    isset($validated['odometer'])
                    && $validated['odometer'] !== null
                    && (float) $validated['odometer'] < (float) $vehicle->current_odometer
                ) {
                    throw new RuntimeException(
                        'Odometer reading cannot be lower than the vehicle\'s current mileage.'
                    );
                }

                $chargingLog = ChargingLog::create($validated);
                $vehicleUpdate = [
                    'battery_level_percent' => $validated['battery_after_percent'],
                ];

                if (isset($validated['odometer']) && $validated['odometer'] !== null) {
                    $vehicleUpdate['current_odometer'] = $validated['odometer'];
                }

                $vehicle->update($vehicleUpdate);
                $chargingLog->load(['vehicle', 'driver']);

                AuditLogService::log(
                    module: 'Fuel Management',
                    action: 'Created',
                    description: "Created charging transaction #{$chargingLog->id}.",
                    record: $chargingLog,
                    newValues: $chargingLog->only([
                        'vehicle_id',
                        'driver_id',
                        'charging_date',
                        'charging_time',
                        'battery_before_percent',
                        'battery_after_percent',
                        'energy_added_kwh',
                        'charging_location',
                        'charging_cost',
                        'odometer',
                    ])
                );

                return $chargingLog;
            });

            return response()->json([
                'success' => true,
                'message' => 'Charging transaction recorded successfully.',
                'chargingLog' => $chargingLog,
            ], 201);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to record the charging transaction.',
            ], 500);
        }
    }

    public function show(ChargingLog $chargingLog): JsonResponse
    {
        $this->authorize('view', $chargingLog);

        return response()->json([
            'chargingLog' => $chargingLog->load(['vehicle', 'driver']),
        ]);
    }
}
