<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\SanitationLog;
use App\Models\User;
use App\Models\Vehicle;

function p5c2User(string $role): User
{
    return User::factory()->create(['role' => $role]);
}

function p5c2Vehicle(array $overrides = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('5C2-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Operations',
        'brand' => 'Lifecycle',
        'model' => 'Guard',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $overrides));
}

function p5c2Driver(Vehicle $vehicle, ?User $user = null): Driver
{
    $driver = Driver::query()->create([
        'user_id' => $user?->id,
        'first_name' => 'Lifecycle',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('5C2-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ]);
    $driver->licenseCodes()->create(['code' => 'B']);
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => $vehicle->vehicle_type,
    ]);

    return $driver;
}

function p5c2Dispatch(string $status = 'Pending', ?User $driverUser = null): Dispatch
{
    $vehicle = p5c2Vehicle();
    $driver = p5c2Driver($vehicle, $driverUser);
    $reservation = Reservation::query()->create([
        'reservation_number' => fake()->unique()->bothify('5C2-RSV-####'),
        'patient_name' => 'Lifecycle Patient',
        'request_type' => 'patient_transfer',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Hospital A',
        'destination' => 'Hospital B',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'routine',
        'status' => $status === 'Pending' ? 'Approved' : 'Scheduled',
    ]);
    RoutePlan::query()->create([
        'route_number' => fake()->unique()->bothify('5C2-ROUTE-####'),
        'reservation_id' => $reservation->id,
        'origin' => 'Hospital A',
        'destination' => 'Hospital B',
        'priority' => 'routine',
        'department' => 'Operations',
        'status' => 'Ready For Dispatch',
        'departure_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'estimated_distance' => 10,
    ]);

    return Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('5C2-DSP-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => $status,
    ]);
}

function p5c2Maintenance(Vehicle $vehicle, string $status = 'Scheduled'): Maintenance
{
    return Maintenance::query()->create([
        'maintenance_number' => fake()->unique()->bothify('5C2-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Lifecycle maintenance',
        'maintenance_date' => now()->toDateString(),
        'technician' => 'Lifecycle Tech — Technician — In-house',
        'priority' => 'Normal',
        'cost' => 100,
        'status' => $status,
    ]);
}

function p5c2MaintenancePayload(Maintenance $maintenance, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $maintenance->vehicle_id,
        'maintenance_type' => $maintenance->maintenance_type,
        'description' => $maintenance->description,
        'maintenance_date' => $maintenance->maintenance_date->format('Y-m-d'),
        'completion_date' => $maintenance->completion_date?->format('Y-m-d'),
        'next_schedule' => $maintenance->next_schedule?->format('Y-m-d'),
        'technician' => $maintenance->technician,
        'priority' => $maintenance->priority,
        'odometer' => $maintenance->odometer,
        'parts_used' => $maintenance->parts_used,
        'cost' => $maintenance->cost,
        'status' => $maintenance->status,
        'notes' => $maintenance->notes,
    ], $overrides);
}

function p5c2MaintenanceCreatePayload(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'request_type' => 'Normal',
        'vehicle_id' => $vehicle->id,
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Lifecycle maintenance',
        'maintenance_date' => now()->toDateString(),
        'technician' => 'Lifecycle Tech — Technician — In-house',
        'priority' => 'Normal',
        'cost' => 100,
    ], $overrides);
}

function p5c2Sanitation(Vehicle $vehicle, string $status): SanitationLog
{
    return SanitationLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
        'status' => $status,
    ]);
}

beforeEach(function () {
    FleetSetting::query()->create(['settings' => ['maintenance' => [
        'requireCost' => true,
        'providers' => [[
            'id' => 'lifecycle_tech',
            'name' => 'Lifecycle Tech',
            'type' => 'Technician',
            'contractType' => 'In-house',
            'active' => true,
        ]],
    ]]]);
});

it('rejects dispatch lifecycle mutation through generic update', function () {
    $dispatch = p5c2Dispatch('Assigned');

    $this->actingAs(p5c2User('dispatcher'))->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'En Route',
    ])->assertUnprocessable()->assertJsonValidationErrors('trip_status');
});

it('allows dispatch non-lifecycle edits and preserves pair validation', function () {
    $dispatch = p5c2Dispatch();
    $dispatcher = p5c2User('dispatcher');

    $this->actingAs($dispatcher)->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'Pending',
        'remarks' => 'Updated safely',
    ])->assertSuccessful();
    expect($dispatch->fresh()->remarks)->toBe('Updated safely');

    $originalVehicleId = $dispatch->reservation->vehicle_id;
    $this->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'Pending',
        'vehicle_id' => p5c2Vehicle(['vehicle_type' => 'Ambulance'])->id,
        'driver_id' => $dispatch->reservation->driver_id,
    ])->assertUnprocessable();
    expect($dispatch->fresh()->reservation->vehicle_id)->toBe($originalVehicleId);
});

