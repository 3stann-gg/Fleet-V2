<?php

namespace App\Http\Controllers;

use App\Models\Dispatch;
use App\Models\PreTripChecklist;
use App\Services\AuditLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PreTripChecklistController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dispatch_id' => ['required', 'integer', Rule::exists((new Dispatch)->getTable(), 'id')],
            'result' => ['required', Rule::in(['passed', 'passed_with_remarks', 'failed'])],
            'remarks' => [
                Rule::requiredIf(in_array($request->input('result'), ['passed_with_remarks', 'failed'], true)),
                'nullable',
                'string',
                'max:1000',
            ],
            'checklist_data' => [
                'nullable',
                'array:tires,brakes,lights,horn,mirrors,energy_level_checked,sufficient_energy,documents_available,vehicle_clean,required_equipment_present,communication_equipment_operational,stretcher,wheelchair_support,oxygen_equipment,emergency_kit,headlight,tail_light,turn_signals,helmet_available,cargo_box_secure,specimen_storage_available,medical_supply_storage_available,cargo_secured',
            ],
            'checklist_data.*' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error on pre-trip checklist.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $dispatch = Dispatch::query()
            ->with(['reservation.vehicle', 'reservation.driver'])
            ->findOrFail($validated['dispatch_id']);

        $this->authorize('completePreTrip', $dispatch);

        if (!$dispatch->reservation) {
            return response()->json([
                'success' => false,
                'message' => 'This dispatch does not have a reservation.',
            ], 422);
        }

        if (!$dispatch->reservation->vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Assign a vehicle to the reservation before completing the pre-trip checklist.',
            ], 422);
        }

        if ($dispatch->preTripChecklist()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'A pre-trip checklist has already been completed for this dispatch.',
            ], 409);
        }

        $checklist = DB::transaction(function () use ($request, $dispatch, $validated): PreTripChecklist {
            $lockedDispatch = Dispatch::query()
                ->with(['reservation.vehicle', 'reservation.driver'])
                ->lockForUpdate()
                ->findOrFail($dispatch->id);

            if ($lockedDispatch->preTripChecklist()->exists()) {
                abort(409, 'A pre-trip checklist has already been completed for this dispatch.');
            }

            $reservation = $lockedDispatch->reservation;
            $vehicle = $reservation->vehicle;

            $checklist = PreTripChecklist::create([
                'dispatch_id' => $lockedDispatch->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $reservation->driver_id,
                'checked_by' => $request->user()->id,
                'result' => $validated['result'],
                'remarks' => $validated['remarks'] ?? null,
                'checklist_data' => $validated['checklist_data'] ?? [],
                'checked_at' => now(),
            ]);

            if ($checklist->result === 'failed') {
                $vehicle->applyReadinessWithPrecedence('unavailable');
            }

            AuditLogService::log(
                module: 'Dispatch Management',
                action: 'Pre-Trip Checklist Completed',
                description: "Pre-trip checklist completed with result {$checklist->result_label} for dispatch {$lockedDispatch->dispatch_number}.",
                record: $checklist,
                newValues: $checklist->only(['dispatch_id', 'vehicle_id', 'driver_id', 'result', 'remarks', 'checklist_data', 'checked_at'])
            );

            return $checklist;
        });

        return response()->json([
            'success' => true,
            'message' => $checklist->result === 'failed'
                ? 'Pre-trip checklist recorded. The failed result requires operational review before dispatch.'
                : 'Pre-trip checklist completed successfully.',
            'checklist' => $checklist->load(['checkedBy', 'vehicle', 'driver']),
        ], 201);
    }

    public function show(Request $request, PreTripChecklist $preTripChecklist): JsonResponse
    {
        $preTripChecklist->load(['checkedBy', 'vehicle', 'driver', 'dispatch.reservation']);
        $this->authorize('view', $preTripChecklist->dispatch);

        return response()->json([
            'success' => true,
            'checklist' => $preTripChecklist,
        ]);
    }
}
