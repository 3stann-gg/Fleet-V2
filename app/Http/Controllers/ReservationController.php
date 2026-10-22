<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\FleetNotification;
use App\Models\FleetSetting;
use App\Models\HospitalFacility;
use App\Models\Reservation;
use App\Models\Shipment;
use App\Models\Vehicle;
use App\Models\VehicleCapability;
use App\Services\AuditLogService;
use App\Services\FleetNotificationService;
use App\Services\ReservationAssignmentEligibilityService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReservationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private ReservationAssignmentEligibilityService $assignmentEligibility,
    ) {}

    private function getReservationSettings(): array
    {
        $record = FleetSetting::query()
            ->latest('id')
            ->first();
        $settings = $record?->settings ?? [];
        $reservationSettings =
            $settings['reservations'] ?? [];

        return [
            'requireApproval' => $reservationSettings['requireApproval'] ?? true,
            'allowSameDay' => $reservationSettings['allowSameDay'] ?? true,
            'maxAdvanceDays' => max(
                1,
                min(
                    365,
                    (int) (
                        $reservationSettings['maxAdvanceDays']
                        ?? 30
                    )
                )
            ),
            'defaultDurationHours' => max(
                1,
                min(
                    72,
                    (int) (
                        $reservationSettings['defaultDurationHours']
                        ?? 2
                    )
                )
            ),
        ];
    }

    /** @return array{priority: string, schedule_date: string, schedule_time: string, status: string} */
    private function emergencyReservationValues(): array
    {
        $currentTime = now();

        return [
            'priority' => 'emergency',
            'schedule_date' => $currentTime->toDateString(),
            'schedule_time' => $currentTime->format('H:i'),
            'status' => 'Approved',
        ];
    }

    private function validateVehicleAndDriverAvailability(
        ?int $vehicleId,
        ?int $driverId,
        string $scheduleDate,
        string $scheduleTime,
        int $durationHours,
        ?int $exceptReservationId = null,
        array $requiredCapabilityIds = [],
    ): void {
        $this->assignmentEligibility->validateAndLock(
            $vehicleId,
            $driverId,
            $scheduleDate,
            $scheduleTime,
            $durationHours,
            $exceptReservationId,
            $requiredCapabilityIds,
        );
    }

    private function updateReservationUnderLock(
        Reservation $reservation,
        array $validated,
        bool $isEmergencyRequest,
        bool $requirePending,
        bool $syncCapabilities,
    ): array {
        return DB::transaction(function () use (
            $reservation,
            $validated,
            $isEmergencyRequest,
            $requirePending,
            $syncCapabilities,
        ): array {
            $reservation = Reservation::query()
                ->with(['routePlan', 'dispatch'])
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->archived_at) {
                throw new \Exception('Archived reservations cannot be edited.');
            }

            if ($reservation->dispatch) {
                throw new \Exception(
                    'This reservation can no longer be modified because a dispatch has already been created.'
                );
            }

            if ($reservation->routePlan) {
                throw new \Exception(
                    'This reservation can no longer be modified because a route plan has already been created.'
                );
            }

            if ($requirePending && $reservation->status !== 'Pending') {
                throw new \Exception('Only Pending reservations can be edited by a department head.');
            }

            unset($validated['status']);

            if (
                $isEmergencyRequest &&
                in_array($reservation->status, ['Pending', 'Approved'], true)
            ) {
                $validated['status'] = 'Approved';
            }

            $targetVehicleId = $validated['vehicle_id'] ?? $reservation->vehicle_id;
            $targetDriverId = $validated['driver_id'] ?? $reservation->driver_id;
            $targetScheduleDate = $validated['schedule_date'] ?? $reservation->schedule_date->toDateString();
            $targetScheduleTime = $validated['schedule_time'] ?? $reservation->schedule_time;
            $reservation->loadMissing('requiredCapabilities');
            $targetCapabilityIds = $syncCapabilities
                ? ($validated['required_capabilities'] ?? [])
                : $reservation->requiredCapabilities->pluck('id')->all();
            $currentCapabilityIds = $reservation->requiredCapabilities->pluck('id')->sort()->values()->all();
            $sortedTargetCapabilityIds = collect($targetCapabilityIds)->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $assignmentChanged = (int) $targetVehicleId !== (int) $reservation->vehicle_id
                || (int) $targetDriverId !== (int) $reservation->driver_id
                || $targetScheduleDate !== $reservation->schedule_date->toDateString()
                || substr((string) $targetScheduleTime, 0, 5) !== substr((string) $reservation->schedule_time, 0, 5)
                || $sortedTargetCapabilityIds !== $currentCapabilityIds;

            if ($assignmentChanged) {
                $this->validateVehicleAndDriverAvailability(
                    $targetVehicleId,
                    $targetDriverId,
                    $targetScheduleDate,
                    $targetScheduleTime,
                    $this->getReservationSettings()['defaultDurationHours'],
                    $reservation->id,
                    $targetCapabilityIds,
                );
            }

            $oldAuditValues = $this->getReservationAuditValues($reservation);
            $hadCompleteAssignment = $reservation->vehicle_id !== null &&
                $reservation->driver_id !== null;

            $reservation->update($validated);

            if ($syncCapabilities) {
                $this->syncRequiredCapabilities(
                    $reservation,
                    $validated['required_capabilities'] ?? []
                );
            }

            $reservation->refresh();

            return [
                'reservation' => $reservation,
                'oldAuditValues' => $oldAuditValues,
                'newAuditValues' => $this->getReservationAuditValues($reservation),
                'hadCompleteAssignment' => $hadCompleteAssignment,
                'hasCompleteAssignment' => $reservation->vehicle_id !== null &&
                    $reservation->driver_id !== null,
            ];
        });
    }

    private function decideReservationUnderLock(
        Reservation $reservation,
        string $status,
        bool $requireNoDispatch,
    ): array {
        return DB::transaction(function () use (
            $reservation,
            $status,
            $requireNoDispatch,
        ): array {
            $reservation = Reservation::query()
                ->with(['routePlan', 'dispatch', 'requester'])
                ->lockForUpdate()
                ->findOrFail($reservation->id);

            if ($reservation->archived_at) {
                throw new \Exception(
                    "Archived reservations cannot be {$status}."
                );
            }

            if ($reservation->status !== 'Pending') {
                throw new \Exception(
                    "Only Pending reservations can be {$status}."
                );
            }

            if (! $reservation->vehicle_id || ! $reservation->driver_id) {
                throw new \Exception(
                    "A vehicle and driver must both be assigned before the reservation can be {$status}."
                );
            }

            if ($requireNoDispatch && $reservation->dispatch) {
                throw new \Exception('This reservation already has a dispatch.');
            }

            $this->validateVehicleAndDriverAvailability(
                $reservation->vehicle_id,
                $reservation->driver_id,
                $reservation->schedule_date->toDateString(),
                (string) $reservation->schedule_time,
                $this->getReservationSettings()['defaultDurationHours'],
                $reservation->id,
                $reservation->requiredCapabilities()->pluck('vehicle_capabilities.id')->all(),
            );

            $oldValues = $this->getReservationAuditValues($reservation);
            $reservation->update(['status' => ucfirst($status)]);

            return [
                'reservation' => $reservation,
                'oldValues' => $oldValues,
            ];
        });
    }

    /**
     * Display a listing of reservations.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $user = $request->user();

        $query = Reservation::with([
            'vehicle',
            'driver',
            'requester',
            'pickupHospital',
            'destinationHospital',
            'requiredCapabilities',
            // 'shipment',
        ]);

        if (
            $user->hasRole('fleet_manager') &&
            $request->boolean('show_archived')
        ) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }
        /*
        |--------------------------------------------------------------------------
        | RBAC Data Scope
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('driver')) {
            $driverId =
                $user->driverProfile?->id;
            if ($driverId) {
                $query->where(
                    'driver_id',
                    $driverId
                );
            } else {
                /*
                | Driver account not linked to a Driver profile.
                | Return no reservation records.
                */
                $query->whereRaw('1 = 0');
            }
        }
        if ($user->hasRole('department_head')) {
            if ($user->department) {
                $query->where(
                    'department',
                    $user->department
                );
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;

            $query->where(function ($q) use ($search) {
                $q->where(
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
                    )
                    ->orWhereHas('vehicle', function ($vehicle) use ($search) {
                        $vehicle
                            ->where('brand', 'like', "%{$search}%")
                            ->orWhere('model', 'like', "%{$search}%")
                            ->orWhere('plate_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('driver', function ($driver) use ($search) {
                        $driver
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
            });
        }
        if (
            $request->filled('request_type') &&
            $request->request_type !== 'all'
        ) {
            $query->where(
                'request_type',
                $request->request_type
            );
        }
        if (
            $request->filled('priority') &&
            $request->priority !== 'all'
        ) {
            $query->where(
                'priority',
                $request->priority
            );
        }
        if (
            $request->filled('status') &&
            $request->status !== 'all'
        ) {
            $query->where(
                'status',
                $request->status
            );
        }

        $allowedSorts = [
            'id',
            'reservation_number',
            'patient_name',
            'schedule_date',
            'schedule_time',
            'priority',
            'status',
        ];

        $sort = $request->get('sort', 'id');

        if (! in_array($sort, $allowedSorts)) {
            $sort = 'id';
        }

        $direction = $request->get('direction', 'asc');

        if (! in_array($direction, ['asc', 'desc'])) {
            $direction = 'asc';
        }

        $query->orderBy($sort, $direction);

        $reservations = $query->get();

        if ($request->expectsJson()) {
            return response()->json([
                'reservations' => $reservations,
            ]);
        }

        $reservationPermissions = [
            'role' => $user->role,
            'canCreate' => $user->can(
                'create',
                Reservation::class
            ),
            'canUpdate' => $user->hasPermission('reservations.update'),
            'canArchive' => $user->hasPermission('reservations.archive'),
            'canBulkArchive' => $user->hasPermission('reservations.archive'),
            'canApprove' => $user->hasPermission('reservations.manage_all'),
            'canReject' => $user->hasPermission('reservations.manage_all'),
        ];

        return view(
            'reservation.index',
            compact('reservationPermissions')
        );
    }

    /**
     * Store a newly created reservation.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Reservation::class);

        $reservationSettings =
            $this->getReservationSettings();
        $isEmergencyRequest = Reservation::isEmergencyRequestType(
            $request->input('request_type')
        );
        $emergencyValues = $isEmergencyRequest
            ? $this->emergencyReservationValues()
            : [];
        if ($isEmergencyRequest) {
            $request->merge($emergencyValues);
        }
        $minimumDate =
            $isEmergencyRequest
            ? now()->toDateString()
            : (
                $reservationSettings['allowSameDay']
                    ? now()->toDateString()
                    : now()->addDay()->toDateString()
            );
        $maximumDate =
            now()
                ->addDays(
                    $reservationSettings['maxAdvanceDays']
                )
                ->toDateString();

        $validator = Validator::make(
            $request->all(),
            [
                'reservation_number' => [
                    'nullable',
                    'string',
                    'max:50',
                    'unique:reservations,reservation_number',
                ],
                'patient_name' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::requiredIf(function () use ($request) {
                        return Reservation::requiresPatientName(
                            $request->input('request_type')
                        );
                    }),
                ],
                'request_type' => [
                    'required',
                    Rule::in(Reservation::supportedRequestTypes()),
                ],
                'shipment_id' => [
                    'nullable',
                    'integer',
                ],
                'vehicle_id' => [
                    'nullable',
                    'exists:vehicles,id',
                ],
                'driver_id' => [
                    'nullable',
                    'exists:drivers,id',
                ],
                'pickup_location' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::requiredIf(function () use ($request) {
                        return ! Reservation::isSupplyDeliveryType(
                            $request->input('request_type')
                        );
                    }),
                ],
                'pickup_hospital_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('hospital_facilities', 'id')
                        ->where(function ($query) {
                            $query->where('status', true);
                        }),
                ],
                'destination' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::requiredIf(function () use ($request) {
                        return ! Reservation::isSupplyDeliveryType(
                            $request->input('request_type')
                        );
                    }),
                ],
                'destination_hospital_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('hospital_facilities', 'id')
                        ->where(function ($query) {
                            $query->where('status', true);
                        }),
                ],
                'schedule_date' => [
                    'required',
                    'date',
                    'after_or_equal:'.$minimumDate,
                    'before_or_equal:'.$maximumDate,
                ],
                'schedule_time' => [
                    'required',
                ],
                'priority' => [
                    'required',
                    Rule::in(Reservation::supportedPriorities()),
                ],
                'status' => [
                    'nullable',
                    'in:Pending,Approved',
                ],
                'contact_number' => [
                    'nullable',
                    'string',
                    'max:50',
                ],
                'required_capabilities' => ['nullable', 'array'],
                'required_capabilities.*' => [
                    'integer',
                    Rule::exists('vehicle_capabilities', 'id'),
                ],
                'notes' => [
                    'nullable',
                    'string',
                ],
            ],
            [
                'schedule_date.after_or_equal' => $reservationSettings['allowSameDay']
                        ? 'Reservation date cannot be earlier than today.'
                        : 'Same-day reservations are disabled. Please select a future date.',

                'schedule_date.before_or_equal' => 'Reservation date cannot be more than '.
                    $reservationSettings['maxAdvanceDays'].
                    ' days in advance.',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the reservation information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validated = [
                ...$validator->validated(),
                ...$emergencyValues,
            ];

            /*
            |--------------------------------------------------------------------------
            | Hospital Facility Resolution
            |--------------------------------------------------------------------------
            | For normal reservations, preserve the selected HospitalFacility IDs
            | and use the facility's canonical name + address as the location text.
            |
            | Supply Delivery gets its locations from Logistics Shipment instead,
            | so HospitalFacility IDs must be cleared.
            |--------------------------------------------------------------------------
            */

            if (Reservation::isSupplyDeliveryType($validated['request_type'])) {

                $validated['pickup_hospital_id'] = null;
                $validated['destination_hospital_id'] = null;

                $shipment = Shipment::find(
                    $validated['shipment_id']
                );

                if (! $shipment) {
                    throw new \Exception(
                        'Selected Logistics shipment was not found.'
                    );
                }

                if (in_array(
                    $shipment->status,
                    [
                        'arrived_at_dock',
                        'received',
                    ],
                    true
                )) {
                    throw new \Exception(
                        'The selected shipment is already completed or has already arrived at the HIMS receiving dock.'
                    );
                }

                $validated['pickup_location'] =
                    $shipment->origin_address
                    ?: $shipment->pickup_location_name;

                $validated['destination'] =
                    $shipment->destination_facility;

                if (
                    ! $validated['pickup_location'] ||
                    ! $validated['destination']
                ) {
                    throw new \Exception(
                        'The selected Logistics shipment does not have a valid pickup location or destination.'
                    );
                }

            } else {

                $validated['shipment_id'] = null;

                /*
                |--------------------------------------------------------------------------
                | Resolve Selected Pickup Facility
                |--------------------------------------------------------------------------
                */
                if (! empty($validated['pickup_hospital_id'])) {

                    $pickupHospital = HospitalFacility::query()
                        ->where('status', true)
                        ->find($validated['pickup_hospital_id']);

                    if (! $pickupHospital) {
                        throw new \Exception(
                            'The selected pickup hospital or facility is no longer available.'
                        );
                    }

                    $validated['pickup_location'] =
                        $pickupHospital->name;
                }

                /*
                |--------------------------------------------------------------------------
                | Resolve Selected Destination Facility
                |--------------------------------------------------------------------------
                */
                if (! empty($validated['destination_hospital_id'])) {

                    $destinationHospital = HospitalFacility::query()
                        ->where('status', true)
                        ->find($validated['destination_hospital_id']);

                    if (! $destinationHospital) {
                        throw new \Exception(
                            'The selected destination hospital or facility is no longer available.'
                        );
                    }

                    $validated['destination'] =
                        $destinationHospital->name;
                }
            }

            $validated['requested_by'] =
                $request->user()->id;
            /*
            |--------------------------------------------------------------------------
            | Department Head ownership
            |--------------------------------------------------------------------------
            */
            if (
                $request->user()->hasRole(
                    'department_head'
                )
            ) {
                if (! $request->user()->department) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Your account does not have an assigned department.',
                    ], 422);
                }
                $validated['department'] =
                    $request->user()->department;
                /*
                |--------------------------------------------------------------------------
                | Department Heads do not assign operational resources
                |--------------------------------------------------------------------------
                */
                $validated['vehicle_id'] = null;
                $validated['driver_id'] = null;
            }

            $validated['requested_by'] =
                $request->user()->id;

            $validated['department'] =
                $request->user()->department;
            /*
            |--------------------------------------------------------------------------
            | Emergency / Normal Reservation
            |--------------------------------------------------------------------------
            */
            if (! $isEmergencyRequest) {
                /*
                |--------------------------------------------------------------------------
                | Normal reservation approval flow.
                |--------------------------------------------------------------------------
                */
                $validated['status'] =
                    $reservationSettings['requireApproval']
                        ? 'Pending'
                        : 'Approved';
            }
            $validated['reservation_number'] =
                $validated['reservation_number']
                    ?? $this->generateReservationNumber();

            $reservation = DB::transaction(function () use (
                $validated,
                $reservationSettings,
            ): Reservation {
                $this->validateVehicleAndDriverAvailability(
                    $validated['vehicle_id'] ?? null,
                    $validated['driver_id'] ?? null,
                    $validated['schedule_date'],
                    $validated['schedule_time'],
                    $reservationSettings['defaultDurationHours'],
                    null,
                    $validated['required_capabilities'] ?? [],
                );

                $reservation = Reservation::create($validated);

                $this->syncRequiredCapabilities(
                    $reservation,
                    $validated['required_capabilities'] ?? []
                );

                return $reservation;
            });

            $reservation->load([
                'vehicle',
                'driver',
                'pickupHospital',
                'destinationHospital',
                'requiredCapabilities',
                // 'shipment',
            ]);

            AuditLogService::log(
                module: 'Reservation Management',
                action: 'Created',
                description: $isEmergencyRequest
                        ? "Created emergency reservation {$reservation->reservation_number}; approval workflow bypassed for immediate processing."
                        : "Created reservation {$reservation->reservation_number}.",
                record: $reservation,
                newValues: $this->getReservationAuditValues(
                    $reservation
                )
            );

            if ($reservation->status === 'Pending') {
                $hasAssignedVehicleAndDriver =
                    $reservation->vehicle_id !== null &&
                    $reservation->driver_id !== null;

                if (! $hasAssignedVehicleAndDriver) {
                    /*
                    |--------------------------------------------------------------------------
                    | Reservation needs Vehicle + Driver assignment
                    |--------------------------------------------------------------------------
                    */
                    FleetNotificationService::createWhenEnabled(
                        'reservationPending',
                        'Reservation Needs Assignment',
                        "Reservation {$reservation->reservation_number} needs an available vehicle and driver assignment before approval.",
                        true,
                        route('reservation.index')
                    );

                    FleetNotificationService::createForRolesWhenEnabled(
                        settingKey: 'reservationPending',
                        roles: [
                            'fleet_manager',
                            'dispatcher',
                        ],
                        title: 'Reservation Needs Assignment',
                        message: "Reservation {$reservation->reservation_number} needs an available vehicle and driver assignment before approval.",
                        default: true,
                        link: route('reservation.index'),
                        excludeUserId: $request->user()->id
                    );
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Reservation is ready for Approval
                    |--------------------------------------------------------------------------
                    */
                    FleetNotificationService::createWhenEnabled(
                        'reservationPending',
                        'Pending Reservation',
                        "Reservation {$reservation->reservation_number} has a vehicle and driver assigned and is waiting for approval.",
                        true,
                        route('reservation.index')
                    );

                    FleetNotificationService::createForRolesWhenEnabled(
                        settingKey: 'reservationPending',
                        roles: [
                            'fleet_manager',
                            'dispatcher',
                        ],
                        title: 'Pending Reservation',
                        message: "Reservation {$reservation->reservation_number} has a vehicle and driver assigned and is waiting for approval.",
                        default: true,
                        link: route('reservation.index'),
                        excludeUserId: $request->user()->id
                    );
                }
            } elseif ($isEmergencyRequest) {
                /*
                |--------------------------------------------------------------------------
                | Emergency Transfer
                |--------------------------------------------------------------------------
                |
                | No approval is required.
                | Notify the operational users immediately.
                |
                */
                FleetNotificationService::createWhenEnabled(
                    'reservationPending',
                    'Emergency Reservation',
                    "Emergency reservation {$reservation->reservation_number} has been automatically approved and is ready for immediate Route Planning.",
                    true,
                    route('reservation.index')
                );
                FleetNotificationService::createForRolesWhenEnabled(
                    settingKey: 'reservationPending',
                    roles: [
                        'fleet_manager',
                        'dispatcher',
                    ],
                    title: 'Emergency Reservation',
                    message: "Emergency reservation {$reservation->reservation_number} requires immediate processing.",
                    default: true,
                    link: route('reservation.index'),
                    excludeUserId: $request->user()->id
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Reservation requested successfully.',
                'reservation' => $reservation,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Display the specified reservation.
     */
    public function show(Reservation $reservation)
    {
        $this->authorize('view', $reservation);

        $reservation->load([
            'vehicle',
            'driver',
            'requester',
            'pickupHospital',
            'destinationHospital',
            'requiredCapabilities',
            // 'shipment',
        ]);

        return response()->json([
            'reservation' => $reservation,
        ]);
    }

    /**
     * Update the specified reservation.
     */
    public function update(
        Request $request,
        Reservation $reservation
    ) {
        $this->authorize('update', $reservation);

        if ($reservation->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Archived reservations cannot be edited.',
            ], 422);
        }

        $reservation->load([
            'routePlan',
            'vehicle',
            'driver',
            'dispatch',
        ]);

        if ($reservation->dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'This reservation can no longer be modified because a dispatch has already been created.',
            ], 422);
        }
        if ($reservation->routePlan) {
            return response()->json([
                'success' => false,
                'message' => 'This reservation can no longer be modified because a route plan has already been created.',
            ], 422);
        }

        $user = $request->user();

        $reservationSettings =
            $this->getReservationSettings();
        $isEmergencyRequest = Reservation::isEmergencyRequestType(
            $request->input('request_type')
        );
        $emergencyValues = $isEmergencyRequest
            ? $this->emergencyReservationValues()
            : [];
        if (
            $isEmergencyRequest &&
            ! in_array($reservation->status, ['Pending', 'Approved'], true)
        ) {
            $emergencyValues['status'] = $reservation->status;
        }
        if ($isEmergencyRequest) {
            $request->merge($emergencyValues);
        }
        $isChangingFromEmergency = Reservation::isEmergencyRequestType(
            $reservation->request_type
        ) && ! $isEmergencyRequest;
        $priorityRules = [
            'required',
            Rule::in(Reservation::supportedPriorities()),
        ];
        if ($isChangingFromEmergency) {
            $priorityRules[] = Rule::notIn(['emergency', 'Emergency']);
        }
        $minimumDate =
            $isEmergencyRequest
                ? now()->toDateString()
                : ($reservationSettings['allowSameDay']
                ? now()->toDateString()
                : now()->addDay()->toDateString());
        $maximumDate =
            now()
                ->addDays(
                    $reservationSettings['maxAdvanceDays']
                )
                ->toDateString();

        /*
        |--------------------------------------------------------------------------
        | Fleet Manager / Dispatcher
        |--------------------------------------------------------------------------
        */
        if (
            $user->hasRole(
                'fleet_manager',
                'dispatcher'
            )
        ) {
            $validator = Validator::make(
                $request->all(),
                [
                    'reservation_number' => [
                        'required',
                        'string',
                        'max:50',
                        'unique:reservations,reservation_number,'.
                            $reservation->id,
                    ],
                    'patient_name' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::requiredIf(function () use ($request) {
                            return Reservation::requiresPatientName(
                                $request->input('request_type')
                            );
                        }),
                    ],
                    'request_type' => [
                        'required',
                        Rule::in(Reservation::supportedRequestTypes()),
                    ],
                    'vehicle_id' => [
                        'nullable',
                        'exists:vehicles,id',
                    ],
                    'driver_id' => [
                        'nullable',
                        'exists:drivers,id',
                    ],
                    'pickup_location' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::requiredIf(function () use ($request) {
                            return ! Reservation::isSupplyDeliveryType(
                                $request->input('request_type')
                            );
                        }),
                    ],
                    'pickup_hospital_id' => [
                        'nullable',
                        'integer',
                        Rule::exists('hospital_facilities', 'id')
                            ->where(function ($query) {
                                $query->where('status', true);
                            }),
                    ],
                    'destination' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::requiredIf(function () use ($request) {
                            return ! Reservation::isSupplyDeliveryType(
                                $request->input('request_type')
                            );
                        }),
                    ],
                    'destination_hospital_id' => [
                        'nullable',
                        'integer',
                        Rule::exists('hospital_facilities', 'id')
                            ->where(function ($query) {
                                $query->where('status', true);
                            }),
                    ],
                    'schedule_date' => [
                        'required',
                        'date',
                        'after_or_equal:'.$minimumDate,
                        'before_or_equal:'.$maximumDate,
                    ],
                    'schedule_time' => [
                        'required',
                    ],
                    'priority' => $priorityRules,
                    'status' => [
                        'nullable',
                        Rule::in(array_unique([
                            'Pending',
                            'Approved',
                            $reservation->status,
                        ])),
                    ],
                    'contact_number' => [
                        'nullable',
                        'string',
                        'max:50',
                    ],
                    'required_capabilities' => ['nullable', 'array'],
                    'required_capabilities.*' => [
                        'integer',
                        Rule::exists('vehicle_capabilities', 'id'),
                    ],
                    'notes' => [
                        'nullable',
                        'string',
                    ],
                ],
                [
                    'schedule_date.after_or_equal' => $reservationSettings['allowSameDay']
                            ? 'Reservation date cannot be earlier than today.'
                            : 'Same-day reservations are disabled. Please select a future date.',

                    'schedule_date.before_or_equal' => 'Reservation date cannot be more than '.
                        $reservationSettings['maxAdvanceDays'].
                        ' days in advance.',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please check the reservation information.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            try {
                $validated = [
                    ...$validator->validated(),
                    ...$emergencyValues,
                ];
                /*
                |--------------------------------------------------------------------------
                | Hospital Facility Resolution
                |--------------------------------------------------------------------------
                */
                if (Reservation::isSupplyDeliveryType($validated['request_type'])) {
                    $validated['pickup_hospital_id'] = null;
                    $validated['destination_hospital_id'] = null;

                } else {
                    if (! empty($validated['pickup_hospital_id'])) {

                        $pickupHospital = HospitalFacility::query()
                            ->where('status', true)
                            ->find($validated['pickup_hospital_id']);
                        if (! $pickupHospital) {
                            throw new \Exception(
                                'The selected pickup hospital or facility is no longer available.'
                            );
                        }
                        $validated['pickup_location'] =
                            $pickupHospital->name;
                    }
                    if (! empty($validated['destination_hospital_id'])) {
                        $destinationHospital = HospitalFacility::query()
                            ->where('status', true)
                            ->find($validated['destination_hospital_id']);
                        if (! $destinationHospital) {
                            throw new \Exception(
                                'The selected destination hospital or facility is no longer available.'
                            );
                        }
                        $validated['destination'] =
                            $destinationHospital->name;
                    }
                }
                $result = $this->updateReservationUnderLock(
                    reservation: $reservation,
                    validated: $validated,
                    isEmergencyRequest: $isEmergencyRequest,
                    requirePending: false,
                    syncCapabilities: $request->has('required_capabilities'),
                );
                $reservation = $result['reservation'];
                $oldAuditValues = $result['oldAuditValues'];
                $newAuditValues = $result['newAuditValues'];

                $this->logReservationUpdate(
                    $reservation,
                    $oldAuditValues,
                    $newAuditValues
                );

                $reservation->load([
                    'vehicle',
                    'driver',
                    'requester',
                    'pickupHospital',
                    'destinationHospital',
                    'requiredCapabilities',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Reservation updated successfully.',
                    'reservation' => $reservation,
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
        }
        /*
        |--------------------------------------------------------------------------
        | Department Head - Limited Edit
        |--------------------------------------------------------------------------
        | Policy already ensures:
        | - same department
        | - Pending reservation only
        |
        | Department Head cannot modify:
        | - reservation number
        | - assigned vehicle
        | - assigned driver
        | - operational status
        | - ownership
        */
        if ($user->hasRole('department_head')) {
            if (! $user->department) {
                return response()->json([
                    'success' => false,
                    'message' => 'Your account does not have an assigned department.',
                ], 422);
            }

            $validator = Validator::make(
                $request->all(),
                [
                    'patient_name' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::requiredIf(function () use ($request) {
                            return Reservation::requiresPatientName(
                                $request->input('request_type')
                            );
                        }),
                    ],
                    'request_type' => [
                        'required',
                        Rule::in(Reservation::supportedRequestTypes()),
                    ],
                    'pickup_location' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::requiredIf(function () use ($request) {
                            return ! Reservation::isSupplyDeliveryType(
                                $request->input('request_type')
                            );
                        }),
                    ],
                    'pickup_hospital_id' => [
                        'nullable',
                        'integer',
                        Rule::exists('hospital_facilities', 'id')
                            ->where(function ($query) {
                                $query->where('status', true);
                            }),
                    ],
                    'destination' => [
                        'nullable',
                        'string',
                        'max:255',
                        Rule::requiredIf(function () use ($request) {
                            return ! Reservation::isSupplyDeliveryType(
                                $request->input('request_type')
                            );
                        }),
                    ],
                    'destination_hospital_id' => [
                        'nullable',
                        'integer',
                        Rule::exists('hospital_facilities', 'id')
                            ->where(function ($query) {
                                $query->where('status', true);
                            }),
                    ],
                    'schedule_date' => [
                        'required',
                        'date',
                        'after_or_equal:'.$minimumDate,
                        'before_or_equal:'.$maximumDate,
                    ],
                    'schedule_time' => [
                        'required',
                    ],
                    'priority' => $priorityRules,
                    'contact_number' => [
                        'nullable',
                        'string',
                        'max:50',
                    ],
                    'required_capabilities' => ['nullable', 'array'],
                    'required_capabilities.*' => [
                        'integer',
                        Rule::exists('vehicle_capabilities', 'id'),
                    ],
                    'notes' => [
                        'nullable',
                        'string',
                    ],
                ],
                [
                    'schedule_date.after_or_equal' => $reservationSettings['allowSameDay']
                            ? 'Reservation date cannot be earlier than today.'
                            : 'Same-day reservations are disabled. Please select a future date.',

                    'schedule_date.before_or_equal' => 'Reservation date cannot be more than '.
                        $reservationSettings['maxAdvanceDays'].
                        ' days in advance.',
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please check the reservation information.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = [
                ...$validator->validated(),
                ...$emergencyValues,
            ];

            /*
            |--------------------------------------------------------------------------
            | Hospital Facility Resolution
            |--------------------------------------------------------------------------
            */
            if (Reservation::isSupplyDeliveryType($validated['request_type'])) {

                $validated['pickup_hospital_id'] = null;
                $validated['destination_hospital_id'] = null;

            } else {

                /*
                |--------------------------------------------------------------------------
                | Resolve Selected Pickup Facility
                |--------------------------------------------------------------------------
                */
                if (! empty($validated['pickup_hospital_id'])) {

                    $pickupHospital = HospitalFacility::query()
                        ->where('status', true)
                        ->find($validated['pickup_hospital_id']);

                    if (! $pickupHospital) {
                        return response()->json([
                            'success' => false,
                            'message' => 'The selected pickup hospital or facility is no longer available.',
                        ], 422);
                    }

                    $validated['pickup_location'] =
                        $pickupHospital->name;
                }

                /*
                |--------------------------------------------------------------------------
                | Resolve Selected Destination Facility
                |--------------------------------------------------------------------------
                */
                if (! empty($validated['destination_hospital_id'])) {

                    $destinationHospital = HospitalFacility::query()
                        ->where('status', true)
                        ->find($validated['destination_hospital_id']);

                    if (! $destinationHospital) {
                        return response()->json([
                            'success' => false,
                            'message' => 'The selected destination hospital or facility is no longer available.',
                        ], 422);
                    }

                    $validated['destination'] =
                        $destinationHospital->name;
                }
            }

            try {
                $result = $this->updateReservationUnderLock(
                    reservation: $reservation,
                    validated: $validated,
                    isEmergencyRequest: $isEmergencyRequest,
                    requirePending: true,
                    syncCapabilities: $request->has('required_capabilities'),
                );
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            $reservation = $result['reservation'];
            $oldAuditValues = $result['oldAuditValues'];
            $newAuditValues = $result['newAuditValues'];
            $hadCompleteAssignment = $result['hadCompleteAssignment'];
            $hasCompleteAssignment = $result['hasCompleteAssignment'];
            /*
            |--------------------------------------------------------------------------
            | Notify when Vehicle + Driver assignment is completed
            |--------------------------------------------------------------------------
            */
            if (
                ! $hadCompleteAssignment &&
                $hasCompleteAssignment &&
                $reservation->status === 'Pending'
            ) {
                FleetNotificationService::createWhenEnabled(
                    'reservationPending',
                    'Pending Reservation',
                    "Reservation {$reservation->reservation_number} has a vehicle and driver assigned and is now waiting for approval.",
                    true,
                    route('reservation.index')
                );
                FleetNotificationService::createForRolesWhenEnabled(
                    settingKey: 'reservationPending',
                    roles: [
                        'fleet_manager',
                        'dispatcher',
                    ],
                    title: 'Pending Reservation',
                    message: "Reservation {$reservation->reservation_number} has a vehicle and driver assigned and is now waiting for approval.",
                    default: true,
                    link: route('reservation.index'),
                    excludeUserId: $request->user()->id
                );
            }

            $this->logReservationUpdate(
                $reservation,
                $oldAuditValues,
                $newAuditValues
            );

            $reservation->load([
                'vehicle',
                'driver',
                'requester',
                'pickupHospital',
                'destinationHospital',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reservation updated successfully.',
                'reservation' => $reservation,
            ]);
        }

        abort(
            403,
            'You do not have permission to update this reservation.'
        );
    }

    /**
     * Approve a pending reservation.
     */
    public function approve(
        Request $request,
        Reservation $reservation
    ) {
        $this->authorize('approve', $reservation);

        try {
            $result = $this->decideReservationUnderLock(
                $reservation,
                'approved',
                true,
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $reservation = $result['reservation'];
        $oldValues = $result['oldValues'];
        $reservation->refresh();

        $newValues = $this->getReservationAuditValues(
            $reservation
        );

        $this->logReservationUpdate(
            $reservation,
            $oldValues,
            $newValues
        );

        AuditLogService::log(
            module: 'Reservation Management',
            action: 'Approved',
            description: "Approved reservation {$reservation->reservation_number}.",
            record: $reservation,
            oldValues: $oldValues,
            newValues: $newValues
        );

        $this->notifyReservationDecision(
            $request,
            $reservation,
            'Approved'
        );

        return response()->json([
            'success' => true,
            'message' => 'Reservation approved successfully.',
            'reservation' => $reservation->fresh()->load([
                'vehicle',
                'driver',
                'requester',
            ]),
        ]);
    }

    /**
     * Reject a pending reservation.
     */
    public function reject(
        Request $request,
        Reservation $reservation
    ) {
        $this->authorize('reject', $reservation);

        try {
            $result = $this->decideReservationUnderLock(
                $reservation,
                'rejected',
                false,
            );
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $reservation = $result['reservation'];
        $oldValues = $result['oldValues'];
        $reservation->refresh();

        $newValues = $this->getReservationAuditValues(
            $reservation
        );

        $this->logReservationUpdate(
            $reservation,
            $oldValues,
            $newValues
        );

        AuditLogService::log(
            module: 'Reservation Management',
            action: 'Rejected',
            description: "Rejected reservation {$reservation->reservation_number}.",
            record: $reservation,
            oldValues: $oldValues,
            newValues: $newValues
        );

        $this->notifyReservationDecision(
            $request,
            $reservation,
            'Rejected'
        );

        return response()->json([
            'success' => true,
            'message' => 'Reservation rejected successfully.',
            'reservation' => $reservation->fresh()->load([
                'vehicle',
                'driver',
                'requester',
            ]),
        ]);
    }

    /**
     * Apply AI Dispatch Recommendation to a reservation.
     */
    public function applyDispatchRecommendation(
        Request $request,
        Reservation $reservation
    ) {
        $this->authorize('update', $reservation);

        $reservation->load([
            'routePlan',
            'dispatch',
            'vehicle',
            'driver',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Reservation must still be eligible for dispatch assignment
        |--------------------------------------------------------------------------
        */
        if ($reservation->dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'This reservation can no longer accept an AI recommendation because a dispatch already exists.',
            ], 422);
        }

        if (! $reservation->routePlan) {
            return response()->json([
                'success' => false,
                'message' => 'A route plan is required before applying an AI dispatch recommendation.',
            ], 422);
        }

        if ($reservation->status !== 'Approved') {
            return response()->json([
                'success' => false,
                'message' => 'Only approved reservations can accept an AI dispatch recommendation.',
            ], 422);
        }

        if ($reservation->routePlan->status !== 'Ready For Dispatch') {
            return response()->json([
                'success' => false,
                'message' => 'The route plan must be Ready For Dispatch before applying an AI recommendation.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate Request
        |--------------------------------------------------------------------------
        */
        $validator = Validator::make(
            $request->all(),
            [
                'vehicle_id' => [
                    'required',
                    'integer',
                    'exists:vehicles,id',
                ],
                'driver_id' => [
                    'required',
                    'integer',
                    'exists:drivers,id',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid AI recommendation assignment.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $validated = $validator->validated();

            if (Reservation::isSupplyDeliveryType($validated['request_type'] ?? null)) {
                $validated['shipment_id'] = $reservation->shipment_id;
            } else {
                $validated['shipment_id'] = null;
            }

            /*
            |--------------------------------------------------------------------------
            | Validate Vehicle + Driver
            |--------------------------------------------------------------------------
            */
            $vehicle = Vehicle::find(
                $validated['vehicle_id']
            );

            $driver = Driver::find(
                $validated['driver_id']
            );

            if (! $vehicle || ! $driver) {
                return response()->json([
                    'success' => false,
                    'message' => 'The recommended vehicle or driver no longer exists.',
                ], 422);
            }

            if ($vehicle->status !== 'Available') {
                return response()->json([
                    'success' => false,
                    'message' => "Vehicle {$vehicle->brand} {$vehicle->model} is currently {$vehicle->status} and cannot be assigned.",
                ], 422);
            }

            if ($driver->status !== 'Available') {
                $driverName = trim(
                    ($driver->first_name ?? '').' '.
                    ($driver->last_name ?? '')
                );

                return response()->json([
                    'success' => false,
                    'message' => "Driver {$driverName} is currently {$driver->status} and cannot be assigned.",
                ], 422);
            }

            if (
                (int) $driver->assigned_vehicle_id !==
                (int) $vehicle->id
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'The recommended driver is not assigned to the recommended vehicle.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Capture Audit State
            |--------------------------------------------------------------------------
            */
            $oldAuditValues =
                $this->getReservationAuditValues(
                    $reservation
                );

            /*
            |--------------------------------------------------------------------------
            | Apply Recommendation Atomically
            |--------------------------------------------------------------------------
            */
            DB::transaction(function () use (
                $reservation,
                $vehicle,
                $driver
            ) {
                $reservation->update([
                    'vehicle_id' => $vehicle->id,
                    'driver_id' => $driver->id,
                ]);
            });

            $reservation->refresh();

            $newAuditValues =
                $this->getReservationAuditValues(
                    $reservation
                );

            /*
            |--------------------------------------------------------------------------
            | Audit
            |--------------------------------------------------------------------------
            */
            $changedOldValues = [];
            $changedNewValues = [];

            foreach ($newAuditValues as $field => $newValue) {
                $oldValue =
                    $oldAuditValues[$field] ?? null;

                if ((string) $oldValue !== (string) $newValue) {
                    $changedOldValues[$field] =
                        $oldValue;

                    $changedNewValues[$field] =
                        $newValue;
                }
            }

            AuditLogService::log(
                module: 'Dispatch Management',
                action: 'AI Recommendation Applied',
                description: "Applied AI dispatch recommendation to reservation {$reservation->reservation_number}.",
                record: $reservation,
                oldValues: $changedOldValues,
                newValues: $changedNewValues
            );

            /*
            |--------------------------------------------------------------------------
            | Return Updated Reservation
            |--------------------------------------------------------------------------
            */
            $reservation->load([
                'vehicle',
                'driver',
                'requester',
                'routePlan',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'AI dispatch recommendation applied successfully.',
                'reservation' => $reservation,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Remove the specified reservation.
     */
    public function archive(
        Request $request,
        Reservation $reservation
    ) {
        $this->authorize(
            'archive',
            $reservation
        );

        if ($reservation->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation is already archived.',
            ], 422);
        }

        $reservation->load([
            'routePlan',
            'dispatch',
        ]);

        /*
        |--------------------------------------------------------------------------
        | Preserve existing deletion protection
        |--------------------------------------------------------------------------
        */
        if ($reservation->dispatch) {
            return response()->json([
                'success' => false,
                'message' => 'This reservation cannot be archived because it already has a dispatch.',
            ], 422);
        }

        if ($reservation->routePlan) {
            return response()->json([
                'success' => false,
                'message' => 'This reservation cannot be archived because it already has a route plan.',
            ], 422);
        }

        $oldValues =
            $this->getReservationAuditValues(
                $reservation
            );

        DB::transaction(function () use (
            $request,
            $reservation,
            $oldValues
        ) {
            $reservation->update([
                'archived_at' => now(),
                'archived_by' => $request->user()->id,
            ]);

            AuditLogService::log(
                module: 'Reservation Management',
                action: 'Archived',
                description: "Archived reservation {$reservation->reservation_number}.",
                record: $reservation,
                oldValues: $oldValues,
                newValues: [
                    'archived_at' => $reservation->archived_at
                        ?->toDateTimeString(),

                    'archived_by' => $request->user()->id,
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Reservation archived successfully.',
        ]);
    }

    public function restore(
        Request $request,
        Reservation $reservation
    ) {
        $this->authorize(
            'restore',
            $reservation
        );

        if (! $reservation->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Reservation is not archived.',
            ], 422);
        }

        $oldValues = [
            'archived_at' => $reservation->archived_at
                ?->toDateTimeString(),

            'archived_by' => $reservation->archived_by,
        ];

        $reservation->update([
            'archived_at' => null,
            'archived_by' => null,
        ]);

        AuditLogService::log(
            module: 'Reservation Management',
            action: 'Restored',
            description: "Restored reservation {$reservation->reservation_number}.",
            record: $reservation,
            oldValues: $oldValues,
            newValues: [
                'archived_at' => null,
                'archived_by' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Reservation restored successfully.',
            'reservation' => $reservation->fresh()->load([
                'vehicle',
                'driver',
                'requester',
            ]),
        ]);
    }

    /**
     * Bulk delete reservations.
     */
    public function bulkArchive(Request $request)
    {
        $this->authorize(
            'archiveAny',
            Reservation::class
        );

        $validator = Validator::make(
            $request->all(),
            [
                'ids' => [
                    'required',
                    'array',
                    'min:1',
                ],
                'ids.*' => [
                    'integer',
                    'exists:reservations,id',
                ],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please select valid reservations.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $archivedIds = [];

        try {
            $reservations =
                Reservation::with([
                    'routePlan',
                    'dispatch',
                ])
                    ->whereIn(
                        'id',
                        $validator->validated()['ids']
                    )
                    ->whereNull('archived_at')
                    ->get();

            DB::transaction(
                function () use (
                    $reservations,
                    &$archivedIds,
                    $request
                ) {
                    foreach ($reservations as $reservation) {

                        if (
                            $reservation->routePlan ||
                            $reservation->dispatch
                        ) {
                            continue;
                        }

                        $oldValues =
                            $this->getReservationAuditValues(
                                $reservation
                            );

                        $reservation->update([
                            'archived_at' => now(),
                            'archived_by' => $request->user()->id,
                        ]);

                        AuditLogService::log(
                            module: 'Reservation Management',
                            action: 'Archived',
                            description: "Archived reservation {$reservation->reservation_number}.",
                            record: $reservation,
                            oldValues: $oldValues,
                            newValues: [
                                'archived_at' => $reservation->archived_at
                                    ?->toDateTimeString(),

                                'archived_by' => $request->user()->id,
                            ]
                        );

                        $archivedIds[] =
                            $reservation->id;
                    }
                }
            );

            if (empty($archivedIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Selected reservations cannot be archived because they are already linked to route planning or dispatch.',
                    'archived_ids' => [],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => count($archivedIds) === 1
                        ? 'Reservation archived successfully.'
                        : count($archivedIds).
                            ' reservations archived successfully.',
                'archived_ids' => $archivedIds,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to archive reservations.',
            ], 500);
        }
    }

    /**
     * Reservation statistics.
     */
    public function stats(Request $request)
    {
        $this->authorize('viewAny', Reservation::class);

        $user =
            $request->user();
        $query =
            Reservation::query()
                ->whereNull('archived_at');

        if ($user->hasRole('driver')) {
            $driverId =
                $user->driverProfile?->id;
            if ($driverId) {
                $query->where(
                    'driver_id',
                    $driverId
                );
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (
            $user->hasRole(
                'department_head'
            )
        ) {
            if ($user->department) {
                $query->where(
                    'department',
                    $user->department
                );
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return response()->json([
            'total' => (clone $query)->count(),
            'pending' => (clone $query)
                ->where(
                    'status',
                    'Pending'
                )
                ->count(),
            'approved' => (clone $query)
                ->where(
                    'status',
                    'Approved'
                )
                ->count(),
            'scheduled' => (clone $query)
                ->where(
                    'status',
                    'Scheduled'
                )
                ->count(),
            'completed' => (clone $query)
                ->where(
                    'status',
                    'Completed'
                )
                ->count(),
            'cancelled' => (clone $query)
                ->where(
                    'status',
                    'Cancelled'
                )
                ->count(),
        ]);
    }

    /**
     * Notify requester + Fleet Manager + Dispatcher
     * when a reservation is approved or rejected.
     */
    private function notifyReservationDecision(
        Request $request,
        Reservation $reservation,
        string $decision
    ): void {
        $reservationNumber =
            $reservation->reservation_number;

        $link =
            route('reservation.index');

        /*
        |--------------------------------------------------------------------------
        | Notification Content
        |--------------------------------------------------------------------------
        */
        if ($decision === 'Approved') {
            $settingKey =
                'reservationApproved';

            $title =
                'Reservation Approved';

            $requesterMessage =
                "Your reservation {$reservationNumber} has been approved and can proceed to Route Planning.";

            $roleMessage =
                "Reservation {$reservationNumber} has been approved and can proceed to Route Planning.";
        } else {
            $settingKey =
                'reservationRejected';

            $title =
                'Reservation Rejected';

            $requesterMessage =
                "Your reservation {$reservationNumber} has been rejected.";

            $roleMessage =
                "Reservation {$reservationNumber} has been rejected.";
        }

        /*
        |--------------------------------------------------------------------------
        | Notify Department Head / Original Requester
        |--------------------------------------------------------------------------
        */
        $requester =
            $reservation->requester;

        if (
            $requester &&
            FleetNotificationService::enabled(
                $settingKey,
                true
            ) &&
            FleetNotificationService::userCanAccessLink(
                $requester,
                $link
            )
        ) {
            FleetNotification::create([
                'user_id' => $requester->id,

                'title' => $title,

                'message' => $requesterMessage,

                'status' => 'Unread',

                'link' => $link,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Notify Fleet Manager + Dispatcher
        |--------------------------------------------------------------------------
        |
        | Exclude the current approving/rejecting user
        | to avoid duplicate self-notification.
        |--------------------------------------------------------------------------
        */
        FleetNotificationService::createForRolesWhenEnabled(
            settingKey: $settingKey,
            roles: [
                'fleet_manager',
                'dispatcher',
            ],
            title: $title,
            message: $roleMessage,
            default: true,
            link: $link,
            excludeUserId: $request->user()->id
        );
    }

    private function generateReservationNumber(): string
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $prefix = "RES-{$year}-{$month}";
        $latestReservation = Reservation::query()
            ->where(
                'reservation_number',
                'like',
                $prefix.'%'
            )
            ->orderByDesc('id')
            ->first();

        if (! $latestReservation) {
            $nextSequence = 1;
        } else {
            $lastSequence = (int) substr(
                $latestReservation->reservation_number,
                -3
            );
            $nextSequence = $lastSequence + 1;
        }

        return $prefix.str_pad(
            $nextSequence,
            3,
            '0',
            STR_PAD_LEFT
        );
    }

    public function nextNumber()
    {
        $this->authorize('create', Reservation::class);

        return response()->json([
            'success' => true,
            'reservation_number' => $this->generateReservationNumber(),
        ]);
    }

    public function capabilities()
    {
        $this->authorize('viewAny', Reservation::class);

        return response()->json([
            'capabilities' => VehicleCapability::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description']),
        ]);
    }

    public function eligibleAssignments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Reservation::class);

        $validated = $request->validate([
            'schedule_date' => ['required', 'date'],
            'schedule_time' => ['required', 'date_format:H:i'],
            'reservation_id' => ['nullable', 'integer', 'exists:reservations,id'],
            'required_capabilities' => ['nullable', 'array'],
            'required_capabilities.*' => ['integer', 'exists:vehicle_capabilities,id'],
        ]);
        $reservation = null;

        if (! empty($validated['reservation_id'])) {
            $reservation = Reservation::findOrFail($validated['reservation_id']);
            $this->authorize('view', $reservation);
        }

        return response()->json([
            'vehicles' => $this->assignmentEligibility->eligibleVehicles(
                $validated['schedule_date'],
                $validated['schedule_time'],
                $this->getReservationSettings()['defaultDurationHours'],
                $reservation,
                $validated['required_capabilities'] ?? [],
            ),
        ]);
    }

    private function getReservationAuditValues(
        Reservation $reservation
    ): array {
        $reservation->loadMissing('requiredCapabilities');

        /*
        |--------------------------------------------------------------------------
        | Audit-safe Reservation Snapshot
        |--------------------------------------------------------------------------
        |
        | Do not unnecessarily store patient/contact/notes information
        | inside the security audit trail.
        |--------------------------------------------------------------------------
        */
        return [
            'reservation_number' => $reservation->reservation_number,

            'request_type' => $reservation->request_type,

            'shipment_id' => $reservation->shipment_id,

            'vehicle_id' => $reservation->vehicle_id,

            'driver_id' => $reservation->driver_id,

            'department' => $reservation->department,

            'pickup_location' => $reservation->pickup_location,

            'pickup_hospital_id' => $reservation->pickup_hospital_id,

            'destination' => $reservation->destination,

            'destination_hospital_id' => $reservation->destination_hospital_id,

            'schedule_date' => $reservation->schedule_date
                ?->format('Y-m-d'),

            'schedule_time' => $reservation->schedule_time,

            'priority' => $reservation->priority,

            'status' => $reservation->status,

            'requested_by' => $reservation->requested_by,

            'required_capabilities' => $reservation->requiredCapabilities
                ->pluck('slug')
                ->sort()
                ->implode(','),
        ];
    }

    private function logReservationUpdate(
        Reservation $reservation,
        array $oldValues,
        array $newValues
    ): void {
        $changedOldValues = [];
        $changedNewValues = [];

        foreach ($newValues as $field => $newValue) {
            $oldValue =
                $oldValues[$field] ?? null;

            if ((string) $oldValue !== (string) $newValue) {
                $changedOldValues[$field] =
                    $oldValue;

                $changedNewValues[$field] =
                    $newValue;
            }
        }

        if (empty($changedNewValues)) {
            return;
        }

        $oldStatus =
            $oldValues['status'] ?? null;

        $newStatus =
            $newValues['status'] ?? null;

        /*
        |--------------------------------------------------------------------------
        | Determine Meaningful Reservation Action
        |--------------------------------------------------------------------------
        */
        $action = 'Updated';

        if (
            $oldStatus !== $newStatus &&
            $newStatus
        ) {
            $action = match ($newStatus) {
                'Approved' => 'Approved',

                'Rejected' => 'Rejected',

                'Cancelled' => 'Cancelled',

                'Scheduled' => 'Scheduled',

                'Completed' => 'Completed',

                default => 'Updated',
            };
        }

        $description = match ($action) {
            'Approved' => "Approved reservation {$reservation->reservation_number}.",

            'Rejected' => "Rejected reservation {$reservation->reservation_number}.",

            'Cancelled' => "Cancelled reservation {$reservation->reservation_number}.",

            'Scheduled' => "Scheduled reservation {$reservation->reservation_number}.",

            'Completed' => "Completed reservation {$reservation->reservation_number}.",

            default => "Updated reservation {$reservation->reservation_number}.",
        };

        AuditLogService::log(
            module: 'Reservation Management',
            action: $action,
            description: $description,
            record: $reservation,
            oldValues: $changedOldValues,
            newValues: $changedNewValues
        );
    }

    public function supplyDeliveryShipments()
    {
        $this->authorize('create', Reservation::class);

        return response()->json([
            'success' => true,
            'integration_ready' => true,
            'integration_connected' => false,
            'shipments' => [],
            'message' => 'Logistics integration is not connected yet.',
        ]);
    }

    public function hospitalFacilities(Request $request)
    {
        $this->authorize('create', Reservation::class);

        $search = trim(
            (string) $request->input('search', '')
        );

        $facilities = HospitalFacility::query()
            ->where('status', true)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where(
                        'name',
                        'like',
                        "%{$search}%"
                    )
                        ->orWhere(
                            'address',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'city',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'province',
                            'like',
                            "%{$search}%"
                        );
                });
            })
            ->orderBy('name')
            ->limit(10)
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

    private function syncRequiredCapabilities(Reservation $reservation, array $caps): void
    {
        $capIds = VehicleCapability::query()
            ->whereIn('id', $caps)
            ->orWhereIn('slug', $caps)
            ->pluck('id');

        $reservation->requiredCapabilities()->sync($capIds);
    }
}
