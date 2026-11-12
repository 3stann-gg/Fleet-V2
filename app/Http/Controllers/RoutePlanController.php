<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FleetSetting;
use App\Models\HospitalFacility;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\RouteStop;
use App\Services\AuditLogService;
use App\Services\DispatchAssignmentService;
use App\Services\PlannedRouteProofService;
use App\Services\RouteComparisonService;
use App\Services\RouteDepartureContextService;
use App\Services\RouteEstimateProvenanceReadinessService;
use App\Services\RouteEstimateProvenanceService;
use App\Services\TomTomRoutingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RoutePlanController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private DispatchAssignmentService $dispatchAssignmentService,
        private RouteEstimateProvenanceService $provenanceService,
    ) {}

    /**
     * Display route plans.
     */
    private function getRouteSettings(): array
    {
        $record = FleetSetting::query()
            ->latest('id')
            ->first();

        $settings =
            $record?->settings ?? [];

        $routeSettings =
            $settings['routes'] ?? [];

        return [
            'preferOptimized' => $routeSettings['preferOptimized']
                ?? true,
        ];
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', RoutePlan::class);

        $user = $request->user();

        $query = RoutePlan::with([
            'reservation.vehicle',
            'reservation.driver',
            'reservation.pickupHospital',
            'reservation.destinationHospital',
            'stops',
        ]);

        $query =
            $this->applyRouteVisibility(
                $query,
                $user
            );

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */
        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('route_number', 'like', "%{$search}%")
                    ->orWhere('origin', 'like', "%{$search}%")
                    ->orWhere('destination', 'like', "%{$search}%")
                    ->orWhere('department', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%")
                    ->orWhereHas('reservation', function ($reservation) use ($search) {
                        $reservation->where('reservation_number', 'like', "%{$search}%")
                            ->orWhere('patient_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('reservation.vehicle', function ($vehicle) use ($search) {
                        $vehicle->where('brand', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%")
                            ->orWhere('plate_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('reservation.driver', function ($driver) use ($search) {
                        $driver->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Filters
        |--------------------------------------------------------------------------
        */

        if ($request->filled('priority') && $request->priority !== 'all') {
            $priorityValues = Reservation::equivalentPriorityValues(
                $request->string('priority')->toString()
            );

            $query->whereHas(
                'reservation',
                fn ($reservation) => $reservation->whereIn('priority', $priorityValues)
            );
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('department') && $request->department !== 'all') {
            $query->where('department', $request->department);
        }

        if ($request->filled('date')) {
            $query->whereDate('departure_date', $request->date);
        }

        if (! $request->boolean('show_archived')) {
            $query->where('status', '!=', 'Archived');
        }

        /*
        |--------------------------------------------------------------------------
        | Sorting
        |--------------------------------------------------------------------------
        */

        $allowedSorts = [
            'route_number',
            'priority',
            'estimated_distance',
            'estimated_time',
            'status',
            'departure_date',
            'created_at',
        ];

        $sort = $request->get('sort', 'id');

        $direction = strtolower(
            $request->get('direction', 'asc')
        ) === 'desc'
            ? 'desc'
            : 'asc';

        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'id';
        }

        $routePlans = $query
            ->orderBy($sort, $direction)
            ->get();

        if ($request->expectsJson()) {
            return response()->json([
                'routePlans' => $routePlans,
            ]);
        }

        $routePermissions = [
            'role' => $user->role,
            'canCreate' => $user->can(
                'create',
                RoutePlan::class
            ),
            'canUpdate' => $user->hasPermission('routes.manage'),
            'canArchive' => $user->hasPermission('routes.manage'),
            'canRestore' => $request->user()->hasPermission('routes.manage'),
            'canDuplicate' => $user->hasPermission('routes.manage'),
        ];

        return view(
            'route-planning.index',
            compact('routePermissions')
        );
    }

    /**
     * Get approved reservations that are still available for route planning.
     */
    public function availableReservations()
    {
        $this->authorize('create', RoutePlan::class);

        $reservations = Reservation::with([
            'vehicle',
            'driver',
            'pickupHospital',
            'destinationHospital',
        ])
            ->where('status', 'Approved')
            ->whereDoesntHave('routePlan')
            ->orderBy('schedule_date', 'asc')
            ->orderBy('schedule_time', 'asc')
            ->get();

        $reservations->each(function ($reservation) {
            $pickupFacility = $reservation->pickupHospital;
            $destinationFacility = $reservation->destinationHospital;

            $reservation->pickup_facility = $pickupFacility
                ? [
                    'id' => $pickupFacility->id,
                    'name' => $pickupFacility->name,
                    'address' => $pickupFacility->address,
                    'city' => $pickupFacility->city,
                    'province' => $pickupFacility->province,
                    'type' => $pickupFacility->type,
                ]
                : null;

            $reservation->destination_facility = $destinationFacility
                ? [
                    'id' => $destinationFacility->id,
                    'name' => $destinationFacility->name,
                    'address' => $destinationFacility->address,
                    'city' => $destinationFacility->city,
                    'province' => $destinationFacility->province,
                    'type' => $destinationFacility->type,
                ]
                : null;
        });

        return response()->json([
            'success' => true,
            'reservations' => $reservations,
        ]);
    }

    /**
     * Get active hospital/facility markers for the
     * Route Planning overview map.
     */
    public function mapOverview()
    {
        $this->authorize('viewAny', RoutePlan::class);

        $facilities = HospitalFacility::query()
            ->where('status', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'address',
                'city',
                'province',
                'latitude',
                'longitude',
                'type',
            ]);

        return response()->json([
            'success' => true,
            'facilities' => $facilities,
        ]);
    }

    /**
     * Store a new route plan.
     */
    public function store(Request $request)
    {
        $this->authorize('create', RoutePlan::class);

        $validator = Validator::make(
            $request->all(),
            [
                'route_number' => [
                    'nullable',
                    'string',
                    'max:50',
                    'unique:route_plans,route_number',
                ],
                'reservation_id' => [
                    'required',
                    'integer',
                    'exists:reservations,id',
                ],
                'department' => [
                    'required',
                    'string',
                    'max:100',
                ],
                'origin_latitude' => [
                    'nullable',
                    'required_with:origin_longitude',
                    'numeric',
                    'between:-90,90',
                ],
                'origin_longitude' => [
                    'nullable',
                    'required_with:origin_latitude',
                    'numeric',
                    'between:-180,180',
                ],
                'destination_latitude' => [
                    'nullable',
                    'required_with:destination_longitude',
                    'numeric',
                    'between:-90,90',
                ],
                'destination_longitude' => [
                    'nullable',
                    'required_with:destination_latitude',
                    'numeric',
                    'between:-180,180',
                ],
                'estimated_distance' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'estimated_time' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'optimization_strategy' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
                'optimization_score' => [
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:100',
                ],
                'purpose' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'notes' => [
                    'nullable',
                    'string',
                ],
                'stops' => [
                    'nullable',
                    'array',
                ],
                'stops.*.location' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'stops.*.latitude' => [
                    'nullable',
                    'required_with:stops.*.longitude',
                    'numeric',
                    'between:-90,90',
                ],
                'stops.*.longitude' => [
                    'nullable',
                    'required_with:stops.*.latitude',
                    'numeric',
                    'between:-180,180',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the route information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $routePlan = $this->provenanceService->createUnrecordedRoute(function () use ($validator, $request) {
                $validated = $validator->validated();
                /*
                |--------------------------------------------------------------------------
                | Lock Reservation
                |--------------------------------------------------------------------------
                */
                $reservation = Reservation::with([
                    'vehicle',
                    'driver',
                    'pickupHospital',
                    'destinationHospital',
                ])
                    ->lockForUpdate()
                    ->findOrFail(
                        $validated['reservation_id']
                    );

                $this->guardPlannedRouteSave($request, $reservation, null, $validated);

                /*
                |--------------------------------------------------------------------------
                | Resolve Reservation Hospital Names
                |--------------------------------------------------------------------------
                |
                | HospitalFacility relationship is the authoritative source for
                | normal hospital/facility reservations.
                |
                | Supply Delivery reservations have no HospitalFacility IDs, so
                | they continue using their existing reservation location values.
                |
                */
                $originLocation =
                    $reservation->pickupHospital?->name
                    ?: $reservation->pickup_location;

                $destinationLocation =
                    $reservation->destinationHospital?->name
                    ?: $reservation->destination;
                /*
                |--------------------------------------------------------------------------
                | Reservation must be Approved
                |--------------------------------------------------------------------------
                */
                if ($reservation->status !== 'Approved') {
                    throw new \Exception(
                        'Only approved reservations can be planned into a route.'
                    );
                }
                /*
                |--------------------------------------------------------------------------
                | Prevent Duplicate Route Plan
                |--------------------------------------------------------------------------
                */
                if ($reservation->routePlan()->exists()) {
                    throw new \Exception(
                        'This reservation already has a route plan.'
                    );
                }
                /*
                |--------------------------------------------------------------------------
                | Vehicle and Driver are required before Route Planning
                |--------------------------------------------------------------------------
                */
                if (! $reservation->vehicle) {
                    throw new \Exception(
                        'This reservation does not have an assigned vehicle.'
                    );
                }

                if (! $reservation->driver) {
                    throw new \Exception(
                        'This reservation does not have an assigned driver.'
                    );
                }
                /*
                |--------------------------------------------------------------------------
                | Generate Route Number
                |--------------------------------------------------------------------------
                */
                $routeNumber = ! empty($validated['route_number'])
                    ? $validated['route_number']
                    : $this->generateRouteNumber();
                /*
                |--------------------------------------------------------------------------
                | Create Draft Route Plan
                |--------------------------------------------------------------------------
                |
                | Reservation supplies the initial transportation information.
                |
                */
                $routePlan = RoutePlan::create([
                    'route_number' => $routeNumber,
                    'reservation_id' => $reservation->id,
                    'origin' => $originLocation,
                    'origin_latitude' => $validated['origin_latitude'] ?? null,
                    'origin_longitude' => $validated['origin_longitude'] ?? null,
                    'destination' => $destinationLocation,
                    'destination_latitude' => $validated['destination_latitude'] ?? null,
                    'destination_longitude' => $validated['destination_longitude'] ?? null,
                    'priority' => Reservation::canonicalPriority($reservation->priority),
                    'department' => $validated['department'],
                    'status' => 'Draft',
                    'departure_date' => $reservation->schedule_date,
                    'departure_time' => $reservation->schedule_time,
                    'estimated_distance' => $validated['estimated_distance'] ?? null,
                    'estimated_time' => $validated['estimated_time'] ?? null,
                    'optimization_strategy' => $validated['optimization_strategy'] ?? null,
                    'optimization_score' => $validated['optimization_score'] ?? null,
                    'purpose' => $validated['purpose'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                ]);
                /*
                |--------------------------------------------------------------------------
                | Create Stops
                |--------------------------------------------------------------------------
                */
                foreach (
                    $validated['stops'] ?? [] as $index => $stop
                ) {
                    $routePlan->stops()->create([
                        'stop_order' => $index + 1,
                        'location' => $stop['location'],
                        'latitude' => $stop['latitude'] ?? null,
                        'longitude' => $stop['longitude'] ?? null,
                    ]);
                }

                $routePlan->load([
                    'reservation.vehicle',
                    'reservation.driver',
                    'stops',
                ]);

                AuditLogService::log(
                    module: 'Route Planning',
                    action: 'Created',
                    description: "Created route plan {$routePlan->route_number}.",
                    record: $routePlan,
                    newValues: $this->getRoutePlanAuditValues(
                        $routePlan
                    )
                );

                return $routePlan;
            });

            return response()->json([
                'success' => true,
                'message' => 'Route plan created successfully.',
                'routePlan' => $routePlan,
            ], 201);

        } catch (HttpResponseException|AuthorizationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Route changes could not be saved.'], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Display a specific route plan.
     */
    public function show(RoutePlan $routePlan)
    {
        $this->authorize('view', $routePlan);

        $routePlan->load([
            'reservation.vehicle',
            'reservation.driver',
            'reservation.pickupHospital',
            'reservation.destinationHospital',
            'stops',
        ]);

        return response()->json([
            'routePlan' => $routePlan,
            'estimateProvenance' => $this->provenanceService->persistedSummary($routePlan),
        ]);
    }

    /**
     * Update route plan.
     */
    public function transition(
        Request $request,
        RoutePlan $routePlan,
    ) {
        return $this->update($request, $routePlan, true);
    }

    public function update(
        Request $request,
        RoutePlan $routePlan,
        bool $isLifecycleTransition = false,
    ) {

        $this->authorize('update', $routePlan);

        $validator = Validator::make(
            $request->all(),
            [
                'origin' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'destination' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'department' => [
                    'required',
                    'string',
                    'max:100',
                ],
                'origin_latitude' => [
                    'nullable',
                    'required_with:origin_longitude',
                    'numeric',
                    'between:-90,90',
                ],
                'origin_longitude' => [
                    'nullable',
                    'required_with:origin_latitude',
                    'numeric',
                    'between:-180,180',
                ],
                'destination_latitude' => [
                    'nullable',
                    'required_with:destination_longitude',
                    'numeric',
                    'between:-90,90',
                ],
                'destination_longitude' => [
                    'nullable',
                    'required_with:destination_latitude',
                    'numeric',
                    'between:-180,180',
                ],
                'status' => $isLifecycleTransition
                    ? ['required', Rule::in(['Draft', 'Planned', 'Ready For Dispatch'])]
                    : ['sometimes', Rule::in([$routePlan->status])],
                'estimated_distance' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'estimated_time' => [
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'optimization_strategy' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
                'optimization_score' => [
                    'nullable',
                    'numeric',
                    'min:0',
                    'max:100',
                ],
                'optimization_applied' => [
                    'sometimes',
                    'boolean',
                ],
                'optimization_proof' => [
                    'nullable',
                    'string',
                    'max:10000',
                ],
                'purpose' => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'notes' => [
                    'nullable',
                    'string',
                ],
                'stops' => [
                    'nullable',
                    'array',
                ],
                'stops.*.location' => [
                    'required',
                    'string',
                    'max:255',
                ],
                'stops.*.latitude' => [
                    'nullable',
                    'required_with:stops.*.longitude',
                    'numeric',
                    'between:-90,90',
                ],
                'stops.*.longitude' => [
                    'nullable',
                    'required_with:stops.*.latitude',
                    'numeric',
                    'between:-180,180',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the route information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->provenanceService->executeRouteOperation(
                $routePlan->id,
                $isLifecycleTransition ? 'transition' : 'update',
                $request->input('expected_estimate_revision'),
                (int) $request->user()->id,
                function (RoutePlan $fresh, Reservation $reservation): bool {
                    $this->authorize('update', $fresh);

                    return true;
                },
                function (RoutePlan $routePlan, Reservation $reservation) use (
                    $validator,
                    $isLifecycleTransition,
                    $request,
                ): void {
                    $validated = $validator->validated();

                    $this->guardPlannedRouteSave($request, $reservation, $routePlan, $validated);

                    if (in_array($routePlan->status, ['Completed', 'Archived'], true)) {
                        throw new \Exception(
                            "{$routePlan->status} route plans are historical records and cannot be edited."
                        );
                    }
                    /*
                    |--------------------------------------------------------------------------
                    | Lock Route Plan once Dispatch exists
                    |--------------------------------------------------------------------------
                    */
                    if (
                        $routePlan->reservation &&
                        $routePlan->reservation->dispatch
                    ) {
                        throw new \Exception(
                            'This route plan can no longer be modified because a dispatch has already been created.'
                        );
                    }

                    $currentStatus =
                        $routePlan->status;

                    $requestedStatus =
                        $isLifecycleTransition
                            ? $validated['status']
                            : $currentStatus;

                    if (
                        $isLifecycleTransition &&
                        $currentStatus !== $requestedStatus &&
                        ! in_array(
                            $requestedStatus,
                            RoutePlan::WORKFLOW_TRANSITIONS[$currentStatus] ?? [],
                            true
                        )
                    ) {
                        throw new \Exception(
                            "Cannot change route status from {$currentStatus} to {$requestedStatus}."
                        );
                    }

                    $newStatus = $requestedStatus;

                    $isEmergency = Reservation::isEmergencyPriority(
                        $routePlan->reservation?->priority
                    );
                    $optimizationApplied = (bool) ($validated['optimization_applied'] ?? false);

                    if (
                        $isLifecycleTransition &&
                        $isEmergency &&
                        $currentStatus === 'Planned' &&
                        $requestedStatus === 'Ready For Dispatch'
                    ) {
                        throw new \Exception(
                            'Emergency routes become Ready For Dispatch automatically after a valid optimization is applied and saved.'
                        );
                    }

                    if (
                        $isLifecycleTransition &&
                        $isEmergency &&
                        in_array($currentStatus, ['Draft', 'Planned'], true) &&
                        $requestedStatus === 'Planned' &&
                        $optimizationApplied
                    ) {
                        $this->assertEmergencyRouteReady(
                            $routePlan,
                            $validated
                        );

                        $newStatus = 'Ready For Dispatch';
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Reservation must still be Approved
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $routePlan->reservation &&
                        $routePlan->reservation->status !== 'Approved' &&
                        $newStatus !== 'Archived'
                    ) {
                        throw new \Exception(
                            'This route can no longer be modified because the reservation is no longer Approved.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Ready For Dispatch Requirements
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $newStatus === 'Ready For Dispatch'
                    ) {
                        if (
                            $validated['estimated_distance'] === null ||
                            $validated['estimated_time'] === null
                        ) {
                            throw new \Exception(
                                'Estimated distance and travel time are required before marking the route Ready For Dispatch.'
                            );
                        }
                    }

                    $originLocation =
                        $routePlan->reservation?->pickupHospital?->name
                        ?: $validated['origin'];

                    $destinationLocation =
                        $routePlan->reservation?->destinationHospital?->name
                        ?: $validated['destination'];

                    /*
                    |--------------------------------------------------------------------------
                    | Update Route Plan
                    |--------------------------------------------------------------------------
                    */

                    $this->saveRouteAndStops($routePlan, [
                        'origin' => $originLocation,
                        'origin_latitude' => $validated['origin_latitude'] ?? null,
                        'origin_longitude' => $validated['origin_longitude'] ?? null,
                        'destination' => $destinationLocation,
                        'destination_latitude' => $validated['destination_latitude'] ?? null,
                        'destination_longitude' => $validated['destination_longitude'] ?? null,
                        'priority' => Reservation::canonicalPriority(
                            $routePlan->reservation->priority
                        ),
                        'department' => $validated['department'],
                        'status' => $newStatus,
                        'departure_date' => $routePlan->reservation->schedule_date,
                        'departure_time' => $routePlan->reservation->schedule_time,
                        'estimated_distance' => $validated['estimated_distance'] ?? null,
                        'estimated_time' => $validated['estimated_time'] ?? null,
                        'optimization_strategy' => $validated['optimization_strategy'] ?? null,
                        'optimization_score' => $validated['optimization_score'] ?? null,
                        'purpose' => $validated['purpose'] ?? null,
                        'notes' => $validated['notes'] ?? null,
                    ], $validated['stops'] ?? []);
                },
                fn (Connection $connection, RoutePlan $before, RoutePlan $after, array $context): ?AuditLog => $this->auditRouteOperation($connection, $before, $after, $context),
                app(PlannedRouteProofService::class)->capabilities()['save']
                    ? $this->plannedSubmission($request, $validator->validated(), $routePlan->status) : null,
            );

            return response()->json([
                'success' => true,
                'message' => 'Route plan updated successfully.',
                'routePlan' => $result['route'],
                'estimateProvenance' => $result['summary'],
            ]);

        } catch (HttpResponseException|AuthorizationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Route changes could not be saved.'], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Archive route.
     */
    public function archive(Request $request, RoutePlan $routePlan)
    {
        return $this->changeArchiveState($request, $routePlan, false);
    }

    public function restore(Request $request, RoutePlan $routePlan)
    {
        return $this->changeArchiveState($request, $routePlan, true);
    }

    private function changeArchiveState(Request $request, RoutePlan $routePlan, bool $restore)
    {
        $event = $restore ? 'restore' : 'archive';
        $this->authorize($event, $routePlan);
        try {
            $result = $this->provenanceService->executeRouteOperation(
                $routePlan->id, $event, $request->input('expected_estimate_revision'), (int) $request->user()->id,
                function (RoutePlan $fresh, Reservation $reservation) use ($event): bool {
                    $this->authorize($event, $fresh);

                    return true;
                },
                function (RoutePlan $fresh, Reservation $reservation) use ($restore): void {
                    if ($restore && $fresh->status !== 'Archived') {
                        throw new \InvalidArgumentException('Only archived routes can be restored.');
                    }
                    if (! $restore && $fresh->status === 'Archived') {
                        throw new \InvalidArgumentException('This route plan is already archived.');
                    }
                    if ($reservation->dispatch) {
                        throw new \InvalidArgumentException($restore
                            ? 'This route plan cannot be restored because a dispatch already exists for its reservation.'
                            : 'This route plan cannot be archived because a dispatch already exists for its reservation.');
                    }
                    if ($restore && $reservation->status !== 'Approved') {
                        throw new \InvalidArgumentException('This route plan cannot be restored because its reservation is no longer Approved.');
                    }
                    if (! $fresh->update(['status' => $restore ? 'Draft' : 'Archived'])) {
                        throw new \RuntimeException('Route lifecycle persistence failed.');
                    }
                },
                fn (Connection $connection, RoutePlan $before, RoutePlan $after, array $context): ?AuditLog => $this->auditRouteOperation($connection, $before, $after, $context),
            );

            return response()->json([
                'success' => true,
                'message' => $restore ? 'Route plan restored successfully.' : 'Route plan archived successfully.',
                'routePlan' => $result['route'], 'estimateProvenance' => $result['summary'],
            ]);
        } catch (HttpResponseException|AuthorizationException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            return response()->json(['success' => false, 'message' => 'Route changes could not be saved.'], 422);
        } catch (\Exception $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Delete route plan.
     */

    /**
     * Duplicate a route plan using another approved reservation.
     */
    public function duplicate(
        Request $request,
        RoutePlan $routePlan
    ) {
        $this->authorize('view', $routePlan);

        $this->authorize('create', RoutePlan::class);

        $validator = Validator::make(
            $request->all(),
            [
                'reservation_id' => [
                    'required',
                    'integer',
                    'exists:reservations,id',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select a valid reservation for the duplicated route.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $newRoutePlan = $this->provenanceService->createUnrecordedRoute(function () use (
                $validator,
                $routePlan
            ) {
                $validated =
                    $validator->validated();
                /*
                |--------------------------------------------------------------------------
                | Load Original Route
                |--------------------------------------------------------------------------
                */
                $routePlan->load([
                    'stops',
                ]);
                /*
                |--------------------------------------------------------------------------
                | Find And Lock Target Reservation
                |--------------------------------------------------------------------------
                */
                $reservation = Reservation::with([
                    'vehicle',
                    'driver',
                    'pickupHospital',
                    'destinationHospital',
                ])
                    ->lockForUpdate()
                    ->findOrFail(
                        $validated['reservation_id']
                    );
                /*
                |--------------------------------------------------------------------------
                | Target Reservation Must Be Approved
                |--------------------------------------------------------------------------
                */
                if ($reservation->status !== 'Approved') {
                    throw new \Exception(
                        'Only approved reservations can be used for a duplicated route.'
                    );
                }
                /*
                |--------------------------------------------------------------------------
                | Prevent Duplicate Route Plan
                |--------------------------------------------------------------------------
                */
                if ($reservation->routePlan()->exists()) {
                    throw new \Exception(
                        'The selected reservation already has a route plan.'
                    );
                }
                /*
                |--------------------------------------------------------------------------
                | Vehicle And Driver Must Exist
                |--------------------------------------------------------------------------
                */
                if (! $reservation->vehicle) {
                    throw new \Exception(
                        'The selected reservation does not have an assigned vehicle.'
                    );
                }
                if (! $reservation->driver) {
                    throw new \Exception(
                        'The selected reservation does not have an assigned driver.'
                    );
                }
                /*
                |--------------------------------------------------------------------------
                | Prevent Using Same Reservation
                |--------------------------------------------------------------------------
                */
                if (
                    (int) $routePlan->reservation_id ===
                    (int) $reservation->id
                ) {
                    throw new \Exception(
                        'The duplicated route must use a different reservation.'
                    );
                }
                $originLocation =
                    $reservation->pickupHospital?->name
                    ?: $reservation->pickup_location;

                $destinationLocation =
                    $reservation->destinationHospital?->name
                    ?: $reservation->destination;
                /*
                |--------------------------------------------------------------------------
                | Create New Draft Route Plan
                |--------------------------------------------------------------------------
                | Reservation-derived information comes from the target Reservation.
                | Planning-specific reusable information may be copied from
                | the original route.
                */
                $copy = RoutePlan::create([
                    'route_number' => $this->generateRouteNumber(),
                    'reservation_id' => $reservation->id,
                    'origin' => $originLocation,
                    'origin_latitude' => null,
                    'origin_longitude' => null,
                    'destination' => $destinationLocation,
                    'destination_latitude' => null,
                    'destination_longitude' => null,
                    'priority' => Reservation::canonicalPriority($reservation->priority),
                    'department' => $routePlan->department,
                    'status' => 'Draft',
                    'departure_date' => $reservation->schedule_date,
                    'departure_time' => $reservation->schedule_time,
                    'estimated_distance' => null,
                    'estimated_time' => null,
                    'optimization_strategy' => $routePlan->optimization_strategy,
                    'optimization_score' => null,
                    'purpose' => $routePlan->purpose,
                    'notes' => $routePlan->notes,
                ]);
                /*
                |--------------------------------------------------------------------------
                | Copy Stops
                |--------------------------------------------------------------------------
                */
                foreach ($routePlan->stops as $stop) {
                    $copy->stops()->create([
                        'stop_order' => $stop->stop_order,
                        'location' => $stop->location,
                        'latitude' => $stop->latitude,
                        'longitude' => $stop->longitude,
                    ]);
                }
                $copy->load([
                    'reservation.vehicle',
                    'reservation.driver',
                    'stops',
                ]);

                AuditLogService::log(
                    module: 'Route Planning',
                    action: 'Duplicated',
                    description: "Duplicated route {$routePlan->route_number} as {$copy->route_number}.",
                    record: $copy,
                    newValues: $this->getRoutePlanAuditValues(
                        $copy
                    )
                );

                return $copy;
            });

            return response()->json([
                'success' => true,
                'message' => 'Route plan duplicated successfully.',
                'routePlan' => $newRoutePlan,
            ], 201);

        } catch (HttpResponseException|AuthorizationException $e) {
            throw $e;
        } catch (QueryException $e) {
            return response()->json(['success' => false, 'message' => 'Route changes could not be saved.'], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Calculate a traffic-aware route using TomTom.
     */
    public function trafficRoute(
        Request $request,
        TomTomRoutingService $tomTom,
        RouteComparisonService $comparison,
        RouteDepartureContextService $departureContexts
    ) {
        $this->authorize('viewAny', RoutePlan::class);

        if ($request->has('departure_mode') && $request->input('departure_mode') !== 'planned') {
            return response()->json([
                'success' => false,
                'code' => 'ROUTE_DEPARTURE_MODE_UNSUPPORTED',
                'message' => 'The requested departure mode is unsupported.',
            ], 422);
        }

        $isPlanned = $request->input('departure_mode') === 'planned';
        if ($isPlanned) {
            $this->authorize('create', RoutePlan::class);
            if (config('services.tomtom.planned_departure_enabled', false) !== true) {
                return response()->json([
                    'success' => false,
                    'code' => 'ROUTE_PLANNED_MODE_DISABLED',
                    'message' => 'Planned departure calculations are disabled.',
                ], 422);
            }
        }

        $validator = Validator::make(
            $request->all(),
            [
                'coordinates' => [
                    'required',
                    'array',
                    'min:2',
                ],
                'coordinates.*.latitude' => [
                    'required',
                    'numeric',
                    'between:-90,90',
                ],
                'coordinates.*.longitude' => [
                    'required',
                    'numeric',
                    'between:-180,180',
                ],
                'depart_at' => [
                    ...($isPlanned ? ['exclude'] : ['nullable', 'date']),
                ],
                'reservation_id' => [
                    Rule::requiredIf($isPlanned || $request->boolean('include_comparison')),
                    'nullable',
                    'integer',
                    ...($isPlanned ? [] : ['exists:reservations,id']),
                ],
                'include_comparison' => ['sometimes', 'boolean'],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please provide valid route coordinates.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $includeComparison = (bool) ($validated['include_comparison'] ?? false);
        $departureContext = null;
        $proofContext = null;
        if ($includeComparison || $isPlanned) {
            $this->authorize('create', RoutePlan::class);
            $reservation = Reservation::with('routePlan.reservation')->findOrFail($validated['reservation_id']);
            $this->authorize('view', $reservation);
            if ($reservation->routePlan) {
                $this->authorize('view', $reservation->routePlan);
            }
            if ($isPlanned) {
                try {
                    $departureContext = $departureContexts->forPlannedDeparture(
                        $reservation->getRawOriginal('schedule_date'),
                        $reservation->getRawOriginal('schedule_time'),
                    );
                } catch (\InvalidArgumentException $exception) {
                    return response()->json([
                        'success' => false,
                        'code' => 'ROUTE_DEPARTURE_CONTEXT_INVALID',
                        'message' => $exception->getMessage(),
                    ], 422);
                }
            }
        }

        $proofs = app(PlannedRouteProofService::class);
        if ($isPlanned && $proofs->capabilities()['issuance']) {
            $input = Validator::make($request->all(), [
                'route_id' => ['required', 'integer', 'min:1'],
                'expected_estimate_revision' => ['required', 'integer', 'min:0'],
                'intended_operation' => ['required', Rule::in(['update', 'transition'])],
                'target_status' => ['required', Rule::in(['Draft', 'Planned', 'Ready For Dispatch'])],
                'stop_labels' => ['present', 'array', 'size:'.(count($validated['coordinates']) - 2)],
                'stop_labels.*' => ['required', 'string', 'max:255'],
            ])->validate();
            $sourceRoute = RoutePlan::findOrFail($input['route_id']);
            $this->authorize('update', $sourceRoute);
            $this->authorize('view', $sourceRoute);
            if ((int) $sourceRoute->reservation_id !== (int) $reservation->id || $reservation->status !== 'Approved'
                || ! in_array($sourceRoute->status, ['Draft', 'Planned'], true) || $reservation->dispatch()->exists()) {
                $this->plannedProofFailure('ROUTE_PLANNED_CONTEXT_INELIGIBLE', 'This route is not eligible for planned proof issuance.');
            }
            if (! (new RouteEstimateProvenanceReadinessService)->installed($sourceRoute->getConnection())) {
                throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_PROVENANCE_SCHEMA_NOT_READY',
                    'message' => 'The route estimate schema is not ready.'], 503));
            }
            if ((string) $sourceRoute->estimate_revision !== (string) $input['expected_estimate_revision']) {
                throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_ESTIMATE_REVISION_STALE',
                    'message' => 'The route changed. Reload it before calculating again.'], 409));
            }
            $proofContext = $this->provenanceService->plannedContext($sourceRoute, $reservation, (int) $request->user()->id,
                $input['intended_operation'], (int) $sourceRoute->estimate_revision, $input['target_status']);
        }

        try {
            $departAt = $departureContext['depart_at'] ?? $validated['depart_at'] ?? ($includeComparison ? now()->toIso8601String() : null);
            $comparisonStartedAt = hrtime(true) / 1e9;
            $result = $includeComparison
                ? $tomTom->calculateRoute($validated['coordinates'], $departAt, true, RouteComparisonService::REQUEST_SECONDS)
                : $tomTom->calculateRoute($validated['coordinates'], $departAt);

            $distanceKm = $result['distance_meters'] !== null
                ? round($result['distance_meters'] / 1000, 2)
                : null;
            $travelTimeMinutes = $result['travel_time_seconds'] !== null
                ? round($result['travel_time_seconds'] / 60, 1)
                : null;
            $firstCoordinate = $validated['coordinates'][0];
            $lastCoordinate = $validated['coordinates'][array_key_last($validated['coordinates'])];
            $proofCoordinates = $this->optimizedProofCoordinates(
                $validated['coordinates'],
                array_key_exists('optimized_waypoints', $result)
                    ? $result['optimized_waypoints']
                    : []
            );
            $stopCount = max(count($validated['coordinates']) - 2, 0);
            $optimizationStrategy = match (true) {
                $stopCount >= 2 => 'TomTom Traffic-Aware Optimized',
                $stopCount === 1 => 'TomTom Traffic-Aware Multi-stop',
                default => 'TomTom Traffic-Aware Fastest',
            };
            if ($isPlanned) {
                if (! is_array($result['points'] ?? null) || count($result['points']) < 2) {
                    throw new \InvalidArgumentException('Planned route geometry is unavailable.');
                }
                foreach ($result['points'] as $point) {
                    foreach (['latitude' => 90, 'longitude' => 180] as $axis => $limit) {
                        $value = $point[$axis] ?? null;
                        if (! is_numeric($value) || ! is_finite((float) $value) || abs((float) $value) > $limit) {
                            throw new \InvalidArgumentException('Planned route geometry is invalid.');
                        }
                    }
                }
                $token = $proofContext === null ? null : $proofs->issue($proofContext, [
                    'coordinates' => $validated['coordinates'], 'stop_labels' => $input['stop_labels'], 'result' => $result,
                ]);
                $saveEligible = $token !== null && $proofs->capabilities()['save']
                    && (new RouteEstimateProvenanceReadinessService)->recordingEnabled();

                return response()->json([
                    'success' => true,
                    'departure_context' => $departureContext,
                    'save_eligible' => $saveEligible,
                    'apply_eligible' => $saveEligible,
                    ...($token === null ? [] : ['optimization_proof' => $token,
                        'expected_estimate_revision' => $proofContext['expected_estimate_revision'],
                        'intended_operation' => $proofContext['intended_operation'], 'target_status' => $proofContext['target_status']]),
                    'calculation' => [
                        'provider' => $result['provider'],
                        'routing_context' => $result['routing_context'],
                        'distance_meters' => $result['distance_meters'],
                        'distance_km' => $distanceKm,
                        'travel_time_seconds' => $result['travel_time_seconds'],
                        'travel_time_minutes' => $travelTimeMinutes,
                        'traffic_delay_seconds' => $result['traffic_delay_seconds'],
                        'traffic_length_meters' => $result['traffic_length_meters'],
                        'no_traffic_travel_time_seconds' => $result['no_traffic_travel_time_seconds'],
                        'historic_traffic_travel_time_seconds' => $result['historic_traffic_travel_time_seconds'],
                        'live_traffic_travel_time_seconds' => $result['live_traffic_travel_time_seconds'],
                        'departure_time' => $result['departure_time'],
                        'arrival_time' => $result['arrival_time'],
                        'optimized_waypoints' => $result['optimized_waypoints'],
                        'instructions' => array_values($result['instructions'] ?? []),
                        'points' => $result['points'],
                        ...($includeComparison ? ['comparison' => $comparison->compare($validated['coordinates'], $result, $departAt, $comparisonStartedAt)] : []),
                    ],
                ]);
            }
            $optimizationProof = Crypt::encryptString(json_encode([
                'user_id' => $request->user()->id,
                'reservation_id' => $validated['reservation_id'] ?? null,
                'issued_at' => now()->timestamp,
                'origin_latitude' => (float) $firstCoordinate['latitude'],
                'origin_longitude' => (float) $firstCoordinate['longitude'],
                'destination_latitude' => (float) $lastCoordinate['latitude'],
                'destination_longitude' => (float) $lastCoordinate['longitude'],
                'coordinates' => array_map(
                    fn (array $coordinate): array => [
                        'latitude' => (float) $coordinate['latitude'],
                        'longitude' => (float) $coordinate['longitude'],
                    ],
                    $proofCoordinates
                ),
                'estimated_distance' => $distanceKm,
                'estimated_time' => $travelTimeMinutes === null
                    ? null
                    : (int) ceil($travelTimeMinutes),
                'optimization_strategy' => $optimizationStrategy,
                'provider' => $result['provider'],
            ], JSON_THROW_ON_ERROR));

            return response()->json([
                'success' => true,
                'provider' => $result['provider'],
                'distance_meters' => $result['distance_meters'],
                'distance_km' => $distanceKm,
                'travel_time_seconds' => $result['travel_time_seconds'],
                'travel_time_minutes' => $travelTimeMinutes,
                'optimization_proof' => $optimizationProof,
                ...($includeComparison ? ['comparison' => $comparison->compare($validated['coordinates'], $result, $departAt, $comparisonStartedAt)] : []),
                'traffic_delay_seconds' => $result['traffic_delay_seconds'],
                'traffic_delay_minutes' => $result['traffic_delay_seconds'] !== null
                        ? round(
                            $result['traffic_delay_seconds'] / 60,
                            1
                        )
                        : null,
                'traffic_length_meters' => $result['traffic_length_meters'],
                'no_traffic_travel_time_seconds' => $result['no_traffic_travel_time_seconds'],
                'historic_traffic_travel_time_seconds' => $result['historic_traffic_travel_time_seconds'],
                'live_traffic_travel_time_seconds' => $result['live_traffic_travel_time_seconds'],
                'departure_time' => $result['departure_time'],
                'arrival_time' => $result['arrival_time'],
                'optimized_waypoints' => $result['optimized_waypoints'],
                /*
                |--------------------------------------------------------------------------
                | Turn-by-Turn Navigation Instructions
                |--------------------------------------------------------------------------
                |
                | Passed from TomTomRoutingService so the frontend can
                | determine the next maneuver, maneuver location,
                | route offset, and human-readable instruction.
                |
                */
                'instructions' => array_values(
                    $result['instructions'] ?? []
                ),
                'points' => $result['points'],
            ]);

        } catch (\Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Traffic-aware route calculation failed.',
                'error' => config('app.debug')
                        ? $e->getMessage()
                        : null,
            ], 502);
        }
    }

    /**
     * Route Planning statistics.
     */
    public function stats(
        Request $request
    ) {
        $this->authorize('viewAny', RoutePlan::class);

        $user = $request->user();

        $query = RoutePlan::with([
            'reservation.vehicle',
        ]);
        $query =
            $this->applyRouteVisibility(
                $query,
                $user
            );

        $routes =
            $query->get();
        $activeRoutes =
            $routes->where(
                'status',
                '!=',
                'Archived'
            );
        $ready =
            $activeRoutes
                ->where(
                    'status',
                    'Ready For Dispatch'
                )
                ->count();
        $highPriority =
            $activeRoutes
                ->filter(fn (RoutePlan $routePlan): bool => in_array(
                    Reservation::canonicalPriority($routePlan->reservation?->priority),
                    ['urgent', 'emergency'],
                    true
                ))
                ->count();
        $distances =
            $activeRoutes
                ->pluck(
                    'estimated_distance'
                )
                ->filter(
                    fn ($value) => $value !== null &&
                        (float) $value >= 0
                );
        $times =
            $activeRoutes
                ->pluck(
                    'estimated_time'
                )
                ->filter(
                    fn ($value) => $value !== null &&
                        (int) $value >= 0
                );
        $averageDistance =
            $distances->count() > 0
                ? round(
                    $distances->avg(),
                    1
                )
                : 0;
        $averageTime =
            $times->count() > 0
                ? round(
                    $times->avg()
                )
                : 0;
        $assignedVehicles =
            $activeRoutes
                ->map(
                    fn ($route) => $route
                        ->reservation
                        ?->vehicle_id
                )
                ->filter()
                ->unique()
                ->count();

        return response()->json([
            'total' => $activeRoutes->count(),
            'ready' => $ready,
            'high_priority' => $highPriority,
            'average_distance' => $averageDistance,
            'average_time' => $averageTime,
            'assigned_vehicles' => $assignedVehicles,
        ]);
    }

    /**
     * Resolve a hospital/facility location into saved coordinates.
     *
     * Uses the existing HospitalFacility master data instead of
     * depending only on frontend-supplied coordinates.
     */
    private function resolveFacilityCoordinates(?string $location): ?array
    {
        $normalized = mb_strtolower(
            preg_replace('/\s+/', ' ', trim((string) $location))
        );

        if ($normalized === '') {
            return null;
        }

        $facilities = HospitalFacility::query()
            ->where('status', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get([
                'id',
                'name',
                'address',
                'city',
                'province',
                'latitude',
                'longitude',
                'type',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Exact match
        |--------------------------------------------------------------------------
        */
        $facility = $facilities->first(function ($item) use ($normalized) {
            $name = mb_strtolower(
                preg_replace('/\s+/', ' ', trim((string) $item->name))
            );

            $address = mb_strtolower(
                preg_replace('/\s+/', ' ', trim((string) $item->address))
            );

            return $name === $normalized
                || $address === $normalized;
        });

        /*
        |--------------------------------------------------------------------------
        | Partial match fallback
        |--------------------------------------------------------------------------
        */
        if (! $facility) {
            $facility = $facilities->first(function ($item) use ($normalized) {
                $name = mb_strtolower(
                    preg_replace('/\s+/', ' ', trim((string) $item->name))
                );

                $address = mb_strtolower(
                    preg_replace('/\s+/', ' ', trim((string) $item->address))
                );

                return ($name !== '' && str_contains($normalized, $name))
                    || ($name !== '' && str_contains($name, $normalized))
                    || ($address !== '' && str_contains($normalized, $address))
                    || ($address !== '' && str_contains($address, $normalized));
            });
        }

        if (! $facility) {
            return null;
        }

        return [
            'latitude' => (float) $facility->latitude,
            'longitude' => (float) $facility->longitude,
            'facility_id' => $facility->id,
            'name' => $facility->name,
            'address' => $facility->address,
            'city' => $facility->city,
            'province' => $facility->province,
            'type' => $facility->type,
        ];
    }

    /**
     * Generate route number.
     *
     * Format:
     * ROUTE-YYYY-MM###
     *
     * Example:
     * ROUTE-2026-08001
     */
    private function generateRouteNumber(): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');

        $prefix =
            "ROUTE-{$year}-{$month}";

        $lastRoute = RoutePlan::where(
            'route_number',
            'like',
            $prefix.'%'
        )
            ->orderByDesc('route_number')
            ->first();

        $nextSequence = 1;

        if ($lastRoute) {
            $lastNumber = (int) substr(
                $lastRoute->route_number,
                strlen($prefix)
            );

            $nextSequence =
                $lastNumber + 1;
        }

        return $prefix.
            str_pad(
                $nextSequence,
                3,
                '0',
                STR_PAD_LEFT
            );
    }

    /**
     * Get next route number.
     */
    public function nextNumber()
    {
        $this->authorize('create', RoutePlan::class);

        return response()->json([
            'route_number' => $this->generateRouteNumber(),
        ]);
    }

    private function applyRouteVisibility(
        $query,
        $user
    ) {
        if ($user->hasRole('driver')) {
            $driverId =
                $user->driverProfile?->id;

            if ($driverId) {
                $query->whereHas(
                    'reservation',
                    fn ($reservation) => $reservation->where(
                        'driver_id',
                        $driverId
                    )
                );
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /** @param array<string, mixed> $validated */
    private function assertEmergencyRouteReady(
        RoutePlan $routePlan,
        array $validated
    ): void {
        $reservation = $routePlan->reservation;

        if (! $reservation) {
            throw new \Exception('The linked reservation is no longer available.');
        }

        if ($reservation->status !== 'Approved') {
            throw new \Exception(
                'The emergency reservation must be Approved before its route can become Ready For Dispatch.'
            );
        }

        if (! Reservation::isEmergencyPriority($reservation->priority)) {
            throw new \Exception('The linked reservation does not have emergency priority.');
        }

        if (! $reservation->vehicle_id) {
            throw new \Exception('The emergency reservation does not have an assigned vehicle.');
        }

        if (! $reservation->driver_id) {
            throw new \Exception('The emergency reservation does not have an assigned driver.');
        }

        if (
            trim((string) ($validated['origin'] ?? '')) === '' ||
            trim((string) ($validated['destination'] ?? '')) === ''
        ) {
            throw new \Exception(
                'Origin and destination are required before the emergency route can become Ready For Dispatch.'
            );
        }

        if (
            (float) ($validated['estimated_distance'] ?? 0) <= 0 ||
            (int) ($validated['estimated_time'] ?? 0) <= 0
        ) {
            throw new \Exception(
                'A positive estimated distance and travel time are required before the emergency route can become Ready For Dispatch.'
            );
        }

        if (trim((string) ($validated['optimization_strategy'] ?? '')) === '') {
            throw new \Exception(
                'An applied route optimization is required before the emergency route can become Ready For Dispatch.'
            );
        }

        foreach ([
            'origin_latitude',
            'origin_longitude',
            'destination_latitude',
            'destination_longitude',
        ] as $coordinate) {
            if (! isset($validated[$coordinate]) || ! is_numeric($validated[$coordinate])) {
                throw new \Exception(
                    'Complete optimized route coordinates are required before the emergency route can become Ready For Dispatch.'
                );
            }
        }

        if ($reservation->dispatch) {
            throw new \Exception(
                'This emergency route is locked because a dispatch has already been created.'
            );
        }

        $this->assertOptimizationProof(
            $routePlan,
            $validated
        );

        $routeForEvaluation = clone $routePlan;
        $routeForEvaluation->estimated_distance = $validated['estimated_distance'];
        $routeForEvaluation->estimated_time = $validated['estimated_time'];
        $reservation->setRelation('routePlan', $routeForEvaluation);

        $this->dispatchAssignmentService->validateManualPair(
            $reservation,
            (int) $reservation->vehicle_id,
            (int) $reservation->driver_id
        );
    }

    /** @param array<string, mixed> $validated */
    private function guardPlannedRouteSave(Request $request, Reservation $reservation, ?RoutePlan $routePlan, array $validated): void
    {
        $proofData = null;
        $proof = $request->input('optimization_proof');
        if (is_string($proof) && $proof !== '') {
            try {
                $proofData = json_decode(Crypt::decryptString($proof), true, 512, JSON_THROW_ON_ERROR);
            } catch (DecryptException|\JsonException) {
                // Unmarked legacy saves retain their existing optional-proof behavior.
            }
        }

        if (! $request->has('departure_mode') && ! $this->hasVersionedProofContext($proofData)) {
            return;
        }

        $this->authorize($routePlan === null ? 'create' : 'update', $routePlan ?? RoutePlan::class);
        $this->authorize('view', $reservation);
        if ($routePlan !== null) {
            $this->authorize('view', $routePlan);
        }
        if (config('services.tomtom.planned_departure_enabled', false) !== true) {
            $this->plannedProofFailure('ROUTE_PLANNED_MODE_DISABLED', 'Planned departure calculations are disabled.');
        }
        if ($request->has('departure_mode') && $request->input('departure_mode') !== 'planned') {
            $this->plannedProofFailure('ROUTE_DEPARTURE_MODE_UNSUPPORTED', 'The requested departure mode is unsupported.');
        }
        if ($proof === null || $proof === '') {
            $this->plannedProofFailure('ROUTE_PLANNED_PROOF_REQUIRED', 'A departure-bound routing proof is required.');
        }
        if (! is_array($proofData)) {
            $this->plannedProofFailure('ROUTE_PLANNED_PROOF_INVALID', 'The departure-bound routing proof could not be verified.');
        }

        if (app(PlannedRouteProofService::class)->capabilities()['save']) {
            if ($routePlan === null) {
                $this->plannedProofFailure('ROUTE_PLANNED_SAVE_UNSUPPORTED', 'Planned saving requires an existing Draft or Planned route.');
            }
            $this->provenanceService->verifyPlannedSave($routePlan, $reservation, (int) $request->user()->id,
                $request->routeIs('route-planning.transition') ? 'transition' : 'update',
                $request->input('expected_estimate_revision'), $this->plannedSubmission($request, $validated, $routePlan->status));

            return;
        }
        $this->assertPlannedOptimizationProof($request, $reservation, $routePlan, $validated, $proofData);

        $this->plannedProofFailure('ROUTE_PLANNED_SAVE_DISABLED', 'Planned departure saving is not enabled.');
    }

    private function hasVersionedProofContext(mixed $proofData): bool
    {
        return is_array($proofData) && (
            array_key_exists('version', $proofData) || array_key_exists('proof_type', $proofData) ||
            array_key_exists('departure_context', $proofData) || array_key_exists('schedule_fingerprint', $proofData)
        );
    }

    /** Request values stay untrusted until canonical verification inside the writer locks. */
    private function plannedSubmission(Request $request, array $validated, string $currentStatus): ?array
    {
        $token = $request->input('optimization_proof');
        $versioned = false;
        if (is_string($token) && strlen($token) <= PlannedRouteProofService::MAX_TOKEN_LENGTH) {
            try {
                $versioned = $this->hasVersionedProofContext(json_decode(Crypt::decryptString($token), true, 64, JSON_THROW_ON_ERROR));
            } catch (DecryptException|\JsonException) {
            }
        }
        if (! $request->has('departure_mode') && ! $versioned) {
            return null;
        }
        $coordinates = [['latitude' => $validated['origin_latitude'] ?? null, 'longitude' => $validated['origin_longitude'] ?? null]];
        foreach ($validated['stops'] ?? [] as $stop) {
            $coordinates[] = ['latitude' => $stop['latitude'] ?? null, 'longitude' => $stop['longitude'] ?? null];
        }
        $coordinates[] = ['latitude' => $validated['destination_latitude'] ?? null, 'longitude' => $validated['destination_longitude'] ?? null];

        return ['token' => is_string($token) ? $token : '', 'target_status' => $validated['status'] ?? $currentStatus,
            'route' => ['coordinates' => array_map(fn (array $point): array => array_map(fn ($value) => is_numeric($value) ? (float) $value : $value, $point), $coordinates),
                'stop_labels' => array_column($validated['stops'] ?? [], 'location'), 'stop_order' => $request->input('planned_stop_order'),
                'provider' => 'TomTom', 'optimization_strategy' => $validated['optimization_strategy'] ?? null,
                'estimated_distance' => isset($validated['estimated_distance']) ? (float) $validated['estimated_distance'] : null,
                'estimated_time' => isset($validated['estimated_time']) ? (int) $validated['estimated_time'] : null]];
    }

    /**
     * The caller must hold the existing Save locks and authorize the fresh reservation.
     * Disabled-Save compatibility validation only; enabled operations use the canonical proof service.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<string, mixed>  $proofData
     */
    private function assertPlannedOptimizationProof(Request $request, Reservation $reservation, ?RoutePlan $routePlan, array $validated, array $proofData): void
    {
        if (
            ($proofData['version'] ?? null) !== 2 || ($proofData['proof_type'] ?? null) !== 'planned_route' ||
            ! is_int($proofData['user_id'] ?? null) || $proofData['user_id'] !== $request->user()?->id ||
            ! is_int($proofData['reservation_id'] ?? null) || $proofData['reservation_id'] !== $reservation->id ||
            ! array_key_exists('route_id', $proofData) || $proofData['route_id'] !== $routePlan?->id ||
            ($routePlan !== null && (int) $routePlan->reservation_id !== (int) $reservation->id) ||
            ($proofData['provider'] ?? null) !== 'TomTom' ||
            ! in_array($proofData['optimization_strategy'] ?? null, [
                'TomTom Traffic-Aware Optimized', 'TomTom Traffic-Aware Multi-stop', 'TomTom Traffic-Aware Fastest',
            ], true) ||
            ! is_int($proofData['issued_at'] ?? null)
        ) {
            $this->plannedProofFailure('ROUTE_PLANNED_PROOF_INVALID', 'The departure-bound proof does not match this user, reservation or route context.');
        }

        $now = now()->timestamp;
        if ($proofData['issued_at'] < $now - 1800 || $proofData['issued_at'] > $now + 60) {
            $this->plannedProofFailure('ROUTE_PLANNED_PROOF_EXPIRED', 'The departure-bound proof has expired or has an invalid issue time.');
        }

        try {
            $context = app(RouteDepartureContextService::class)->forPlannedDeparture(
                $reservation->getRawOriginal('schedule_date'),
                $reservation->getRawOriginal('schedule_time'),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->plannedProofFailure('ROUTE_DEPARTURE_CONTEXT_INVALID', $exception->getMessage());
        }

        $fingerprint = hash('sha256', json_encode([
            'reservation_id' => $reservation->id,
            'schedule_date' => $reservation->getRawOriginal('schedule_date'),
            'schedule_time' => $reservation->getRawOriginal('schedule_time'),
        ], JSON_THROW_ON_ERROR));
        if (! is_string($proofData['schedule_fingerprint'] ?? null) || ! hash_equals($fingerprint, $proofData['schedule_fingerprint'])) {
            $this->plannedProofFailure('ROUTE_PLANNED_SCHEDULE_CHANGED', 'The reservation schedule changed after calculation. Calculate the route again.');
        }
        if (($proofData['departure_context'] ?? null) !== $context) {
            $this->plannedProofFailure('ROUTE_PLANNED_DEPARTURE_MISMATCH', 'The proof departure assumptions do not match the authorized reservation schedule.');
        }

        $stops = $validated['stops'] ?? [];
        $coordinates = $proofData['coordinates'] ?? null;
        $order = $proofData['stop_order'] ?? null;
        if (
            ! is_array($stops) || ! array_is_list($stops) ||
            ! is_array($coordinates) || ! array_is_list($coordinates) || count($coordinates) !== count($stops) + 2 ||
            ! is_array($order) || ! array_is_list($order) || count($order) !== count($stops) ||
            $request->input('planned_stop_order') !== $order ||
            ($proofData['stop_labels'] ?? null) !== array_column($stops, 'location')
        ) {
            $this->plannedProofFailure('ROUTE_PLANNED_VALUES_MISMATCH', 'The submitted stop occurrences do not match the departure-bound proof.');
        }
        $indexes = $order;
        foreach ($indexes as $index) {
            if (! is_int($index)) {
                $this->plannedProofFailure('ROUTE_PLANNED_VALUES_MISMATCH', 'The stop occurrence mapping is invalid.');
            }
        }
        sort($indexes);
        if ($indexes !== ($stops === [] ? [] : range(0, count($stops) - 1))) {
            $this->plannedProofFailure('ROUTE_PLANNED_VALUES_MISMATCH', 'The stop occurrence mapping is invalid.');
        }
        $endpoints = [
            ['latitude' => $proofData['origin_latitude'] ?? null, 'longitude' => $proofData['origin_longitude'] ?? null],
            ['latitude' => $proofData['destination_latitude'] ?? null, 'longitude' => $proofData['destination_longitude'] ?? null],
        ];
        foreach ([...$coordinates, ...$endpoints] as $coordinate) {
            foreach (['latitude' => 90, 'longitude' => 180] as $axis => $limit) {
                $value = is_array($coordinate) ? ($coordinate[$axis] ?? null) : null;
                if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || abs($value) > $limit) {
                    $this->plannedProofFailure('ROUTE_PLANNED_VALUES_MISMATCH', 'The proof coordinates are invalid.');
                }
            }
        }
        $distance = $proofData['estimated_distance'] ?? null;
        if (
            (! is_int($distance) && ! is_float($distance)) || ! is_finite((float) $distance) || $distance < 0 ||
            ! is_int($proofData['estimated_time'] ?? null) || $proofData['estimated_time'] < 0 ||
            ! isset($validated['estimated_time']) ||
            ! $this->optimizationValuesMatch($proofData, $validated)
        ) {
            $this->plannedProofFailure('ROUTE_PLANNED_VALUES_MISMATCH', 'The route values do not match the departure-bound proof.');
        }
    }

    private function plannedProofFailure(string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(['success' => false, 'code' => $code, 'message' => $message], 422));
    }

    /** @param array<string, mixed> $validated */
    private function assertOptimizationProof(RoutePlan $routePlan, array $validated): void
    {
        $proof = $validated['optimization_proof'] ?? null;

        if (! is_string($proof) || $proof === '') {
            throw new \Exception(
                'A verified server routing result is required before the emergency route can become Ready For Dispatch.'
            );
        }

        try {
            $proofData = json_decode(
                Crypt::decryptString($proof),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (DecryptException|\JsonException) {
            throw new \Exception(
                'The applied route optimization could not be verified. Run optimization again.'
            );
        }

        if ($this->hasVersionedProofContext($proofData)) {
            if (! app(PlannedRouteProofService::class)->capabilities()['save'] || request()->input('optimization_proof') !== $proof) {
                throw new \Exception('The applied route optimization could not be verified. Run optimization again.');
            }
            $this->guardPlannedRouteSave(request(), $routePlan->reservation, $routePlan, $validated);

            return;
        }
        if (! is_array($proofData)) {
            throw new \Exception(
                'The applied route optimization could not be verified. Run optimization again.'
            );
        }

        $proofIssuedAt = (int) ($proofData['issued_at'] ?? 0);
        $proofMatches =
            (int) ($proofData['user_id'] ?? 0) === (int) request()->user()?->id &&
            (int) ($proofData['reservation_id'] ?? 0) === (int) $routePlan->reservation_id &&
            $proofIssuedAt >= now()->subMinutes(30)->timestamp &&
            $proofIssuedAt <= now()->addMinute()->timestamp &&
            $this->optimizationValuesMatch(
                $proofData,
                $validated
            );

        if (! $proofMatches) {
            throw new \Exception(
                'The applied route optimization does not match the verified server routing result. Run optimization again.'
            );
        }
    }

    /** @param array<string, mixed> $proofData @param array<string, mixed> $validated */
    private function optimizationValuesMatch(array $proofData, array $validated): bool
    {
        $floatValues = [
            'origin_latitude' => 0.000001,
            'origin_longitude' => 0.000001,
            'destination_latitude' => 0.000001,
            'destination_longitude' => 0.000001,
            'estimated_distance' => 0.01,
        ];

        foreach ($floatValues as $field => $tolerance) {
            if (
                ! isset($proofData[$field], $validated[$field]) ||
                abs((float) $proofData[$field] - (float) $validated[$field]) > $tolerance
            ) {
                return false;
            }
        }

        if (
            (int) ($proofData['estimated_time'] ?? 0) !==
            (int) ($validated['estimated_time'] ?? 0) ||
            (string) ($proofData['optimization_strategy'] ?? '') !==
            (string) ($validated['optimization_strategy'] ?? '')
        ) {
            return false;
        }

        $proofCoordinates = $proofData['coordinates'] ?? null;

        if (! is_array($proofCoordinates)) {
            return false;
        }

        $submittedCoordinates = [[
            'latitude' => $validated['origin_latitude'],
            'longitude' => $validated['origin_longitude'],
        ]];

        foreach ($validated['stops'] ?? [] as $stop) {
            if (! isset($stop['latitude'], $stop['longitude'])) {
                return false;
            }

            $submittedCoordinates[] = [
                'latitude' => $stop['latitude'],
                'longitude' => $stop['longitude'],
            ];
        }

        $submittedCoordinates[] = [
            'latitude' => $validated['destination_latitude'],
            'longitude' => $validated['destination_longitude'],
        ];

        $normalizeCoordinates = static fn (array $coordinates): array => array_map(
            fn (array $coordinate): string => sprintf(
                '%.6F,%.6F',
                (float) ($coordinate['latitude'] ?? 0),
                (float) ($coordinate['longitude'] ?? 0)
            ),
            $coordinates
        );

        return $normalizeCoordinates($proofCoordinates) ===
            $normalizeCoordinates($submittedCoordinates);
    }

    /**
     * @param  list<array{latitude: numeric-string|float|int, longitude: numeric-string|float|int}>  $coordinates
     * @return list<array{latitude: numeric-string|float|int, longitude: numeric-string|float|int}>
     */
    private function optimizedProofCoordinates(array $coordinates, mixed $optimizedWaypoints): array
    {
        if (! is_array($optimizedWaypoints) || ! array_is_list($optimizedWaypoints)) {
            throw new \RuntimeException('TomTom returned an invalid waypoint permutation.');
        }

        $stops = array_slice($coordinates, 1, -1);
        $stopCount = count($stops);

        if ($stopCount < 2 && $optimizedWaypoints === []) {
            return $coordinates;
        }

        if (count($optimizedWaypoints) !== $stopCount) {
            throw new \RuntimeException('TomTom returned an invalid waypoint permutation.');
        }

        $providedIndexes = [];
        $orderedStops = [];

        foreach ($optimizedWaypoints as $waypoint) {
            $providedIndex = is_array($waypoint) ? ($waypoint['providedIndex'] ?? null) : null;
            $optimizedIndex = is_array($waypoint) ? ($waypoint['optimizedIndex'] ?? null) : null;

            if (
                ! is_int($providedIndex) || ! is_int($optimizedIndex) ||
                $providedIndex < 0 || $providedIndex >= $stopCount ||
                $optimizedIndex < 0 || $optimizedIndex >= $stopCount ||
                isset($providedIndexes[$providedIndex]) || isset($orderedStops[$optimizedIndex])
            ) {
                throw new \RuntimeException('TomTom returned an invalid waypoint permutation.');
            }

            $providedIndexes[$providedIndex] = true;
            $orderedStops[$optimizedIndex] = $stops[$providedIndex];
        }

        ksort($orderedStops);

        return [
            $coordinates[0],
            ...array_values($orderedStops),
            $coordinates[array_key_last($coordinates)],
        ];
    }

    private function getRoutePlanAuditValues(
        RoutePlan $routePlan
    ): array {
        return [
            'route_number' => $routePlan->route_number,
            'reservation_id' => $routePlan->reservation_id,
            'origin' => $routePlan->origin,
            'destination' => $routePlan->destination,
            'priority' => $routePlan->priority,
            'department' => $routePlan->department,
            'status' => $routePlan->status,
            'departure_date' => $routePlan->departure_date
                ?->format('Y-m-d'),
            'departure_time' => $routePlan->departure_time,
            'estimated_distance' => $routePlan->estimated_distance,
            'estimated_time' => $routePlan->estimated_time,
            'optimization_strategy' => $routePlan->optimization_strategy,
            'optimization_score' => $routePlan->optimization_score,
            'origin_latitude' => $routePlan->origin_latitude,
            'origin_longitude' => $routePlan->origin_longitude,
            'destination_latitude' => $routePlan->destination_latitude,
            'destination_longitude' => $routePlan->destination_longitude,
            'notes_hash' => $routePlan->notes === null ? null : hash('sha256', $routePlan->notes),
            'purpose_hash' => $routePlan->purpose === null ? null : hash('sha256', $routePlan->purpose),
            'stop_occurrences' => $routePlan->relationLoaded('stops') ? $routePlan->getRelation('stops')->map(fn (RouteStop $stop): array => [
                'position' => $stop->stop_order, 'label_hash' => hash('sha256', $stop->location),
                'latitude' => $stop->latitude, 'longitude' => $stop->longitude,
            ])->all() : null,
            ...array_key_exists('estimate_revision', $routePlan->getAttributes()) ? ['estimate_revision' => $routePlan->estimate_revision] : [],
        ];
    }

    /** @param array<string, mixed> $values @param list<array<string, mixed>> $stops */
    private function saveRouteAndStops(RoutePlan $route, array $values, array $stops): void
    {
        $expected = (clone $route)->fill($values);
        if (! $route->update($values)) {
            throw new \RuntimeException('Route persistence failed.');
        }
        $route->stops()->delete();
        foreach ($stops as $index => $stop) {
            $created = $route->stops()->create([
                'stop_order' => $index + 1, 'location' => $stop['location'],
                'latitude' => $stop['latitude'] ?? null, 'longitude' => $stop['longitude'] ?? null,
            ]);
            if (! $created->exists) {
                throw new \RuntimeException('Route stop persistence failed.');
            }
        }
        $route->refresh()->load('stops');
        foreach (array_keys($values) as $field) {
            $actualValue = $field === 'departure_date' ? $route->departure_date?->format('Y-m-d') : $route->getAttribute($field);
            $expectedValue = $field === 'departure_date' ? $expected->departure_date?->format('Y-m-d') : $expected->getAttribute($field);
            if ($actualValue !== $expectedValue) {
                throw new \RuntimeException('Persisted route fields do not match the requested state.');
            }
        }
        if ($route->stops->count() !== count($stops)) {
            throw new \RuntimeException('Persisted stops do not match the requested occurrences.');
        }
        foreach ($route->stops as $index => $stop) {
            $expectedStop = new RouteStop([
                'stop_order' => $index + 1, 'location' => $stops[$index]['location'],
                'latitude' => $stops[$index]['latitude'] ?? null, 'longitude' => $stops[$index]['longitude'] ?? null,
            ]);
            foreach (['stop_order', 'location', 'latitude', 'longitude'] as $field) {
                if ($stop->getAttribute($field) !== $expectedStop->getAttribute($field)) {
                    throw new \RuntimeException('Persisted stop identity does not match the requested state.');
                }
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function auditRouteOperation(Connection $connection, RoutePlan $before, RoutePlan $after, array $context): ?AuditLog
    {
        $old = $this->getRoutePlanAuditValues($before);
        $new = $this->getRoutePlanAuditValues($after);
        $changedOld = [];
        $changedNew = [];
        foreach ($new as $field => $value) {
            if (($old[$field] ?? null) !== $value) {
                $changedOld[$field] = $old[$field] ?? null;
                $changedNew[$field] = $value;
            }
        }
        if ($changedNew === [] && ! $context['protected']) {
            return null;
        }
        $action = $context['event'] === 'restore' ? 'Restored'
            : ($before->status !== $after->status ? match ($after->status) {
                'Planned' => 'Planned', 'Ready For Dispatch' => 'Ready For Dispatch',
                'Completed' => 'Completed', 'Archived' => 'Archived', 'Draft' => 'Returned To Draft',
                default => 'Updated',
            } : 'Updated');
        if ($context['protected']) {
            $changedNew['estimate_provenance'] = array_diff_key($context, ['protected' => true]);
        }

        return AuditLogService::log(
            module: 'Route Planning', action: $action,
            description: $action.' route '.$after->route_number.'.', record: $after,
            oldValues: $changedOld, newValues: $changedNew, connection: $connection->getName(),
        );
    }
}
