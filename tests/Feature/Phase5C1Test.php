<?php

use App\Models\Driver;
use App\Models\DriverLicenseCode;
use App\Models\DriverVehicleQualification;
use App\Models\FleetSetting;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;

function phase5C1FleetManager(): User
{
    return User::factory()->create(['role' => 'fleet_manager', 'department' => 'Operations']);
}

function phase5C1VehiclePayload(array $overrides = []): array
{
    return array_merge([
        'plate_number' => fake()->unique()->bothify('5C1-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Facilities',
        'brand' => 'Lifecycle',
        'model' => 'Guard',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'transmission_type' => 'automatic',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 25,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $overrides);
}

function phase5C1Vehicle(array $overrides = []): Vehicle
{
    return Vehicle::query()->create(phase5C1VehiclePayload($overrides));
}

function phase5C1VehicleUpdatePayload(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'plate_number' => $vehicle->plate_number,
        'vehicle_type' => $vehicle->vehicle_type,
        'department' => $vehicle->department,
        'capacity' => $vehicle->capacity,
        'powertrain_type' => $vehicle->powertrain_type,
        'fuel_type' => $vehicle->fuel_type,
        'tank_capacity' => $vehicle->tank_capacity,
        'status' => $vehicle->status,
        'readiness_status' => $vehicle->readiness_status,
        'notes' => $vehicle->notes,
    ], $overrides);
}

