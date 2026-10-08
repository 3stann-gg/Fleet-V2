<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Vehicle;
use App\Models\Driver;
use App\Models\FleetSetting;
use App\Models\VehicleCapability;
use App\Services\AuditLogService;
use App\Services\VehicleOperationalHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class VehicleController extends Controller
{   
    use AuthorizesRequests;
    /**
     * Display a listing of the resource.
     */

    private function getVehicleSettings(): array
    {
        $record = FleetSetting::query()
            ->latest('id')
            ->first();

        $settings = $record?->settings ?? [];

        $vehicleSettings = $settings['vehicles'] ?? [];

        $allowedStatuses = [
            'Available',
            'On Trip',
            'Maintenance',
            'Out of Service',
        ];

        $defaultStatus =
            $vehicleSettings['defaultStatus'] ?? 'Available';

        if (!in_array($defaultStatus, $allowedStatuses, true)) {
            $defaultStatus = 'Available';
        }

        return [
            'requirePlateNumber' =>
                $vehicleSettings['requirePlateNumber'] ?? true,
            
            'requireDepartment' =>
                $vehicleSettings['requireDepartment'] ?? false,

            'defaultStatus' =>
                $defaultStatus,
        ];
    }

    public function index(Request $request)
    {   
        $this->authorize('viewAny', Vehicle::class);

        $user = $request->user();
        $showDriverDetails = $user->hasPermission('drivers.view');


        $query = Vehicle::with([
            'drivers',
            'lastCompletedMaintenance',
            'capabilities',
        ]);

        if ($request->boolean('show_archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $query =
        $this->applyVehicleVisibility(
            $query,
            $user
        );

        if ($request->filled('search')) {

            $search = $request->search;

            $query->where(function ($q) use ($search, $showDriverDetails) {
                $q->where('brand', 'like', "%{$search}%")
                ->orWhere('model', 'like', "%{$search}%")
                ->orWhere('plate_number', 'like', "%{$search}%")
                ->orWhere('department', 'like', "%{$search}%");

                if ($showDriverDetails) {
                    $q->orWhereHas('drivers', function ($driver) use ($search) {
                    $driver->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%");
                    });
                }
            });
        }

        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('vehicle_type', $request->type);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        $sort = $request->get('sort', 'id');
        $direction = $request->get('direction', 'asc');

        $query->orderBy($sort, $direction);

        $vehicles = $query->get()->map(function ($vehicle) use ($showDriverDetails) {
            $driver = $showDriverDetails
                ? $vehicle->drivers->first()
                : null;

            return [
                'id'                => $vehicle->id,
                'plate_number'      => $vehicle->plate_number,
                'vehicle_type'      => $vehicle->vehicle_type,
                'department'        => $vehicle->department,
                'brand'             => $vehicle->brand,
                'model'             => $vehicle->model,
                'purchase_date'     => $vehicle->purchase_date,
                'insurance_expiry'  => $vehicle->insurance_expiry,
                'capacity'          => $vehicle->capacity,
                'fuel_type'         => $vehicle->fuel_type,
                'powertrain_type'   => $vehicle->powertrain_type,
                'powertrain_label'  => $vehicle->powertrain_label,
                'tank_capacity'     => $vehicle->tank_capacity,
                'current_fuel'      => $vehicle->current_fuel,
                'current_odometer'  => $vehicle->current_odometer,
                'battery_capacity_kwh' => $vehicle->battery_capacity_kwh,
                'battery_level_percent' => $vehicle->battery_level_percent,
                'estimated_range_km' => $vehicle->estimated_range_km,
                'charging_port_type' => $vehicle->charging_port_type,
                'status'            => $vehicle->status,
                'readiness_status'  => $vehicle->readiness_status,
                'readiness_label'   => $vehicle->readiness_label,
                'fuel_level_percent' => $vehicle->fuel_level_percent,
                'capabilities'      => $vehicle->capabilities->map(fn ($cap) => [
                    'id' => $cap->id,
                    'name' => $cap->name,
                    'slug' => $cap->slug,
                ]),

                'driver_name' => $driver
                    ? $driver->first_name . ' ' . $driver->last_name
                    : null,

                'driver_license' => $driver?->license_number,

                'notes' => $vehicle->notes,
                'archived_at' => $vehicle->archived_at,
                /*
                |--------------------------------------------------------------------------
                | Include assigned drivers for Fuel Management
                |--------------------------------------------------------------------------
                */
                'drivers' => $showDriverDetails
                    ? $vehicle->drivers->map(function ($driver) {
                    return [
                        'id' => $driver->id,
                        'first_name' => $driver->first_name,
                        'last_name' => $driver->last_name,
                        'license_number' => $driver->license_number,
                        'status' => $driver->status,
                    ];
                    })->values()
                    : [],
                // Last Service from Last Maintenance Complete
                'last_service' =>
                    $vehicle->lastCompletedMaintenance?->completion_date
                        ?->format('Y-m-d'),
                'last_service_record' =>
                    $vehicle->lastCompletedMaintenance
                        ? [
                            'id' =>
                                $vehicle->lastCompletedMaintenance->id,
                            'maintenance_number' =>
                                $vehicle->lastCompletedMaintenance->maintenance_number,
                            'maintenance_type' =>
                                $vehicle->lastCompletedMaintenance->maintenance_type,
                            'completion_date' =>
                                optional(
                                    $vehicle->lastCompletedMaintenance->completion_date
                                )->format('Y-m-d'),
                            'cost' =>
                                $vehicle->lastCompletedMaintenance->cost,
                        ]
                        : null,
            
            ];
        });
        
        // if AJAX request
        if ($request->expectsJson()) {

            return response()->json([
                'vehicles' => $vehicles
            ]);

        }

        $vehiclePermissions = [
            'role' => $request->user()->role,
            'canCreate' =>
                $request->user()->can(
                    'create',
                    Vehicle::class
                ),
            'canUpdate' =>
                $request->user()->hasPermission('vehicles.update'),
            'canArchive' =>
                $request->user()->hasPermission('vehicles.archive'),
            'canBulkArchive' =>
                $request->user()->hasPermission('vehicles.archive'),
            'canRestore' =>
                $request->user()->hasPermission('vehicles.archive'),

            'canOpenMaintenance' =>
                $request->user()->canViewModule('maintenance'),
        ];
        return view(
            'fleet.index',
            compact('vehiclePermissions')
        );
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {   
        $this->authorize('create', Vehicle::class);

        $vehicleSettings = $this->getVehicleSettings();
        $requirePlateNumber =
            (bool) $vehicleSettings['requirePlateNumber'];
        $requireDepartment =
            (bool) $vehicleSettings['requireDepartment'];
        if (!$request->filled('status')) {
            $request->merge([
                'status' => $vehicleSettings['defaultStatus'],
            ]);
        }

        $powertrainType = $request->string('powertrain_type')->toString();
        $isMotorcycle = $request->string('vehicle_type')->toString() === 'Motorcycle';
        $usesLiquidFuel = in_array(
            $powertrainType,
            ['gasoline', 'diesel', 'hybrid', 'plug_in_hybrid'],
            true
        );
        $supportsBatteryHealth = in_array($powertrainType, ['electric', 'plug_in_hybrid'], true);
        $allowedFuelTypes = match ($powertrainType) {
            'diesel' => ['Diesel'],
            'gasoline' => ['Gasoline', 'Premium Gasoline'],
            'electric' => ['Electric'],
            default => ['Diesel', 'Gasoline', 'Premium Gasoline'],
        };

        if ($powertrainType === 'electric') {
            $request->merge([
                'fuel_type' => 'Electric',
                'tank_capacity' => null,
                'current_fuel' => 0,
            ]);
        }

        $validator = Validator::make(
            $request->all(),
            [
                'plate_number' => [
                    $requirePlateNumber
                        ? 'required'
                        : 'nullable',
                    'string',
                    'max:255',
                    Rule::unique(
                        'vehicles',
                        'plate_number'
                    ),
                ],
                'vehicle_type' => [
                    'required',
                    Rule::in(Vehicle::TYPE_OPTIONS),
                ],
                'department' => [
                    $requireDepartment
                        ? 'required'
                        : 'nullable',

                    Rule::in([
                        'Emergency',
                        'Outpatient',
                        'Laboratory',
                        'Facilities',
                        'Admin',
                        'Logistics',
                    ]),
                ],
                'brand'             => 'required',
                'model'             => 'required',
                'purchase_date'     => 'nullable|date',
                'insurance_expiry'  => 'nullable|date',
                'capacity'          => 'required|integer',
                'powertrain_type' => [
                    'required',
                    Rule::in(
                        $isMotorcycle
                            ? Vehicle::MOTORCYCLE_POWERTRAIN_TYPES
                            : array_keys(Vehicle::POWERTRAIN_LABELS)
                    ),
                ],
                'fuel_type' => [
                    Rule::requiredIf($usesLiquidFuel),
                    'nullable',
                    Rule::in($allowedFuelTypes),
                ],
                'tank_capacity' => [
                    Rule::requiredIf($usesLiquidFuel),
                    'nullable',
                    'numeric',
                    'min:0.01',
                ],
                'current_fuel' => [
                    Rule::requiredIf($usesLiquidFuel),
                    'nullable',
                    'numeric',
                    'min:0',
                    ...($usesLiquidFuel ? ['lte:tank_capacity'] : []),
                ],
                'current_odometer' => [
                    'required',
                    'numeric',
                    'min:0',
                ],
                'battery_capacity_kwh' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'battery_level_percent' => [
                    'nullable',
                    'numeric',
                    'between:0,100',
                ],
                'estimated_range_km' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'charging_port_type' => [
                    'nullable',
                    'string',
                    'max:100',
                ],
                'battery_health_percent' => [
                    Rule::prohibitedIf(!$supportsBatteryHealth),
                    'nullable',
                    'numeric',
                    'between:0,100',
                ],
                'battery_last_inspection_date' => [
                    Rule::prohibitedIf(!$supportsBatteryHealth),
                    'nullable',
                    'date',
                ],
                'engine_displacement_cc' => [
                    Rule::prohibitedIf(!$isMotorcycle || $powertrainType === 'electric'),
                    'nullable',
                    'integer',
                    'min:0',
                ],
                'transmission_type' => [
                    Rule::prohibitedIf(!$isMotorcycle),
                    'nullable',
                    Rule::in(Vehicle::TRANSMISSION_TYPES),
                ],
                'helmet_available' => [
                    Rule::prohibitedIf(!$isMotorcycle),
                    'nullable',
                    'boolean',
                ],
                'cargo_box_available' => [
                    Rule::prohibitedIf(!$isMotorcycle),
                    'nullable',
                    'boolean',
                ],
                'cargo_capacity_kg' => [
                    Rule::prohibitedIf(!$isMotorcycle),
                    'nullable',
                    'numeric',
                    'min:0',
                ],
                'registration_expiry' => [
                    Rule::prohibitedIf(!$isMotorcycle),
                    'nullable',
                    'date',
                ],
                'status' => [
                    'required',
                    Rule::in([
                        'Available',
                        'On Trip',
                        'Maintenance',
                        'Out of Service',
                    ]),
                ],
                'readiness_status' => [
                    'nullable',
                    Rule::in(array_keys(Vehicle::READINESS_LABELS)),
                ],
                'assigned_driver_id'=> 'nullable|exists:drivers,id',
                'capabilities' => ['nullable', 'array'],
                'capabilities.*' => [
                    'integer',
                    Rule::exists((new VehicleCapability)->getTable(), 'id'),
                ],
                'notes'             => 'nullable|string',
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Please check the vehicle information.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        if (array_key_exists('battery_health_percent', $validated)) {
            $validated['battery_health_updated_at'] = $validated['battery_health_percent'] === null
                ? null
                : now();
        }

        $driverId = $validated['assigned_driver_id'] ?? null;
        $capabilityIds = $validated['capabilities'] ?? [];

        unset($validated['assigned_driver_id'], $validated['capabilities']);

        $vehicle = Vehicle::create($validated);

        $vehicle->capabilities()->sync($capabilityIds);

        if ($driverId) {
            Driver::where('id', $driverId)
                ->update([
                    'assigned_vehicle_id' => $vehicle->id,
                ]);
        }

        $vehicle->load(['drivers', 'capabilities']);

        AuditLogService::log(
            module: 'Vehicle Management',
            action: 'Created',
            description:
                "Created vehicle {$vehicle->plate_number}.",
            record: $vehicle,
            newValues:
                $this->getVehicleAuditValues(
                    $vehicle
                )
        );

        return response()->json([
            'success' => true,
            'message' => 'Vehicle requested successfully.',
            'vehicle' => $vehicle,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(
        Request $request,
        Vehicle $vehicle,
        VehicleOperationalHealthService $healthService
    ) {
        $this->authorize(
            'view',
            $vehicle
        );

        $user =
            $request->user();

        /*
        |--------------------------------------------------------------------------
        | Driver - Assigned Vehicle Only
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('driver')) {
            $driver =
                $user->driverProfile;

            abort_unless(
                $driver &&
                (int) $driver->assigned_vehicle_id ===
                (int) $vehicle->id,
                403
            );
        }

        $relations = [
            'lastCompletedMaintenance',
            'capabilities',
            'sanitationLogs' => fn ($query) => $query->latest('id')->limit(10),
            'latestPreTripChecklist',
            'latestPostTripChecklist',
            'activeSanitationLog',
        ];

        if ($user->hasPermission('drivers.view') && !$user->hasRole('driver')) {
            $relations[] = 'drivers';
        }

        $vehicle->load($relations);

        $availableDrivers = $user->hasRole('fleet_manager')
            ? Driver::whereNull('assigned_vehicle_id')
                ->orWhere('assigned_vehicle_id', $vehicle->id)
                ->get()
            : collect();

        return response()->json([
            'vehicle' =>
                $vehicle,

            'drivers' =>
                $availableDrivers,

            'operational_health' =>
                $healthService->summarize($vehicle),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        Request $request,
        Vehicle $vehicle
    ) {
        $this->authorize('update', $vehicle);

        $user = $request->user();

        $vehicle->load('drivers');

        $oldAuditValues =
            $this->getVehicleAuditValues(
                $vehicle
            );

        /*
        |--------------------------------------------------------------------------
        | Fleet Manager
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('fleet_manager')) {

            $vehicleSettings = $this->getVehicleSettings();
            $requirePlateNumber =
                (bool) $vehicleSettings['requirePlateNumber'];
            $requireDepartment =
                (bool) ($vehicleSettings['requireDepartment'] ?? false);
            $powertrainType = $request->string('powertrain_type')->toString();
            $usesLiquidFuel = in_array(
                $powertrainType,
                ['gasoline', 'diesel', 'hybrid', 'plug_in_hybrid'],
                true
            );
            $supportsBatteryHealth = in_array($powertrainType, ['electric', 'plug_in_hybrid'], true);
            $isMotorcycle = $request->string('vehicle_type')->toString() === 'Motorcycle';
            $allowedFuelTypes = match ($powertrainType) {
                'diesel' => ['Diesel'],
                'gasoline' => ['Gasoline', 'Premium Gasoline'],
                'electric' => ['Electric'],
                default => ['Diesel', 'Gasoline', 'Premium Gasoline'],
            };

            $validator = Validator::make(
                $request->all(),
                [
                    'plate_number' => [
                        $requirePlateNumber
                            ? 'required'
                            : 'nullable',
                        'string',
                        'max:255',
                        Rule::unique(
                            'vehicles',
                            'plate_number'
                        )->ignore($vehicle->id),
                    ],
                    'vehicle_type' => [
                        'required',
                        Rule::in(Vehicle::TYPE_OPTIONS),
                    ],
                    'department' => [
                        $requireDepartment
                            ? 'required'
                            : 'nullable',
                        Rule::in([
                            'Emergency',
                            'Outpatient',
                            'Laboratory',
                            'Facilities',
                            'Admin',
                            'Logistics',
                        ]),
                    ],
                    /*
                    |--------------------------------------------------------------------------
                    | Brand / Model
                    |--------------------------------------------------------------------------
                    | Wala sila currently sa Edit Vehicle form,
                    | kaya hindi sila dapat required sa update.
                    |--------------------------------------------------------------------------
                    */
                    'brand' => [
                        'sometimes',
                        'string',
                        'max:255',
                    ],
                    'model' => [
                        'sometimes',
                        'string',
                        'max:255',
                    ],
                    'purchase_date' => [
                        'sometimes',
                        'nullable',
                        'date',
                    ],
                    'insurance_expiry' => [
                        'sometimes',
                        'nullable',
                        'date',
                    ],
                    'capacity' => [
                        'required',
                        'integer',
                        'min:0',
                    ],
                    'powertrain_type' => [
                        'required',
                        Rule::in(
                            $isMotorcycle
                                ? Vehicle::MOTORCYCLE_POWERTRAIN_TYPES
                                : array_keys(Vehicle::POWERTRAIN_LABELS)
                        ),
                    ],
                    'fuel_type' => [
                        Rule::requiredIf($usesLiquidFuel),
                        'nullable',
                        Rule::in($allowedFuelTypes),
                    ],
                    'tank_capacity' => [
                        Rule::requiredIf($usesLiquidFuel),
                        'nullable',
                        'numeric',
                        'min:0.01',
                    ],
                    'battery_capacity_kwh' => [
                        'sometimes',
                        'nullable',
                        'numeric',
                        'min:0',
                    ],
                    'battery_level_percent' => [
                        'sometimes',
                        'nullable',
                        'numeric',
                        'between:0,100',
                    ],
                    'estimated_range_km' => [
                        'sometimes',
                        'nullable',
                        'numeric',
                        'min:0',
                    ],
                    'charging_port_type' => [
                        'sometimes',
                        'nullable',
                        'string',
                        'max:100',
                    ],
                    'battery_health_percent' => [
                        Rule::prohibitedIf(!$supportsBatteryHealth),
                        'nullable',
                        'numeric',
                        'between:0,100',
                    ],
                    'battery_last_inspection_date' => [
                        Rule::prohibitedIf(!$supportsBatteryHealth),
                        'nullable',
                        'date',
                    ],
                    'engine_displacement_cc' => [
                        Rule::prohibitedIf(!$isMotorcycle || $powertrainType === 'electric'),
                        'nullable',
                        'integer',
                        'min:0',
                    ],
                    'transmission_type' => [
                        Rule::prohibitedIf(!$isMotorcycle),
                        'nullable',
                        Rule::in(Vehicle::TRANSMISSION_TYPES),
                    ],
                    'helmet_available' => [
                        Rule::prohibitedIf(!$isMotorcycle),
                        'nullable',
                        'boolean',
                    ],
                    'cargo_box_available' => [
                        Rule::prohibitedIf(!$isMotorcycle),
                        'nullable',
                        'boolean',
                    ],
                    'cargo_capacity_kg' => [
                        Rule::prohibitedIf(!$isMotorcycle),
                        'nullable',
                        'numeric',
                        'min:0',
                    ],
                    'registration_expiry' => [
                        Rule::prohibitedIf(!$isMotorcycle),
                        'nullable',
                        'date',
                    ],
                    /*
                    |--------------------------------------------------------------------------
                    | Fuel + Odometer
                    |--------------------------------------------------------------------------
                    | Hindi sila galing sa Vehicle Edit form.
                    | Existing values remain unchanged.
                    |--------------------------------------------------------------------------
                    */
                    'current_fuel' => [
                        'sometimes',
                        'numeric',
                        'min:0',
                    ],
                    'current_odometer' => [
                        'sometimes',
                        'numeric',
                        'min:0',
                    ],
                    'status' => [
                        'required',
                        Rule::in([
                            'Available',
                            'On Trip',
                            'Maintenance',
                            'Out of Service',
                        ]),
                    ],
                    'readiness_status' => [
                        'sometimes',
                        'nullable',
                        Rule::in(array_keys(Vehicle::READINESS_LABELS)),
                    ],
                    'assigned_driver_id' => [
                        'nullable',
                        'exists:drivers,id',
                    ],
                    'capabilities' => ['sometimes', 'array'],
                    'capabilities.*' => [
                        'integer',
                        Rule::exists((new VehicleCapability)->getTable(), 'id'),
                    ],
                    'notes' => [
                        'nullable',
                        'string',
                    ],
                ]
            );

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Please check the vehicle information.',
                    'errors' =>
                        $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            if (array_key_exists('battery_health_percent', $validated)) {
                $validated['battery_health_updated_at'] = $validated['battery_health_percent'] === null
                    ? null
                    : now();
            }

            if (!$isMotorcycle) {
                $validated = array_merge($validated, [
                    'engine_displacement_cc' => null,
                    'transmission_type' => null,
                    'helmet_available' => null,
                    'cargo_box_available' => null,
                    'cargo_capacity_kg' => null,
                    'registration_expiry' => null,
                ]);
            } elseif ($powertrainType === 'electric') {
                $validated['engine_displacement_cc'] = null;
            }
            /*
            |--------------------------------------------------------------------------
            | Tank Capacity Safety
            |--------------------------------------------------------------------------
            | Vehicle Edit does not submit current_fuel.
            | Compare against the vehicle's existing fuel value instead.
            |--------------------------------------------------------------------------
            */
            if (
                isset($validated['tank_capacity']) &&
                $vehicle->current_fuel !== null &&
                (float) $validated['tank_capacity'] <
                    (float) $vehicle->current_fuel
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Tank capacity cannot be lower than the vehicle\'s current fuel level.',
                ], 422);
            }

            $driverFieldProvided =
                array_key_exists(
                    'assigned_driver_id',
                    $validated
                );

            $driverId =
                $validated['assigned_driver_id']
                ?? null;

            $capabilityIds = $validated['capabilities'] ?? null;

            unset(
                $validated['assigned_driver_id'],
                $validated['capabilities']
            );

            $vehicle->update($validated);

            if ($capabilityIds !== null) {
                $vehicle->capabilities()->sync($capabilityIds);
            }

            /*
            |--------------------------------------------------------------------------
            | Driver Assignment
            |--------------------------------------------------------------------------
            */
            if ($driverFieldProvided) {

                Driver::where(
                    'assigned_vehicle_id',
                    $vehicle->id
                )->update([
                    'assigned_vehicle_id' => null,
                ]);

                if ($driverId) {
                    Driver::where(
                        'id',
                        $driverId
                    )->update([
                        'assigned_vehicle_id' =>
                            $vehicle->id,
                    ]);
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Dispatcher
        |--------------------------------------------------------------------------
        */
        elseif ($user->hasRole('dispatcher')) {

            $validated =
                $request->validate([
                    'status' => [
                        'sometimes',
                        Rule::in([
                            'Available',
                            'Out of Service',
                        ]),
                    ],
                    'readiness_status' => [
                        'sometimes',
                        Rule::in(array_keys(Vehicle::READINESS_LABELS)),
                    ],
                    'current_fuel' => [
                        'sometimes',
                        'numeric',
                        'min:0',
                    ],
                    'current_odometer' => [
                        'sometimes',
                        'numeric',
                        'min:0',
                    ],
                    'notes' => [
                        'sometimes',
                        'nullable',
                        'string',
                    ],
                ]);

            if (
                isset($validated['current_fuel']) &&
                $vehicle->tank_capacity !== null &&
                (float) $validated['current_fuel'] >
                    (float) $vehicle->tank_capacity
            ) {
                return response()->json([
                    'success' => false,
                    'message' =>
                        'Current fuel cannot exceed tank capacity.',
                ], 422);
            }

            if (empty($validated)) {
                abort(
                    403,
                    'You do not have permission to modify these vehicle fields.'
                );
            }

            $vehicle->update($validated);
        }

        /*
        |--------------------------------------------------------------------------
        | Maintenance
        |--------------------------------------------------------------------------
        */
        elseif ($user->hasRole('maintenance')) {

            $validated =
                $request->validate([
                    'status' => [
                        'sometimes',
                        Rule::in([
                            'Available',
                            'Maintenance',
                            'Out of Service',
                        ]),
                    ],
                    'readiness_status' => [
                        'sometimes',
                        Rule::in([
                            'ready',
                            'maintenance',
                            'cleaning',
                            'unavailable',
                        ]),
                    ],
                    'current_odometer' => [
                        'sometimes',
                        'numeric',
                        'min:0',
                    ],
                    'battery_health_percent' => [
                        'sometimes',
                        'nullable',
                        'numeric',
                        'between:0,100',
                        Rule::prohibitedIf(!$vehicle->supportsExternalCharging()),
                    ],
                    'battery_last_inspection_date' => [
                        'sometimes',
                        'nullable',
                        'date',
                        Rule::prohibitedIf(!$vehicle->supportsExternalCharging()),
                    ],
                    'notes' => [
                        'sometimes',
                        'nullable',
                        'string',
                    ],
                ]);

            if (empty($validated)) {
                abort(
                    403,
                    'You do not have permission to modify these vehicle fields.'
                );
            }

            if (array_key_exists('battery_health_percent', $validated)) {
                $validated['battery_health_updated_at'] = $validated['battery_health_percent'] === null
                    ? null
                    : now();
            }

            $vehicle->update($validated);
        }

        else {
            abort(403);
        }

        $vehicle =
            $vehicle
                ->fresh()
                ->load('drivers');

        $newAuditValues =
            $this->getVehicleAuditValues(
                $vehicle
            );

        $changedOldValues = [];
        $changedNewValues = [];

        foreach (
            $newAuditValues
            as $field => $newValue
        ) {
            $oldValue =
                $oldAuditValues[$field]
                ?? null;

            if (
                (string) $oldValue !==
                (string) $newValue
            ) {
                $changedOldValues[$field] =
                    $oldValue;

                $changedNewValues[$field] =
                    $newValue;
            }
        }

        if (!empty($changedNewValues)) {
            $action = 'Updated';

            if (
                array_key_exists(
                    'battery_health_percent',
                    $changedNewValues
                ) ||
                array_key_exists(
                    'battery_last_inspection_date',
                    $changedNewValues
                )
            ) {
                $action = 'Battery Health Updated';
            } elseif (
                array_key_exists(
                    'assigned_driver_id',
                    $changedNewValues
                )
            ) {
                if (
                    $changedNewValues[
                        'assigned_driver_id'
                    ] === null
                ) {
                    $action =
                        'Driver Unassigned';
                } elseif (
                    $changedOldValues[
                        'assigned_driver_id'
                    ] === null
                ) {
                    $action =
                        'Driver Assigned';
                } else {
                    $action =
                        'Driver Reassigned';
                }
            } elseif (
                array_key_exists(
                    'status',
                    $changedNewValues
                )
            ) {
                $action =
                    'Status Changed';
            }

            $description = match ($action) {
                'Driver Assigned' =>
                    "Assigned a driver to vehicle {$vehicle->plate_number}.",
                'Driver Unassigned' =>
                    "Unassigned the driver from vehicle {$vehicle->plate_number}.",
                'Driver Reassigned' =>
                    "Reassigned the driver for vehicle {$vehicle->plate_number}.",
                'Status Changed' =>
                    "Changed the status of vehicle {$vehicle->plate_number}.",
                'Battery Health Updated' =>
                    "Recorded manual battery health information for vehicle {$vehicle->plate_number}.",
                default =>
                    "Updated vehicle {$vehicle->plate_number}.",
            };

            AuditLogService::log(
                module: 'Vehicle Management',
                action: $action,
                description: $description,
                record: $vehicle,
                oldValues:
                    $changedOldValues,
                newValues:
                    $changedNewValues
            );
        }

        return response()->json([
            'success' => true,
            'message' =>
                'Vehicle updated successfully.',
            'vehicle' =>
                $vehicle->fresh()->load('drivers'),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
        public function archive(
        Request $request,
        Vehicle $vehicle
    ) {
        $this->authorize('archive', $vehicle);

        if ($vehicle->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Vehicle is already archived.',
            ], 422);
        }

        if ($vehicle->status === 'On Trip') {
            return response()->json([
                'success' => false,
                'message' => 'A vehicle currently on a trip cannot be archived.',
            ], 422);
        }

        $vehicle->load('drivers');

        $oldValues = $this->getVehicleAuditValues($vehicle);

        DB::transaction(function () use (
            $vehicle,
            $request,
            $oldValues
        ) {
            $vehicle->update([
                'archived_at' => now(),
                'archived_by' => $request->user()->id,
            ]);

            Driver::where(
                'assigned_vehicle_id',
                $vehicle->id
            )->update([
                'assigned_vehicle_id' => null,
            ]);

            $vehicle->refresh();

            AuditLogService::log(
                module: 'Vehicle Management',
                action: 'Archived',
                description:
                    "Archived vehicle {$vehicle->plate_number}.",
                record: $vehicle,
                oldValues: $oldValues,
                newValues: [
                    'archived_at' => $vehicle->archived_at?->toDateTimeString(),
                    'archived_by' => $request->user()->id,
                    'assigned_driver_id' => null,
                ]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Vehicle archived successfully.',
        ]);
    }

    public function restore(
        Request $request,
        Vehicle $vehicle
    ) {
        $this->authorize('restore', $vehicle);

        if (!$vehicle->archived_at) {
            return response()->json([
                'success' => false,
                'message' => 'Vehicle is not archived.',
            ], 422);
        }

        $oldValues = [
            'archived_at' =>
                $vehicle->archived_at?->toDateTimeString(),
            'archived_by' =>
                $vehicle->archived_by,
        ];

        $vehicle->update([
            'archived_at' => null,
            'archived_by' => null,
        ]);

        AuditLogService::log(
            module: 'Vehicle Management',
            action: 'Restored',
            description:
                "Restored vehicle {$vehicle->plate_number}.",
            record: $vehicle,
            oldValues: $oldValues,
            newValues: [
                'archived_at' => null,
                'archived_by' => null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Vehicle restored successfully.',
            'vehicle' => $vehicle->fresh()->load('drivers'),
        ]);
    }

    public function bulkArchive(Request $request)
    {
        $this->authorize('archiveAny', Vehicle::class);

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:vehicles,id',
        ]);

        $vehicles = Vehicle::with('drivers')
            ->whereIn('id', $request->ids)
            ->whereNull('archived_at')
            ->get();

        DB::transaction(function () use (
            $vehicles,
            $request
        ) {
            foreach ($vehicles as $vehicle) {

                if ($vehicle->status === 'On Trip') {
                    continue;
                }

                $oldValues =
                    $this->getVehicleAuditValues($vehicle);

                $vehicle->update([
                    'archived_at' => now(),
                    'archived_by' => $request->user()->id,
                ]);

                Driver::where(
                    'assigned_vehicle_id',
                    $vehicle->id
                )->update([
                    'assigned_vehicle_id' => null,
                ]);

                AuditLogService::log(
                    module: 'Vehicle Management',
                    action: 'Archived',
                    description:
                        "Archived vehicle {$vehicle->plate_number}.",
                    record: $vehicle,
                    oldValues: $oldValues,
                    newValues: [
                        'archived_at' =>
                            $vehicle->archived_at?->toDateTimeString(),
                        'archived_by' =>
                            $request->user()->id,
                        'assigned_driver_id' => null,
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Selected vehicles archived successfully.',
        ]);
    }

    public function stats(
        Request $request
    ) {
        $this->authorize(
            'viewAny',
            Vehicle::class
        );

        $user =
            $request->user();

        $baseQuery =
            $this->applyVehicleVisibility(
                Vehicle::query(),
                $user
            )->whereNull('archived_at');

        return response()->json([
            'total' =>
                (clone $baseQuery)
                    ->count(),
            'available' =>
                (clone $baseQuery)
                    ->where(
                        'status',
                        'Available'
                    )
                    ->count(),
            'on_trip' =>
                (clone $baseQuery)
                    ->where(
                        'status',
                        'On Trip'
                    )
                    ->count(),
            'maintenance' =>
                (clone $baseQuery)
                    ->where(
                        'status',
                        'Maintenance'
                    )
                    ->count(),
            'out_of_service' =>
                (clone $baseQuery)
                    ->where(
                        'status',
                        'Out of Service'
                    )
                    ->count(),
        ]);
    }

    public function available(
        Request $request
    ) {
        $this->authorize(
            'viewAny',
            Vehicle::class
        );

        $query =
            Vehicle::with('drivers');

        $query =
            $this->applyVehicleVisibility(
                $query,
                $request->user()
            )->whereNull('archived_at');

        $vehicles =
            $query
                ->where(
                    'status',
                    'Available'
                )
                ->orderBy('brand')
                ->orderBy('model')
                ->get([
                    'id',
                    'brand',
                    'model',
                    'vehicle_type',
                    'department',
                    'fuel_type',
                    'powertrain_type',
                    'tank_capacity',
                    'current_fuel',
                    'current_odometer',
                    'battery_capacity_kwh',
                    'battery_level_percent',
                    'estimated_range_km',
                    'charging_port_type',
                    'status',
                    'readiness_status',
                ]);

        return response()->json(
            $vehicles
        );
    }

    public function capabilities()
    {
        $this->authorize('viewAny', Vehicle::class);

        return response()->json([
            'capabilities' => VehicleCapability::query()
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'description']),
        ]);
    }

    private function applyVehicleVisibility(
        $query,
        $user
    ) {
        /*
        |--------------------------------------------------------------------------
        | Driver - Assigned Vehicle Only
        |--------------------------------------------------------------------------
        |
        | Drivers may only see the vehicle currently assigned to
        | their Driver profile.
        |--------------------------------------------------------------------------
        */
        if ($user->hasRole('driver')) {
            $driver =
                $user->driverProfile;

            if (
                $driver &&
                $driver->assigned_vehicle_id
            ) {
                $query->where(
                    'vehicles.id',
                    $driver->assigned_vehicle_id
                );
            } else {
                /*
                |--------------------------------------------------------------------------
                | Driver Has No Assigned Vehicle
                |--------------------------------------------------------------------------
                |
                | Return zero vehicles instead of exposing the full fleet.
                |--------------------------------------------------------------------------
                */
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    private function getVehicleAuditValues(
        Vehicle $vehicle
    ): array {
        $vehicle->loadMissing(['drivers', 'capabilities']);

        $driver =
            $vehicle->drivers->first();

        return [
            'plate_number' =>
                $vehicle->plate_number,

            'vehicle_type' =>
                $vehicle->vehicle_type,

            'department' =>
                $vehicle->department,

            'brand' =>
                $vehicle->brand,

            'model' =>
                $vehicle->model,

            'capacity' =>
                $vehicle->capacity,

            'fuel_type' =>
                $vehicle->fuel_type,

            'powertrain_type' =>
                $vehicle->powertrain_type,

            'tank_capacity' =>
                $vehicle->tank_capacity,

            'current_fuel' =>
                $vehicle->current_fuel,

            'current_odometer' =>
                $vehicle->current_odometer,

            'battery_capacity_kwh' =>
                $vehicle->battery_capacity_kwh,

            'battery_level_percent' =>
                $vehicle->battery_level_percent,

            'estimated_range_km' =>
                $vehicle->estimated_range_km,

            'charging_port_type' =>
                $vehicle->charging_port_type,

            'battery_health_percent' =>
                $vehicle->battery_health_percent,

            'battery_health_updated_at' =>
                $vehicle->battery_health_updated_at?->toIso8601String(),

            'battery_last_inspection_date' =>
                $vehicle->battery_last_inspection_date?->format('Y-m-d'),

            'engine_displacement_cc' =>
                $vehicle->engine_displacement_cc,

            'transmission_type' =>
                $vehicle->transmission_type,

            'helmet_available' =>
                $vehicle->helmet_available,

            'cargo_box_available' =>
                $vehicle->cargo_box_available,

            'cargo_capacity_kg' =>
                $vehicle->cargo_capacity_kg,

            'registration_expiry' =>
                $vehicle->registration_expiry?->format('Y-m-d'),

            'status' =>
                $vehicle->status,

            'readiness_status' =>
                $vehicle->readiness_status,

            'capabilities' =>
                $vehicle->capabilities->pluck('slug')->sort()->values()->implode(', '),

            'assigned_driver_id' =>
                $driver?->id,
        ];
    }

}
