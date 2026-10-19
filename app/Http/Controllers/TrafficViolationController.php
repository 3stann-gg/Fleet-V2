<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewTrafficViolationRequest;
use App\Http\Requests\SettleTrafficViolationRequest;
use App\Http\Requests\StoreTrafficViolationRequest;
use App\Http\Requests\UpdateTrafficViolationRequest;
use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\TrafficViolation;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TrafficViolationController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', TrafficViolation::class);

        $query = $this->visibleQuery($request);
        $this->applyFilters($query, $request);

        $trafficViolations = $query->latest('violation_date')->latest('id')->paginate(15)->withQueryString();
        $overviewQuery = $this->visibleQuery($request)->whereNull('archived_at');
        $overview = [
            'total' => (clone $overviewQuery)->count(),
            'under_review' => (clone $overviewQuery)->whereIn('status', ['reported', 'under_review', 'contested'])->count(),
            'for_settlement' => (clone $overviewQuery)->where('status', 'for_settlement')->count(),
            'unpaid' => (clone $overviewQuery)->whereIn('payment_status', ['unpaid', 'partially_paid'])->count(),
            'closed_settled' => (clone $overviewQuery)->whereIn('status', ['closed', 'settled'])->count(),
        ];

        $canMutateOperationalDetails = $request->user()->hasPermission('traffic_violations.create')
            || $request->user()->hasPermission('traffic_violations.update');
        $drivers = $canMutateOperationalDetails
            ? Driver::query()->select(['id', 'first_name', 'last_name', 'license_expiry', 'clutch_authorization'])
                ->with(['licenseCodes:id,driver_id,code', 'qualifications:id,driver_id,vehicle_type'])
                ->whereNull('archived_at')->orderBy('last_name')->orderBy('first_name')->get()
            : collect();
        $vehicles = $canMutateOperationalDetails
            ? Vehicle::query()->select(['id', 'plate_number', 'vehicle_type', 'lto_vehicle_category', 'registration_expiry', 'transmission_type'])
                ->whereNull('archived_at')->orderBy('plate_number')->get()
            : collect();
        $dispatches = $canMutateOperationalDetails
            ? Dispatch::query()->select(['id', 'dispatch_number', 'reservation_id', 'dispatch_date'])
                ->with(['reservation:id,vehicle_id,driver_id', 'tripLog:id,dispatch_id'])
                ->whereNull('archived_at')->latest('dispatch_date')->limit(250)->get()
            : collect();

        return view('traffic-violations.index', [
            'trafficViolations' => $trafficViolations,
            'overview' => $overview,
            'drivers' => $drivers,
            'vehicles' => $vehicles,
            'dispatches' => $dispatches,
            'typeLabels' => TrafficViolation::TYPE_LABELS,
            'responsibilityLabels' => TrafficViolation::RESPONSIBILITY_LABELS,
            'statusLabels' => TrafficViolation::STATUS_LABELS,
            'paymentStatusLabels' => TrafficViolation::PAYMENT_STATUS_LABELS,
        ]);
    }

    public function show(Request $request, TrafficViolation $trafficViolation): JsonResponse
    {
        $this->authorize('view', $trafficViolation);

        $trafficViolation->load($this->relations());

        if (! $request->user()->hasPermission('traffic_violations.settlement')
            && ! $request->user()->hasPermission('traffic_violations.review')) {
            $trafficViolation->makeHidden([
                'fine_amount', 'due_date', 'payment_status', 'payment_status_label',
                'payment_date', 'payment_reference', 'settlement_notes',
            ]);
        }

        return response()->json(['traffic_violation' => $trafficViolation]);
    }

    public function store(StoreTrafficViolationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $trafficViolation = DB::transaction(function () use ($request, $validated): TrafficViolation {
            $trafficViolation = TrafficViolation::create([
                ...$validated,
                'responsibility' => 'under_review',
                'status' => 'reported',
                'payment_status' => 'not_applicable',
                'reported_by' => $request->user()->id,
            ]);

            AuditLogService::log(
                module: 'Traffic Violation Management',
                action: 'Violation Created',
                description: "Traffic violation #{$trafficViolation->id} was recorded.",
                record: $trafficViolation,
                newValues: $this->auditValues($trafficViolation)
            );

            return $trafficViolation;
        });

        return response()->json([
            'message' => 'Traffic violation recorded successfully.',
            'traffic_violation' => $trafficViolation->load($this->relations()),
        ], 201);
    }

    public function update(UpdateTrafficViolationRequest $request, TrafficViolation $trafficViolation): JsonResponse
    {
        abort_if($trafficViolation->archived_at !== null, 422, 'Archived traffic violations cannot be edited.');
        $oldValues = $this->auditValues($trafficViolation);
        $validated = $request->validated();

        DB::transaction(function () use ($trafficViolation, $validated, $oldValues): void {
            $trafficViolation->update($validated);

            AuditLogService::log(
                module: 'Traffic Violation Management',
                action: 'Violation Edited',
                description: "Traffic violation #{$trafficViolation->id} was edited.",
                record: $trafficViolation,
                oldValues: $oldValues,
                newValues: $this->auditValues($trafficViolation)
            );

            if ($oldValues['fine_amount'] !== $trafficViolation->fine_amount) {
                $this->logFieldChange($trafficViolation, 'Fine Amount Changed', $oldValues, 'fine_amount');
            }
        });

        return response()->json([
            'message' => 'Traffic violation updated successfully.',
            'traffic_violation' => $trafficViolation->fresh()->load($this->relations()),
        ]);
    }

    public function review(ReviewTrafficViolationRequest $request, TrafficViolation $trafficViolation): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $trafficViolation, $validated): void {
            $trafficViolation = TrafficViolation::query()
                ->lockForUpdate()
                ->findOrFail($trafficViolation->id);

            abort_if($trafficViolation->archived_at !== null, 422, 'Archived traffic violations cannot be reviewed.');

            if (! $trafficViolation->canTransitionTo($validated['status'])) {
                throw ValidationException::withMessages([
                    'status' => 'That status transition is not allowed from the current status.',
                ]);
            }

            $oldValues = $this->auditValues($trafficViolation);
            $trafficViolation->fill($validated);
            $trafficViolation->reviewed_by = $request->user()->id;
            $trafficViolation->reviewed_at = now();
            $trafficViolation->resolved_at = in_array($validated['status'], ['settled', 'dismissed', 'closed'], true)
                ? ($trafficViolation->resolved_at ?? now())
                : null;
            $trafficViolation->save();

            AuditLogService::log(
                module: 'Traffic Violation Management', action: 'Violation Reviewed',
                description: "Traffic violation #{$trafficViolation->id} was reviewed.", record: $trafficViolation,
                oldValues: $oldValues, newValues: $this->auditValues($trafficViolation)
            );

            foreach ([
                'responsibility' => 'Responsibility Changed',
                'status' => 'Status Changed',
                'fine_amount' => 'Fine Amount Changed',
                'payment_status' => 'Payment Status Changed',
            ] as $field => $action) {
                if ($oldValues[$field] !== $trafficViolation->{$field}) {
                    $this->logFieldChange($trafficViolation, $action, $oldValues, $field);
                }
            }
        });

        return response()->json(['message' => 'Traffic violation review saved successfully.']);
    }

    public function settlement(SettleTrafficViolationRequest $request, TrafficViolation $trafficViolation): JsonResponse
    {
        abort_if($trafficViolation->archived_at !== null, 422, 'Archived traffic violations cannot be settled.');
        $oldValues = $this->auditValues($trafficViolation);

        DB::transaction(function () use ($trafficViolation, $request, $oldValues): void {
            $trafficViolation->update($request->validated());
            AuditLogService::log(
                module: 'Traffic Violation Management', action: 'Settlement Recorded',
                description: "Settlement information for traffic violation #{$trafficViolation->id} was updated.",
                record: $trafficViolation, oldValues: $oldValues, newValues: $this->auditValues($trafficViolation)
            );
            if ($oldValues['payment_status'] !== $trafficViolation->payment_status) {
                $this->logFieldChange($trafficViolation, 'Payment Status Changed', $oldValues, 'payment_status');
            }
            if ($oldValues['fine_amount'] !== $trafficViolation->fine_amount) {
                $this->logFieldChange($trafficViolation, 'Fine Amount Changed', $oldValues, 'fine_amount');
            }
        });

        return response()->json(['message' => 'Settlement information updated successfully.']);
    }

    public function archive(Request $request, TrafficViolation $trafficViolation): JsonResponse
    {
        $this->authorize('archive', $trafficViolation);
        abort_if($trafficViolation->archived_at !== null, 422, 'Traffic violation is already archived.');

        $oldValues = $this->auditValues($trafficViolation);
        $trafficViolation->update(['archived_at' => now(), 'archived_by' => $request->user()->id]);
        AuditLogService::log(
            module: 'Traffic Violation Management', action: 'Violation Archived',
            description: "Traffic violation #{$trafficViolation->id} was archived.", record: $trafficViolation,
            oldValues: $oldValues, newValues: $this->auditValues($trafficViolation)
        );

        return response()->json(['message' => 'Traffic violation archived successfully.']);
    }

    public function restore(Request $request, TrafficViolation $trafficViolation): JsonResponse
    {
        $this->authorize('restore', $trafficViolation);
        abort_if($trafficViolation->archived_at === null, 422, 'Traffic violation is not archived.');

        $oldValues = $this->auditValues($trafficViolation);
        $trafficViolation->update(['archived_at' => null, 'archived_by' => null]);
        AuditLogService::log(
            module: 'Traffic Violation Management', action: 'Violation Restored',
            description: "Traffic violation #{$trafficViolation->id} was restored.", record: $trafficViolation,
            oldValues: $oldValues, newValues: $this->auditValues($trafficViolation)
        );

        return response()->json(['message' => 'Traffic violation restored successfully.']);
    }

    private function visibleQuery(Request $request): Builder
    {
        $query = TrafficViolation::query()->with($this->relations());

        if ($request->user()->hasRole('driver')) {
            $driverId = $request->user()->driverProfile?->id;
            $driverId ? $query->where('driver_id', $driverId) : $query->whereRaw('1 = 0');
        }

        if ($request->user()->hasRole('maintenance')) {
            $query->whereIn('violation_type', ['registration_document_issue', 'vehicle_equipment_issue']);
        }

        return $query;
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        $request->boolean('show_archived')
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('citation_number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('issuing_agency', 'like', "%{$search}%")
                    ->orWhereHas('driver', fn (Builder $driver) => $driver
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('plate_number', 'like', "%{$search}%"));
            });
        }

        foreach (['vehicle_id', 'driver_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        foreach (['violation_type', 'responsibility', 'status', 'payment_status', 'issuing_agency'] as $field) {
            if ($request->filled($field) && $request->input($field) !== 'all') {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('date_from')) {
            $query->whereDate('violation_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('violation_date', '<=', $request->input('date_to'));
        }
    }

    private function relations(): array
    {
        return [
            'driver:id,first_name,last_name,license_expiry,clutch_authorization',
            'driver.licenseCodes:id,driver_id,code',
            'driver.qualifications:id,driver_id,vehicle_type',
            'vehicle:id,plate_number,vehicle_type,lto_vehicle_category,registration_expiry,transmission_type',
            'dispatch:id,dispatch_number,reservation_id,dispatch_date,trip_status',
            'dispatch.reservation:id,reservation_number,vehicle_id,driver_id,schedule_date,status',
            'tripLog:id,dispatch_id', 'reservation:id,reservation_number,vehicle_id,driver_id,schedule_date,status',
            'reporter:id,name', 'reviewer:id,name',
        ];
    }

    private function auditValues(TrafficViolation $trafficViolation): array
    {
        return $trafficViolation->only([
            'citation_number', 'violation_date', 'violation_time', 'location', 'issuing_agency', 'violation_type',
            'description', 'notes', 'driver_id', 'vehicle_id', 'dispatch_id', 'trip_log_id', 'reservation_id',
            'responsibility', 'responsibility_notes', 'status', 'fine_amount', 'due_date', 'payment_status',
            'payment_date', 'payment_reference', 'settlement_notes', 'reported_by', 'reviewed_by', 'reviewed_at',
            'resolved_at', 'archived_at', 'archived_by',
        ]);
    }

    private function logFieldChange(
        TrafficViolation $trafficViolation,
        string $action,
        array $oldValues,
        string $field
    ): void {
        AuditLogService::log(
            module: 'Traffic Violation Management', action: $action,
            description: "{$action} for traffic violation #{$trafficViolation->id}.", record: $trafficViolation,
            oldValues: [$field => $oldValues[$field]], newValues: [$field => $trafficViolation->{$field}]
        );
    }
}
