<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\SanitationLog;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SanitationLogController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse|View
    {
        Gate::authorize('sanitation.view');

        $query = SanitationLog::query()->with([
            'vehicle',
            'dispatch.reservation',
            'performedBy',
        ]);

        if ($request->user()->hasRole('driver')) {
            $vehicleId = $request->user()->driverProfile?->assigned_vehicle_id;
            $vehicleId
                ? $query->where('vehicle_id', $vehicleId)
                : $query->whereRaw('1 = 0');
        }

        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->integer('vehicle_id'));
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $sanitationLogs = $query->latest('id')->paginate(15)->withQueryString();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'sanitation_logs' => $sanitationLogs,
            ]);
        }

        return view('sanitation.index', compact('sanitationLogs'));
    }

    public function show(Request $request, SanitationLog $sanitationLog): JsonResponse
    {
        Gate::authorize('sanitation.view');
        $this->authorize('view', $sanitationLog->vehicle);

        return response()->json([
            'success' => true,
            'sanitation' => $sanitationLog->load([
                'vehicle',
                'dispatch.reservation',
                'performedBy',
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('sanitation.create');

        $validator = Validator::make($request->all(), [
            'vehicle_id' => ['required', 'integer', Rule::exists((new Vehicle)->getTable(), 'id')],
            'dispatch_id' => ['nullable', 'integer', 'exists:dispatch,id'],
            'reason' => ['required', Rule::in(array_keys(SanitationLog::REASON_LABELS))],
            'cleaning_type' => ['required', Rule::in(array_keys(SanitationLog::CLEANING_TYPE_LABELS))],
            'status' => ['prohibited'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error on sanitation record.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $vehicle = Vehicle::query()->findOrFail($validated['vehicle_id']);
        $this->authorize('view', $vehicle);

        if ($request->user()->hasRole('driver')) {
            abort_unless(
                (int) $request->user()->driverProfile?->assigned_vehicle_id === (int) $vehicle->id,
                403
            );
        }

        if (! empty($validated['dispatch_id'])) {
            $dispatchMatchesVehicle = Dispatch::query()
                ->whereKey($validated['dispatch_id'])
                ->whereHas(
                    'reservation',
                    fn ($query) => $query->where('vehicle_id', $vehicle->id)
                )
                ->when(
                    $request->user()->hasRole('driver'),
                    fn ($query) => $query->whereHas(
                        'reservation',
                        fn ($reservation) => $reservation->where(
                            'driver_id',
                            $request->user()->driverProfile?->id
                        )
                    )
                )
                ->exists();

            abort_unless($dispatchMatchesVehicle, 403);
        }

        $validated['status'] = 'pending';

        $sanitationLog = DB::transaction(function () use ($request, $validated, $vehicle): SanitationLog {
            $lockedVehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            $status = $validated['status'];

            $sanitationLog = SanitationLog::create([
                'vehicle_id' => $lockedVehicle->id,
                'dispatch_id' => $validated['dispatch_id'] ?? null,
                'reason' => $validated['reason'],
                'cleaning_type' => $validated['cleaning_type'],
                'status' => $status,
                'started_at' => $status === 'in_progress' ? now() : null,
                'completed_at' => $status === 'completed' ? now() : null,
                'performed_by' => $status === 'pending' ? null : $request->user()->id,
                'remarks' => $validated['remarks'] ?? null,
            ]);

            $this->synchronizeVehicleReadiness($lockedVehicle, $sanitationLog);

            AuditLogService::log(
                module: 'Vehicle Management',
                action: 'Sanitation Record Created',
                description: "Sanitation record #{$sanitationLog->id} created for {$lockedVehicle->display_label}.",
                record: $sanitationLog,
                newValues: $sanitationLog->only([
                    'vehicle_id',
                    'dispatch_id',
                    'reason',
                    'cleaning_type',
                    'status',
                    'started_at',
                    'completed_at',
                    'performed_by',
                    'remarks',
                ])
            );

            return $sanitationLog;
        });

        return response()->json([
            'success' => true,
            'message' => 'Sanitation record created successfully.',
            'sanitation' => $sanitationLog->load(['vehicle', 'dispatch', 'performedBy']),
        ], 201);
    }

    public function update(Request $request, SanitationLog $sanitationLog): JsonResponse
    {
        Gate::authorize('sanitation.update');

        $validator = Validator::make($request->all(), [
            'status' => ['prohibited'],
            'cleaning_type' => ['sometimes', Rule::in(array_keys(SanitationLog::CLEANING_TYPE_LABELS))],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error on sanitation record update.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->authorize('view', $sanitationLog->vehicle);
        $validated = $validator->validated();

        DB::transaction(function () use ($sanitationLog, $validated): void {
            $sanitationLog = SanitationLog::query()
                ->lockForUpdate()
                ->findOrFail($sanitationLog->id);
            $oldValues = $sanitationLog->only([
                'status',
                'cleaning_type',
                'started_at',
                'completed_at',
                'performed_by',
                'remarks',
            ]);

            $sanitationLog->fill([
                'cleaning_type' => $validated['cleaning_type'] ?? $sanitationLog->cleaning_type,
                'remarks' => array_key_exists('remarks', $validated)
                    ? $validated['remarks']
                    : $sanitationLog->remarks,
            ]);

            $sanitationLog->save();
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($sanitationLog->vehicle_id);
            $this->synchronizeVehicleReadiness($vehicle, $sanitationLog);

            AuditLogService::log(
                module: 'Vehicle Management',
                action: 'Sanitation Status Updated',
                description: "Sanitation record #{$sanitationLog->id} updated to {$sanitationLog->status_label}.",
                record: $sanitationLog,
                oldValues: $oldValues,
                newValues: $sanitationLog->only(array_keys($oldValues))
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Sanitation record updated successfully.',
            'sanitation' => $sanitationLog->fresh()->load(['vehicle', 'dispatch', 'performedBy']),
        ]);
    }

    public function transition(Request $request, SanitationLog $sanitationLog): JsonResponse
    {
        Gate::authorize('sanitation.update');
        $this->authorize('view', $sanitationLog->vehicle);

        if (array_diff(array_keys($request->all()), ['status']) !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Sanitation transition requests may only contain status.',
            ], 422);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(SanitationLog::STATUS_LABELS))],
        ]);

        try {
            DB::transaction(function () use ($request, $sanitationLog, $validated): void {
                $sanitationLog = SanitationLog::query()
                    ->lockForUpdate()
                    ->findOrFail($sanitationLog->id);
                $currentStatus = $sanitationLog->status;
                $newStatus = $validated['status'];

                if (! in_array($newStatus, SanitationLog::TRANSITIONS[$currentStatus] ?? [], true)) {
                    throw new \Exception(
                        "Cannot change sanitation status from {$currentStatus} to {$newStatus}."
                    );
                }

                $oldValues = $sanitationLog->only([
                    'status',
                    'started_at',
                    'completed_at',
                    'performed_by',
                ]);

                $sanitationLog->status = $newStatus;
                $sanitationLog->started_at ??= now();
                $sanitationLog->performed_by = $request->user()->id;
                $sanitationLog->completed_at = $newStatus === 'completed'
                    ? now()
                    : null;
                $sanitationLog->save();

                $vehicle = Vehicle::query()
                    ->lockForUpdate()
                    ->findOrFail($sanitationLog->vehicle_id);
                $this->synchronizeVehicleReadiness($vehicle, $sanitationLog);

                AuditLogService::log(
                    module: 'Vehicle Management',
                    action: 'Sanitation Status Updated',
                    description: "Sanitation record #{$sanitationLog->id} updated to {$sanitationLog->status_label}.",
                    record: $sanitationLog,
                    oldValues: $oldValues,
                    newValues: $sanitationLog->only(array_keys($oldValues))
                );
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Sanitation status updated successfully.',
            'sanitation' => $sanitationLog->fresh()->load(['vehicle', 'dispatch', 'performedBy']),
        ]);
    }

    private function synchronizeVehicleReadiness(Vehicle $vehicle, SanitationLog $sanitationLog): void
    {
        if (in_array($sanitationLog->status, ['pending', 'in_progress'], true)) {
            $vehicle->applyReadinessWithPrecedence('cleaning');

            return;
        }

        $vehicle->refresh();

        if ($vehicle->canReturnToReady()) {
            $vehicle->update(['readiness_status' => 'ready']);
        }
    }
}