it('runs dispatch acceptance start arrival and completion through dedicated actions', function () {
    $driverUser = p5c2User('driver');
    $dispatch = p5c2Dispatch('Pending', $driverUser);

    $this->actingAs($driverUser)->postJson(route('dispatch.accept', $dispatch))->assertSuccessful();
    $this->postJson(route('dispatch.transition', $dispatch), ['trip_status' => 'En Route'])->assertSuccessful();
    expect($dispatch->fresh()->reservation->vehicle->status)->toBe('On Trip');
    $this->postJson(route('dispatch.transition', $dispatch), ['trip_status' => 'Arrived'])->assertSuccessful();
    $this->postJson(route('dispatch.transition', $dispatch), ['trip_status' => 'Completed'])->assertSuccessful();

    $dispatch->refresh();
    expect($dispatch->trip_status)->toBe('Completed')
        ->and($dispatch->reservation->status)->toBe('Completed')
        ->and($dispatch->reservation->vehicle->status)->toBe('Available')
        ->and($dispatch->reservation->driver->status)->toBe('Available');
});

it('rejects invalid and terminal dispatch transitions', function (string $current, string $target) {
    $dispatch = p5c2Dispatch($current);

    $this->actingAs(p5c2User('dispatcher'))
        ->postJson(route('dispatch.transition', $dispatch), ['trip_status' => $target])
        ->assertUnprocessable();
    expect($dispatch->fresh()->trip_status)->toBe($current);
})->with([
    'invalid skip' => ['Pending', 'Completed'],
    'completed terminal' => ['Completed', 'Arrived'],
    'cancelled terminal' => ['Cancelled', 'Pending'],
]);

it('keeps driver-specific dispatch restrictions', function () {
    $driverUser = p5c2User('driver');
    $dispatch = p5c2Dispatch('Assigned', $driverUser);

    $this->actingAs($driverUser)
        ->postJson(route('dispatch.transition', $dispatch), ['trip_status' => 'Cancelled'])
        ->assertUnprocessable();
    expect($dispatch->fresh()->trip_status)->toBe('Assigned');
});

it('runs dispatch cancellation through its dedicated action', function (string $status) {
    $dispatch = p5c2Dispatch($status);

    $this->actingAs(p5c2User('dispatcher'))
        ->postJson(route('dispatch.transition', $dispatch), ['trip_status' => 'Cancelled'])
        ->assertSuccessful();
    expect($dispatch->fresh()->trip_status)->toBe('Cancelled')
        ->and($dispatch->fresh()->reservation->status)->toBe('Cancelled');
})->with(['Pending', 'Assigned', 'En Route']);

it('creates maintenance with its server-owned initial state', function (string $requestType, string $expectedStatus) {
    $response = $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('maintenance.store'), p5c2MaintenanceCreatePayload(p5c2Vehicle(), [
            'request_type' => $requestType,
            'priority' => $requestType === 'Emergency' ? 'Emergency' : 'Normal',
        ]))->assertCreated();
    expect($response->json('maintenance.status'))->toBe($expectedStatus);
})->with(['normal' => ['Normal', 'Scheduled'], 'emergency' => ['Emergency', 'In Progress']]);

it('rejects crafted maintenance status on create', function (string $status) {
    $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('maintenance.store'), p5c2MaintenanceCreatePayload(
            p5c2Vehicle(),
            ['status' => $status],
        ))->assertUnprocessable()->assertJsonValidationErrors('status');
})->with(['Scheduled', 'In Progress', 'Completed', 'Cancelled']);

it('rejects maintenance lifecycle mutation through generic edit', function (string $target) {
    $maintenance = p5c2Maintenance(p5c2Vehicle());
    $this->actingAs(p5c2User('fleet_manager'))
        ->putJson(route('maintenance.update', $maintenance), p5c2MaintenancePayload(
            $maintenance,
            ['status' => $target],
        ))->assertUnprocessable()->assertJsonValidationErrors('status');
})->with(['In Progress', 'Completed', 'Cancelled']);

it('allows maintenance non-lifecycle edits', function () {
    $maintenance = p5c2Maintenance(p5c2Vehicle());
    $this->actingAs(p5c2User('fleet_manager'))
        ->putJson(route('maintenance.update', $maintenance), p5c2MaintenancePayload(
            $maintenance,
            ['notes' => 'Updated safely'],
        ))->assertSuccessful();
    expect($maintenance->fresh()->status)->toBe('Scheduled')
        ->and($maintenance->fresh()->notes)->toBe('Updated safely');
});

