<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\PostTripChecklist;
use App\Services\AuditLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PostTripChecklistController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dispatch_id' => ['required', 'integer', Rule::exists((new Dispatch)->getTable(), 'id')],
            'final_odometer' => ['nullable', 'numeric', 'min:0'],
            'fuel_level_remaining' => ['nullable', 'numeric', 'between:0,100'],
            'battery_level_remaining' => ['nullable', 'numeric', 'between:0,100'],
            'visible_damage' => ['nullable', 'boolean'],
            'tire_condition' => ['nullable', Rule::in(['good', 'attention_required'])],
            'lights_condition' => ['nullable', Rule::in(['good', 'attention_required'])],
            'brake_condition' => ['nullable', Rule::in(['good', 'attention_required'])],
            'issue_reported' => ['nullable', 'boolean'],
            'maintenance_required' => ['nullable', 'boolean'],
            'cleaning_required' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error on post-trip checklist.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $dispatch = Dispatch::query()
            ->with(['reservation.vehicle', 'reservation.driver', 'tripLog'])
            ->findOrFail($validated['dispatch_id']);

        $this->authorize('completePostTrip', $dispatch);

        if (!$dispatch->reservation) {
            return response()->json([
                'success' => false,
                'message' => 'This dispatch does not have a reservation.',
            ], 422);
        }

        if (!$dispatch->reservation->vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Assign a vehicle to the reservation before completing the post-trip checklist.',
            ], 422);
        }

        if ($dispatch->postTripChecklist()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A post-trip checklist has already been completed for this dispatch.',
            ], 409);
        }

        $authoritativeOdometer = $dispatch->tripLog?->end_odometer;
        $submittedOdometer = $validated['final_odometer'] ?? null;

        if (
            $authoritativeOdometer !== null
            && $submittedOdometer !== null
            && (float) $authoritativeOdometer !== (float) $submittedOdometer
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The final odometer must match the completed Trip Log odometer.',
                'errors' => [
                    'final_odometer' => ['Trip Log end odometer is the authoritative completed-trip value.'],
                ],
            ], 422);
        }

        $finalOdometer = $authoritativeOdometer ?? $submittedOdometer;
        $vehicle = $dispatch->reservation->vehicle;

        if (
            $finalOdometer !== null
            && $vehicle->current_odometer !== null
            && (float) $finalOdometer < (float) $vehicle->current_odometer
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Final odometer cannot be lower than the vehicle current odometer.',
                'errors' => ['final_odometer' => ['Final odometer cannot decrease.']],
            ], 422);
        }

        $checklist = DB::transaction(function () use (
            $request,
            $dispatch,
            $validated,
            $finalOdometer
        ): PostTripChecklist {
            $lockedDispatch = Dispatch::query()
                ->with(['reservation.vehicle', 'reservation.driver', 'tripLog'])
                ->lockForUpdate()
                ->findOrFail($dispatch->id);

            if ($lockedDispatch->postTripChecklist()->exists()) {
                abort(409, 'A post-trip checklist has already been completed for this dispatch.');
            }

            $reservation = $lockedDispatch->reservation;
            $vehicle = $reservation->vehicle;

            $checklist = PostTripChecklist::create([
                'dispatch_id' => $lockedDispatch->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $reservation->driver_id,
                'submitted_by' => $request->user()->id,
                'final_odometer' => $finalOdometer,
                'fuel_level_remaining' => $validated['fuel_level_remaining'] ?? null,
                'battery_level_remaining' => $validated['battery_level_remaining'] ?? null,
                'visible_damage' => (bool) ($validated['visible_damage'] ?? false),
                'tire_condition' => $validated['tire_condition'] ?? null,
                'lights_condition' => $validated['lights_condition'] ?? null,
                'brake_condition' => $validated['brake_condition'] ?? null,
                'issue_reported' => (bool) ($validated['issue_reported'] ?? false),
                'maintenance_required' => (bool) ($validated['maintenance_required'] ?? false),
                'cleaning_required' => (bool) ($validated['cleaning_required'] ?? false),
                'remarks' => $validated['remarks'] ?? null,
                'submitted_at' => now(),
            ]);

            if ($finalOdometer !== null && (float) $finalOdometer > (float) ($vehicle->current_odometer ?? 0)) {
                $vehicle->current_odometer = $finalOdometer;
            }

            if ($checklist->fuel_level_remaining !== null && $vehicle->supportsLiquidFuel() && (float) $vehicle->tank_capacity > 0) {
                $vehicle->current_fuel = round(
                    ((float) $checklist->fuel_level_remaining / 100) * (float) $vehicle->tank_capacity,
                    2
                );
            }

            if (
                $checklist->battery_level_remaining !== null
                && in_array($vehicle->powertrain_type, ['hybrid', 'plug_in_hybrid', 'electric'], true)
            ) {
                $vehicle->battery_level_percent = $checklist->battery_level_remaining;
            }

            $vehicle->save();

            if ($checklist->maintenance_required) {
                $vehicle->applyReadinessWithPrecedence('maintenance');
            } elseif ($checklist->cleaning_required) {
                $vehicle->applyReadinessWithPrecedence('cleaning');
            } elseif ($vehicle->canReturnToReady(allowInUse: true)) {
                $vehicle->update(['readiness_status' => 'ready']);
            }

            AuditLogService::log(
                module: 'Dispatch Management',
                action: 'Post-Trip Checklist Completed',
                description: "Post-trip checklist completed for dispatch {$lockedDispatch->dispatch_number}.",
                record: $checklist,
                newValues: $checklist->only([
                    'dispatch_id',
                    'vehicle_id',
                    'driver_id',
                    'final_odometer',
                    'fuel_level_remaining',
                    'battery_level_remaining',
                    'maintenance_required',
                    'cleaning_required',
                    'submitted_at',
                ])
            );

            return $checklist;
        });

        return response()->json([
            'success' => true,
            'message' => 'Post-trip checklist submitted successfully.',
            'checklist' => $checklist->load(['submittedBy', 'vehicle', 'driver']),
            'actions' => [
                'create_maintenance_request' => $checklist->maintenance_required,
                'create_sanitation_record' => $checklist->cleaning_required,
            ],
        ], 201);
    }

    public function show(Request $request, PostTripChecklist $postTripChecklist): JsonResponse
    {
        $postTripChecklist->load(['submittedBy', 'vehicle', 'driver', 'dispatch.reservation', 'dispatch.tripLog']);
        $this->authorize('view', $postTripChecklist->dispatch);

        return response()->json([
            'success' => true,
            'checklist' => $postTripChecklist,
            'odometer_source' => $postTripChecklist->dispatch->tripLog?->end_odometer !== null
                ? 'trip_log'
                : 'post_trip_snapshot',
        ]);
    }
}