function phase5C1Assignment(): array
{
    $vehicle = phase5C1Vehicle();
    $driver = Driver::query()->create([
        'first_name' => 'Lifecycle',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('5C1-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ]);

    DriverLicenseCode::query()->create([
        'driver_id' => $driver->id,
        'code' => 'B',
    ]);
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => $vehicle->vehicle_type,
    ]);

    return [$vehicle, $driver];
}

function phase5C1ReservationPayload(array $overrides = []): array
{
    return array_merge([
        'reservation_number' => fake()->unique()->bothify('5C1-RSV-####'),
        'patient_name' => 'Lifecycle Patient',
        'request_type' => 'patient_transfer',
        'pickup_location' => 'Hospital A',
        'destination' => 'Hospital B',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00',
        'priority' => 'routine',
        'contact_number' => '09170000000',
    ], $overrides);
}

function phase5C1Reservation(array $overrides = []): Reservation
{
    return Reservation::query()->create(phase5C1ReservationPayload(array_merge([
        'requested_by' => phase5C1FleetManager()->id,
        'status' => 'Pending',
    ], $overrides)));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 19, 10, 30, 0, config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('accepts manually controlled vehicle statuses on create', function (string $status) {
    $response = $this->actingAs(phase5C1FleetManager())->postJson(
        route('vehicles.store'),
        phase5C1VehiclePayload(['status' => $status])
    );

    $response->assertSuccessful()->assertJsonPath('vehicle.status', $status);
})->with(['Available', 'Out of Service']);

it('rejects workflow-owned vehicle statuses on create', function (string $status) {
    $this->actingAs(phase5C1FleetManager())
        ->postJson(route('vehicles.store'), phase5C1VehiclePayload(['status' => $status]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
})->with(['On Trip', 'Maintenance']);

it('accepts manually controlled vehicle readiness on create', function (string $readiness) {
    $response = $this->actingAs(phase5C1FleetManager())->postJson(
        route('vehicles.store'),
        phase5C1VehiclePayload(['readiness_status' => $readiness])
    );

    $response->assertSuccessful()->assertJsonPath('vehicle.readiness_status', $readiness);
})->with(['ready', 'refueling', 'charging']);

it('rejects workflow-owned vehicle readiness on create', function (string $readiness) {
    $this->actingAs(phase5C1FleetManager())
        ->postJson(route('vehicles.store'), phase5C1VehiclePayload(['readiness_status' => $readiness]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('readiness_status');
})->with(['in_use', 'maintenance', 'cleaning', 'unavailable']);

it('prevents generic edit from escaping a workflow-owned vehicle status', function (string $status) {
    $vehicle = phase5C1Vehicle(['status' => $status]);

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('vehicles.update', $vehicle), phase5C1VehicleUpdatePayload($vehicle, ['status' => 'Available']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($vehicle->fresh()->status)->toBe($status);
})->with(['On Trip', 'Maintenance']);

it('prevents generic edit from escaping workflow-owned vehicle readiness', function (string $readiness) {
    $vehicle = phase5C1Vehicle(['readiness_status' => $readiness]);

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('vehicles.update', $vehicle), phase5C1VehicleUpdatePayload($vehicle, ['readiness_status' => 'ready']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('readiness_status');

    expect($vehicle->fresh()->readiness_status)->toBe($readiness);
})->with(['in_use', 'maintenance', 'cleaning', 'unavailable']);

it('still updates non-lifecycle vehicle fields', function () {
    $vehicle = phase5C1Vehicle();

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('vehicles.update', $vehicle), phase5C1VehicleUpdatePayload($vehicle, ['notes' => 'Lifecycle-safe note']))
        ->assertSuccessful();

    expect($vehicle->fresh()->notes)->toBe('Lifecycle-safe note');
});

it('preserves legacy null readiness while updating unrelated vehicle fields', function () {
    $vehicle = phase5C1Vehicle(['readiness_status' => null]);
    $payload = phase5C1VehicleUpdatePayload($vehicle, ['notes' => 'Legacy readiness preserved']);
    unset($payload['status'], $payload['readiness_status']);

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('vehicles.update', $vehicle), $payload)
        ->assertSuccessful();

    $vehicle->refresh();

    expect($vehicle->readiness_status)->toBeNull()
        ->and($vehicle->notes)->toBe('Legacy readiness preserved');
});

it('saves a manual vehicle default and preserves unrelated settings', function () {
    $settings = [
        'vehicles' => ['defaultStatus' => 'Out of Service'],
        'general' => ['organizationName' => 'Hospital Fleet'],
    ];

    $this->actingAs(User::factory()->create(['role' => 'it_admin']))
        ->putJson(route('settings.update'), ['settings' => $settings])
        ->assertSuccessful()
        ->assertJsonPath('settings.vehicles.defaultStatus', 'Out of Service')
        ->assertJsonPath('settings.general.organizationName', 'Hospital Fleet');
});

it('rejects workflow-owned vehicle defaults', function (string $status) {
    $this->actingAs(User::factory()->create(['role' => 'it_admin']))
        ->putJson(route('settings.update'), ['settings' => ['vehicles' => ['defaultStatus' => $status]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('settings.vehicles.defaultStatus');

    expect(FleetSetting::query()->exists())->toBeFalse();
})->with(['On Trip', 'Maintenance']);

it('keeps normal reservation creation on the configured initial status', function () {
    $response = $this->actingAs(phase5C1FleetManager())->postJson(
        route('reservation.store'),
        phase5C1ReservationPayload(['status' => 'Approved'])
    );

    $response->assertCreated()->assertJsonPath('reservation.status', 'Pending');
});

it('rejects protected reservation statuses on generic create', function (string $status) {
    $this->actingAs(phase5C1FleetManager())
        ->postJson(route('reservation.store'), phase5C1ReservationPayload(['status' => $status]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
})->with(['Rejected', 'Scheduled', 'Completed', 'Cancelled']);

it('does not let generic edit force approval', function () {
    $reservation = phase5C1Reservation();

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('reservation.update', $reservation), phase5C1ReservationPayload([
            'reservation_number' => $reservation->reservation_number,
            'status' => 'Approved',
        ]))
        ->assertSuccessful();

    expect($reservation->fresh()->status)->toBe('Pending');
});

it('rejects protected reservation statuses on generic edit', function (string $status) {
    $reservation = phase5C1Reservation();

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('reservation.update', $reservation), phase5C1ReservationPayload([
            'reservation_number' => $reservation->reservation_number,
            'status' => $status,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($reservation->fresh()->status)->toBe('Pending');
})->with(['Rejected', 'Scheduled', 'Completed', 'Cancelled']);

it('preserves terminal reservation status during generic edit', function () {
    $reservation = phase5C1Reservation(['status' => 'Completed']);

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('reservation.update', $reservation), phase5C1ReservationPayload([
            'reservation_number' => $reservation->reservation_number,
            'status' => 'Pending',
        ]))
        ->assertSuccessful();

    expect($reservation->fresh()->status)->toBe('Completed');
});

it('keeps dedicated reservation approval and rejection authoritative', function (string $action, string $expectedStatus) {
    [$vehicle, $driver] = phase5C1Assignment();
    $reservation = phase5C1Reservation(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);

    $this->actingAs(phase5C1FleetManager())
        ->postJson(route("reservation.{$action}", $reservation))
        ->assertSuccessful();

    expect($reservation->fresh()->status)->toBe($expectedStatus);
})->with([
    'approve' => ['approve', 'Approved'],
    'reject' => ['reject', 'Rejected'],
]);

it('preserves emergency creation automation', function () {
    $response = $this->actingAs(phase5C1FleetManager())->postJson(
        route('reservation.store'),
        phase5C1ReservationPayload([
            'request_type' => 'emergency_response',
            'priority' => 'routine',
            'schedule_date' => now()->addWeek()->toDateString(),
            'schedule_time' => '23:00',
            'status' => 'Pending',
        ])
    );

    $response->assertCreated()
        ->assertJsonPath('reservation.priority', 'emergency')
        ->assertJsonPath('reservation.status', 'Approved');

    $reservation = Reservation::query()->findOrFail($response->json('reservation.id'));
    expect($reservation->schedule_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and(substr((string) $reservation->schedule_time, 0, 5))->toBe('10:30');
});

it('preserves emergency edit conversion automation', function () {
    $reservation = phase5C1Reservation();

    $this->actingAs(phase5C1FleetManager())
        ->putJson(route('reservation.update', $reservation), phase5C1ReservationPayload([
            'reservation_number' => $reservation->reservation_number,
            'request_type' => 'Emergency Transfer',
            'priority' => 'scheduled',
            'status' => 'Pending',
        ]))
        ->assertSuccessful();

    $reservation->refresh();
    expect($reservation->priority)->toBe('emergency')
        ->and($reservation->status)->toBe('Approved')
        ->and($reservation->schedule_date->format('Y-m-d'))->toBe('2026-10-19')
        ->and(substr((string) $reservation->schedule_time, 0, 5))->toBe('10:30');
});