it('runs dedicated maintenance start and completion with vehicle synchronization', function () {
    $vehicle = p5c2Vehicle();
    $maintenance = p5c2Maintenance($vehicle);
    $manager = p5c2User('fleet_manager');

    $this->actingAs($manager)
        ->postJson(route('maintenance.transition', $maintenance), ['status' => 'In Progress'])
        ->assertSuccessful();
    expect($vehicle->fresh()->status)->toBe('Maintenance')
        ->and($vehicle->fresh()->readiness_status)->toBe('maintenance');
    $this->postJson(route('maintenance.transition', $maintenance), ['status' => 'Completed'])
        ->assertSuccessful();
    expect($maintenance->fresh()->status)->toBe('Completed')
        ->and($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready');
});

it('runs dedicated maintenance cancellation', function (string $status) {
    $vehicle = p5c2Vehicle([
        'status' => $status === 'In Progress' ? 'Maintenance' : 'Available',
        'readiness_status' => $status === 'In Progress' ? 'maintenance' : 'ready',
    ]);
    $maintenance = p5c2Maintenance($vehicle, $status);
    $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('maintenance.transition', $maintenance), ['status' => 'Cancelled'])
        ->assertSuccessful();
    expect($maintenance->fresh()->status)->toBe('Cancelled')
        ->and($vehicle->fresh()->status)->toBe('Available');
})->with(['Scheduled', 'In Progress']);

it('rejects invalid and terminal maintenance transitions', function (string $current, string $target) {
    $maintenance = p5c2Maintenance(p5c2Vehicle(), $current);
    $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('maintenance.transition', $maintenance), ['status' => $target])
        ->assertUnprocessable();
    expect($maintenance->fresh()->status)->toBe($current);
})->with([
    'invalid skip' => ['Scheduled', 'Completed'],
    'completed terminal' => ['Completed', 'In Progress'],
    'cancelled terminal' => ['Cancelled', 'Scheduled'],
]);

it('retains cleaning when maintenance completes with active sanitation', function () {
    $vehicle = p5c2Vehicle(['status' => 'Maintenance', 'readiness_status' => 'maintenance']);
    $maintenance = p5c2Maintenance($vehicle, 'In Progress');
    p5c2Sanitation($vehicle, 'pending');
    $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('maintenance.transition', $maintenance), ['status' => 'Completed'])
        ->assertSuccessful();
    expect($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('cleaning');
});

it('creates sanitation as pending and marks the vehicle cleaning', function () {
    $vehicle = p5c2Vehicle();
    $response = $this->actingAs(p5c2User('fleet_manager'))->postJson(route('sanitation.store'), [
        'vehicle_id' => $vehicle->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
    ])->assertCreated();
    expect($response->json('sanitation.status'))->toBe('pending')
        ->and($vehicle->fresh()->readiness_status)->toBe('cleaning');
});

it('rejects crafted sanitation status on create', function (string $status) {
    $this->actingAs(p5c2User('fleet_manager'))->postJson(route('sanitation.store'), [
        'vehicle_id' => p5c2Vehicle()->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
        'status' => $status,
    ])->assertUnprocessable()->assertJsonValidationErrors('status');
})->with(['pending', 'in_progress', 'completed']);

it('rejects sanitation lifecycle mutation through generic update', function (string $status) {
    $sanitation = p5c2Sanitation(p5c2Vehicle(['readiness_status' => 'cleaning']), 'pending');
    $this->actingAs(p5c2User('fleet_manager'))
        ->putJson(route('sanitation.update', $sanitation), ['status' => $status])
        ->assertUnprocessable()->assertJsonValidationErrors('status');
})->with(['in_progress', 'completed']);

it('runs dedicated sanitation start and completion', function () {
    $vehicle = p5c2Vehicle(['readiness_status' => 'cleaning']);
    $sanitation = p5c2Sanitation($vehicle, 'pending');
    $manager = p5c2User('fleet_manager');
    $this->actingAs($manager)
        ->postJson(route('sanitation.transition', $sanitation), ['status' => 'in_progress'])
        ->assertSuccessful();
    expect($vehicle->fresh()->readiness_status)->toBe('cleaning');
    $this->postJson(route('sanitation.transition', $sanitation), ['status' => 'completed'])
        ->assertSuccessful();
    expect($sanitation->fresh()->status)->toBe('completed')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready');
});

it('does not regress completed sanitation', function () {
    $sanitation = p5c2Sanitation(p5c2Vehicle(), 'completed');
    $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('sanitation.transition', $sanitation), ['status' => 'in_progress'])
        ->assertUnprocessable();
    expect($sanitation->fresh()->status)->toBe('completed');
});

it('does not let sanitation completion override higher restrictions', function (string $vehicleStatus, string $readiness) {
    $vehicle = p5c2Vehicle(['status' => $vehicleStatus, 'readiness_status' => $readiness]);
    $sanitation = p5c2Sanitation($vehicle, 'in_progress');
    $this->actingAs(p5c2User('fleet_manager'))
        ->postJson(route('sanitation.transition', $sanitation), ['status' => 'completed'])
        ->assertSuccessful();
    expect($vehicle->fresh()->readiness_status)->toBe($readiness);
})->with([
    'maintenance' => ['Maintenance', 'maintenance'],
    'unavailable' => ['Available', 'unavailable'],
    'charging' => ['Available', 'charging'],
    'refueling' => ['Available', 'refueling'],
    'in use' => ['On Trip', 'in_use'],
]);
