<?php

use App\Http\Controllers\DispatchController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\TrafficViolationController;
use App\Http\Controllers\VehicleController;
use App\Http\Requests\UpdateTrafficViolationRequest;
use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\TrafficViolation;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DispatchAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

function p5c4User(string $role = 'fleet_manager'): User
{
    return User::factory()->create(['role' => $role, 'department' => 'Operations']);
}

function p5c4Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('5C4-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Facilities',
        'brand' => 'Lifecycle',
        'model' => 'Concurrency',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 25,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function p5c4VehiclePayload(Vehicle $vehicle, array $attributes = []): array
{
    return array_merge([
        'plate_number' => $vehicle->plate_number,
        'vehicle_type' => $vehicle->vehicle_type,
        'lto_vehicle_category' => $vehicle->lto_vehicle_category,
        'department' => $vehicle->department,
        'capacity' => $vehicle->capacity,
        'powertrain_type' => $vehicle->powertrain_type,
        'fuel_type' => $vehicle->fuel_type,
        'tank_capacity' => $vehicle->tank_capacity,
        'status' => $vehicle->status,
        'readiness_status' => $vehicle->readiness_status,
        'notes' => $vehicle->notes,
    ], $attributes);
}

function p5c4Driver(Vehicle $vehicle): Driver
{
    return Driver::query()->create([
        'first_name' => 'Lifecycle',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('5C4-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ]);
}

function p5c4Reservation(array $attributes = []): Reservation
{
    $vehicle = p5c4Vehicle();
    $driver = p5c4Driver($vehicle);

    return Reservation::query()->create(array_merge([
        'reservation_number' => fake()->unique()->bothify('5C4-RSV-####'),
        'patient_name' => 'Concurrency Patient',
        'request_type' => 'patient_transfer',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Hospital A',
        'destination' => 'Hospital B',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'routine',
        'contact_number' => '09170000000',
        'requested_by' => p5c4User()->id,
        'status' => 'Pending',
    ], $attributes));
}

function p5c4ReservationPayload(Reservation $reservation, array $attributes = []): array
{
    return array_merge([
        'reservation_number' => $reservation->reservation_number,
        'patient_name' => $reservation->patient_name,
        'request_type' => $reservation->request_type,
        'vehicle_id' => $reservation->vehicle_id,
        'driver_id' => $reservation->driver_id,
        'pickup_location' => $reservation->pickup_location,
        'destination' => $reservation->destination,
        'schedule_date' => $reservation->schedule_date->format('Y-m-d'),
        'schedule_time' => substr((string) $reservation->schedule_time, 0, 5),
        'priority' => $reservation->priority,
        'contact_number' => $reservation->contact_number,
        'status' => $reservation->status,
        'notes' => $reservation->notes,
    ], $attributes);
}

function p5c4Dispatch(string $status = 'Pending'): Dispatch
{
    $reservation = p5c4Reservation([
        'status' => $status === 'Pending' ? 'Approved' : 'Scheduled',
    ]);

    RoutePlan::query()->create([
        'route_number' => fake()->unique()->bothify('5C4-ROUTE-####'),
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
        'dispatch_number' => fake()->unique()->bothify('5C4-DSP-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => $status,
    ]);
}

function p5c4Maintenance(Vehicle $vehicle, string $status = 'Scheduled'): Maintenance
{
    return Maintenance::query()->create([
        'maintenance_number' => fake()->unique()->bothify('5C4-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Concurrency maintenance',
        'maintenance_date' => now()->toDateString(),
        'technician' => 'Lifecycle Tech — Technician — In-house',
        'priority' => 'Normal',
        'cost' => 100,
        'status' => $status,
    ]);
}

function p5c4MaintenancePayload(Maintenance $maintenance, array $attributes = []): array
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
    ], $attributes);
}

function p5c4Request(User $user, string $method, array $payload): Request
{
    $request = Request::create('/phase-5c4', $method, $payload);
    $request->setUserResolver(fn (): User => $user);

    return $request;
}

function p5c4TrafficUpdateRequest(User $user, TrafficViolation $trafficViolation, array $payload): UpdateTrafficViolationRequest
{
    $request = UpdateTrafficViolationRequest::create('/phase-5c4/violation', 'PUT', $payload);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(fn (): User => $user);

    $route = new Route(['PUT'], '/phase-5c4/violation', fn () => null);
    $route->bind($request);
    $route->setParameter('trafficViolation', $trafficViolation);
    $request->setRouteResolver(fn (): Route => $route);
    $request->validateResolved();

    return $request;
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

it('keeps a newer dispatch transition authoritative over a stale generic edit', function () {
    $dispatcher = p5c4User('dispatcher');
    $dispatch = p5c4Dispatch('Pending');
    Dispatch::query()->whereKey($dispatch->id)->update(['trip_status' => 'Assigned']);

    $this->actingAs($dispatcher);
    $response = app(DispatchController::class)->update(
        p5c4Request($dispatcher, 'PUT', [
            'dispatch_number' => $dispatch->dispatch_number,
            'trip_status' => 'Pending',
            'remarks' => 'Stale edit kept safe',
        ]),
        $dispatch,
        app(DispatchAssignmentService::class),
    );

    expect($response->getStatusCode(), $response->getContent())->toBe(200)
        ->and($dispatch->fresh()->trip_status)->toBe('Assigned')
        ->and($dispatch->fresh()->remarks)->toBe('Stale edit kept safe');
});

it('still permits a normal non-lifecycle dispatch edit', function () {
    $dispatch = p5c4Dispatch();

    $this->actingAs(p5c4User('dispatcher'))
        ->putJson(route('dispatch.update', $dispatch), [
            'dispatch_number' => $dispatch->dispatch_number,
            'trip_status' => 'Pending',
            'remarks' => 'Normal dispatch edit',
        ])->assertSuccessful();

    expect($dispatch->fresh()->trip_status)->toBe('Pending')
        ->and($dispatch->fresh()->remarks)->toBe('Normal dispatch edit');
});

it('keeps a newer maintenance transition authoritative over a stale generic edit', function () {
    $fleetManager = p5c4User();
    $vehicle = p5c4Vehicle();
    $maintenance = p5c4Maintenance($vehicle);
    Maintenance::query()->whereKey($maintenance->id)->update(['status' => 'In Progress']);
    Vehicle::query()->whereKey($vehicle->id)->update([
        'status' => 'Maintenance',
        'readiness_status' => 'maintenance',
    ]);

    $this->actingAs($fleetManager);
    $response = app(MaintenanceController::class)->update(
        p5c4Request($fleetManager, 'PUT', p5c4MaintenancePayload($maintenance, [
            'notes' => 'Stale maintenance edit kept safe',
        ])),
        $maintenance,
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($maintenance->fresh()->status)->toBe('In Progress')
        ->and($maintenance->fresh()->notes)->toBe('Stale maintenance edit kept safe')
        ->and($vehicle->fresh()->status)->toBe('Maintenance');
});

it('still permits a normal non-lifecycle maintenance edit', function () {
    $vehicle = p5c4Vehicle();
    $maintenance = p5c4Maintenance($vehicle);

    $this->actingAs(p5c4User())
        ->putJson(route('maintenance.update', $maintenance), p5c4MaintenancePayload($maintenance, [
            'notes' => 'Normal maintenance edit',
        ]))->assertSuccessful();

    expect($maintenance->fresh()->status)->toBe('Scheduled')
        ->and($maintenance->fresh()->notes)->toBe('Normal maintenance edit');
});

it('does not let a stale vehicle edit escape a newer On Trip state', function () {
    $fleetManager = p5c4User();
    $vehicle = p5c4Vehicle();
    Vehicle::query()->whereKey($vehicle->id)->update(['status' => 'On Trip']);

    $this->actingAs($fleetManager);
    $response = app(VehicleController::class)->update(
        p5c4Request($fleetManager, 'PUT', p5c4VehiclePayload($vehicle, ['notes' => 'Safe vehicle edit'])),
        $vehicle,
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($vehicle->fresh()->status)->toBe('On Trip')
        ->and($vehicle->fresh()->notes)->toBe('Safe vehicle edit');
});

it('does not let a stale vehicle edit escape a newer Maintenance state', function () {
    $fleetManager = p5c4User();
    $vehicle = p5c4Vehicle();
    Vehicle::query()->whereKey($vehicle->id)->update(['status' => 'Maintenance']);

    $this->actingAs($fleetManager);
    app(VehicleController::class)->update(
        p5c4Request($fleetManager, 'PUT', p5c4VehiclePayload($vehicle)),
        $vehicle,
    );

    expect($vehicle->fresh()->status)->toBe('Maintenance');
});

it('does not let a stale vehicle edit overwrite workflow-owned readiness', function () {
    $fleetManager = p5c4User();
    $vehicle = p5c4Vehicle();
    Vehicle::query()->whereKey($vehicle->id)->update(['readiness_status' => 'cleaning']);

    $this->actingAs($fleetManager);
    app(VehicleController::class)->update(
        p5c4Request($fleetManager, 'PUT', p5c4VehiclePayload($vehicle)),
        $vehicle,
    );

    expect($vehicle->fresh()->readiness_status)->toBe('cleaning');
});

it('does not let a stale reservation edit revert a newer Approved state', function () {
    $fleetManager = p5c4User();
    $reservation = p5c4Reservation();
    Reservation::query()->whereKey($reservation->id)->update(['status' => 'Approved']);

    $this->actingAs($fleetManager);
    $response = app(ReservationController::class)->update(
        p5c4Request($fleetManager, 'PUT', p5c4ReservationPayload($reservation, [
            'notes' => 'Approved state preserved',
        ])),
        $reservation,
    );

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->fresh()->status)->toBe('Approved')
        ->and($reservation->fresh()->notes)->toBe('Approved state preserved');
});

it('does not let a stale reservation edit reopen a terminal state', function (string $status) {
    $fleetManager = p5c4User();
    $reservation = p5c4Reservation();
    Reservation::query()->whereKey($reservation->id)->update(['status' => $status]);

    $this->actingAs($fleetManager);
    app(ReservationController::class)->update(
        p5c4Request($fleetManager, 'PUT', p5c4ReservationPayload($reservation)),
        $reservation,
    );

    expect($reservation->fresh()->status)->toBe($status);
})->with(['Rejected', 'Cancelled', 'Completed']);

it('makes reservation approval and rejection re-check the current locked state', function (string $action, string $newerStatus) {
    $fleetManager = p5c4User();
    $reservation = p5c4Reservation();
    Reservation::query()->whereKey($reservation->id)->update(['status' => $newerStatus]);

    $this->actingAs($fleetManager);
    $response = app(ReservationController::class)->{$action}(
        p5c4Request($fleetManager, 'POST', []),
        $reservation,
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($reservation->fresh()->status)->toBe($newerStatus);
})->with([
    'approve sees rejected' => ['approve', 'Rejected'],
    'reject sees approved' => ['reject', 'Approved'],
]);

it('does not let a stale traffic violation edit overwrite terminal resolution', function () {
    $dispatcher = p5c4User('dispatcher');
    $trafficViolation = TrafficViolation::factory()->create(['status' => 'reported']);
    $resolvedAt = now()->startOfSecond()->subMinute();
    TrafficViolation::query()->whereKey($trafficViolation->id)->update([
        'status' => 'closed',
        'resolved_at' => $resolvedAt,
    ]);
    $payload = [
        'citation_number' => $trafficViolation->citation_number,
        'violation_date' => $trafficViolation->violation_date->format('Y-m-d'),
        'violation_type' => $trafficViolation->violation_type,
        'description' => 'Operational detail updated safely.',
    ];

    $this->actingAs($dispatcher);
    $response = app(TrafficViolationController::class)->update(
        p5c4TrafficUpdateRequest($dispatcher, $trafficViolation, $payload),
        $trafficViolation,
    );

    $trafficViolation->refresh();
    expect($response->getStatusCode())->toBe(200)
        ->and($trafficViolation->status)->toBe('closed')
        ->and($trafficViolation->resolved_at?->equalTo($resolvedAt))->toBeTrue()
        ->and($trafficViolation->description)->toBe('Operational detail updated safely.');
});

it('still permits a valid traffic violation review transition', function () {
    $trafficViolation = TrafficViolation::factory()->create(['status' => 'reported']);

    $this->actingAs(p5c4User())
        ->putJson(route('traffic-violations.review', $trafficViolation), [
            'responsibility' => 'under_review',
            'responsibility_notes' => null,
            'status' => 'under_review',
            'fine_amount' => null,
            'due_date' => null,
            'payment_status' => 'not_applicable',
            'payment_date' => null,
            'payment_reference' => null,
            'settlement_notes' => null,
        ])->assertSuccessful();

    expect($trafficViolation->fresh()->status)->toBe('under_review');
});
