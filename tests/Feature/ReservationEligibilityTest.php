<?php

use App\Models\Driver;
use App\Models\DriverLicenseCode;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCapability;
use Carbon\Carbon;

function eligibilityUser(): User
{
    return User::factory()->create(['role' => 'fleet_manager', 'department' => 'Operations']);
}

/** @return array{0: Vehicle, 1: Driver} */
function eligibilityPair(array $vehicle = [], array $driver = []): array
{
    $vehicleModel = Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('ELG-####'), 'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1', 'department' => 'Operations', 'brand' => 'Eligibility',
        'model' => 'Test', 'capacity' => 4, 'powertrain_type' => 'gasoline', 'fuel_type' => 'Gasoline',
        'tank_capacity' => 50, 'current_fuel' => 25, 'current_odometer' => 100,
        'transmission_type' => 'automatic', 'status' => 'Available', 'readiness_status' => 'ready',
    ], $vehicle));
    $driverModel = Driver::query()->create(array_merge([
        'first_name' => 'Eligible', 'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('ELG-LIC-####'), 'license_class' => 'Professional',
        'clutch_authorization' => 'AT', 'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000', 'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicleModel->id, 'status' => 'Available',
    ], $driver));
    DriverLicenseCode::query()->create(['driver_id' => $driverModel->id, 'code' => 'B']);
    DriverVehicleQualification::query()->create([
        'driver_id' => $driverModel->id, 'vehicle_type' => $vehicleModel->vehicle_type,
    ]);

    return [$vehicleModel, $driverModel];
}

function eligibilityPayload(Vehicle $vehicle, Driver $driver, array $overrides = []): array
{
    return array_merge([
        'reservation_number' => fake()->unique()->bothify('ELG-RSV-####'), 'patient_name' => null,
        'request_type' => 'staff_transport', 'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
        'pickup_location' => 'Hospital A', 'destination' => 'Hospital B',
        'schedule_date' => now()->addDays(5)->toDateString(), 'schedule_time' => '09:00',
        'priority' => 'routine', 'status' => 'Pending', 'contact_number' => '09170000000',
    ], $overrides);
}

function eligibilityReservation(Vehicle $vehicle, Driver $driver, array $overrides = []): Reservation
{
    return Reservation::query()->create(array_merge(
        eligibilityPayload($vehicle, $driver),
        ['requested_by' => eligibilityUser()->id],
        $overrides,
    ));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 22, 8, 0, 0, config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('rejects a driver license already expired', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-21']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', "Driver's license is expired.");
});

it('rejects a license that expires before the future trip', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-25']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'schedule_date' => '2026-10-26',
    ]))->assertUnprocessable()->assertJsonPath('message', "Driver's license expires before the requested trip date.");
});

it('accepts a license through its expiration date', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-25']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'schedule_date' => '2026-10-25',
    ]))->assertCreated();
});

it('rejects emergency assignments with an expired license', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-21']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'request_type' => 'emergency_response', 'patient_name' => 'Emergency Patient',
    ]))->assertUnprocessable()->assertJsonPath('message', "Driver's license is expired.");
});

it('uses the automated emergency trip date for future license validity', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-23']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'request_type' => 'emergency_response', 'patient_name' => 'Emergency Patient',
        'schedule_date' => '2026-10-30',
    ]))->assertCreated()->assertJsonPath('reservation.schedule_date', '2026-10-22');
});

it('does not allow a crafted request to bypass license validation', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-23']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'schedule_date' => '2026-10-24', 'status' => 'Approved',
    ]))->assertUnprocessable();
    expect(Reservation::query()->count())->toBe(0);
});

it('rejects inactive and on-leave drivers', function (string $status, string $message) {
    [$vehicle, $driver] = eligibilityPair(driver: ['status' => $status]);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', $message);
})->with([
    ['Inactive', 'Selected driver is inactive.'],
    ['On Leave', 'Selected driver is on leave.'],
]);

it('rejects an incompatible LTO category', function () {
    [$vehicle, $driver] = eligibilityPair(vehicle: ['lto_vehicle_category' => 'N1']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', 'Driver is not licensed for the selected vehicle category.');
});

it('rejects a transmission authorization mismatch', function () {
    [$vehicle, $driver] = eligibilityPair(vehicle: ['transmission_type' => 'manual']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', 'Driver does not have the required transmission authorization.');
});

it('rejects a missing hospital vehicle qualification', function () {
    [$vehicle, $driver] = eligibilityPair();
    DriverVehicleQualification::query()->where('driver_id', $driver->id)->delete();
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', 'Driver is not qualified to operate the selected vehicle type.');
});

it('rejects an out-of-service vehicle', function () {
    [$vehicle, $driver] = eligibilityPair(vehicle: ['status' => 'Out of Service']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', 'Selected vehicle is Out of Service.');
});

it('rejects a driver assigned to another vehicle', function () {
    [$vehicle, $driver] = eligibilityPair();
    [$otherVehicle] = eligibilityPair();
    $driver->update(['assigned_vehicle_id' => $otherVehicle->id]);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertUnprocessable()->assertJsonPath('message', 'The selected driver is not assigned to the selected vehicle.');
});

it('rejects a vehicle missing a required reservation capability', function () {
    [$vehicle, $driver] = eligibilityPair();
    $capability = VehicleCapability::query()->create([
        'name' => 'Isolation Transport', 'slug' => 'isolation_transport',
    ]);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'required_capabilities' => [$capability->id],
    ]))->assertUnprocessable()->assertJsonPath('message', 'Vehicle lacks required capability: Isolation Transport');
});

it('accepts valid normal and emergency assignments', function (string $requestType) {
    [$vehicle, $driver] = eligibilityPair();
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'request_type' => $requestType,
        'patient_name' => $requestType === 'emergency_response' ? 'Emergency Patient' : null,
    ]))->assertCreated();
})->with(['staff_transport', 'emergency_response']);

it('rejects an overlapping driver assignment', function () {
    [$vehicle, $driver] = eligibilityPair();
    [$otherVehicle] = eligibilityPair();
    eligibilityReservation($otherVehicle, $driver, ['schedule_date' => '2026-10-27', 'schedule_time' => '09:30']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'schedule_date' => '2026-10-27', 'schedule_time' => '10:00',
    ]))->assertUnprocessable()->assertJsonPath('message', 'Driver already has a conflicting assignment during the requested schedule.');
});

it('rejects an overlapping vehicle reservation', function () {
    [$vehicle, $driver] = eligibilityPair();
    [, $otherDriver] = eligibilityPair();
    $otherDriver->update(['assigned_vehicle_id' => $vehicle->id]);
    eligibilityReservation($vehicle, $otherDriver, ['schedule_date' => '2026-10-27', 'schedule_time' => '09:30']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'schedule_date' => '2026-10-27', 'schedule_time' => '10:00',
    ]))->assertUnprocessable()->assertJsonPath('message', 'Vehicle already has a conflicting reservation during the requested schedule.');
});

it('allows adjacent non-overlapping schedules', function () {
    [$vehicle, $driver] = eligibilityPair();
    eligibilityReservation($vehicle, $driver, ['schedule_date' => '2026-10-27', 'schedule_time' => '09:00']);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver, [
        'schedule_date' => '2026-10-27', 'schedule_time' => '11:00',
    ]))->assertCreated();
});

it('ignores cancelled and rejected reservations when checking conflicts', function (string $status) {
    [$vehicle, $driver] = eligibilityPair();
    eligibilityReservation($vehicle, $driver, ['status' => $status]);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertCreated();
})->with(['Cancelled', 'Rejected']);

it('excludes the edited reservation itself from conflict checks', function () {
    [$vehicle, $driver] = eligibilityPair();
    $reservation = eligibilityReservation($vehicle, $driver);
    $this->actingAs(eligibilityUser())->putJson(route('reservation.update', $reservation), eligibilityPayload($vehicle, $driver, [
        'reservation_number' => $reservation->reservation_number, 'notes' => 'Unrelated update',
    ]))->assertSuccessful();
});

it('revalidates eligibility when an edit changes the schedule', function () {
    [$vehicle, $driver] = eligibilityPair(driver: ['license_expiry' => '2026-10-27']);
    $reservation = eligibilityReservation($vehicle, $driver, ['schedule_date' => '2026-10-26']);
    $this->actingAs(eligibilityUser())->putJson(route('reservation.update', $reservation), eligibilityPayload($vehicle, $driver, [
        'reservation_number' => $reservation->reservation_number, 'schedule_date' => '2026-10-28',
    ]))->assertUnprocessable()->assertJsonPath('message', "Driver's license expires before the requested trip date.");
});

it('does not revalidate an unchanged assignment for a notes-only edit', function () {
    [$vehicle, $driver] = eligibilityPair();
    $reservation = eligibilityReservation($vehicle, $driver);
    $driver->update(['license_expiry' => '2026-10-21']);
    $this->actingAs(eligibilityUser())->putJson(route('reservation.update', $reservation), eligibilityPayload($vehicle, $driver, [
        'reservation_number' => $reservation->reservation_number, 'notes' => 'Grandfathered assignment remains unchanged',
    ]))->assertSuccessful();
});

it('allows a future reservation during temporary recoverable readiness', function (string $readiness) {
    [$vehicle, $driver] = eligibilityPair(vehicle: ['readiness_status' => $readiness]);
    $this->actingAs(eligibilityUser())->postJson(route('reservation.store'), eligibilityPayload($vehicle, $driver))
        ->assertCreated();
})->with(['charging', 'refueling', 'cleaning']);

it('filters the candidate endpoint by requested-date eligibility', function () {
    [$vehicle] = eligibilityPair(driver: ['license_expiry' => '2026-10-25']);
    $this->actingAs(eligibilityUser())->getJson(route('reservation.eligible-assignments', [
        'schedule_date' => '2026-10-26', 'schedule_time' => '09:00',
    ]))->assertSuccessful()->assertJsonPath('vehicles.0.id', $vehicle->id)
        ->assertJsonCount(0, 'vehicles.0.drivers')
        ->assertJsonPath('vehicles.0.reservation_eligibility_message', "Driver's license expires before the requested trip date.");
});

it('preserves an unchanged existing assignment in the edit candidate list', function () {
    [$vehicle, $driver] = eligibilityPair();
    $reservation = eligibilityReservation($vehicle, $driver);
    $driver->update(['license_expiry' => '2026-10-21']);

    $this->actingAs(eligibilityUser())->getJson(route('reservation.eligible-assignments', [
        'schedule_date' => $reservation->schedule_date->toDateString(),
        'schedule_time' => substr((string) $reservation->schedule_time, 0, 5),
        'reservation_id' => $reservation->id,
    ]))->assertSuccessful()
        ->assertJsonPath('vehicles.0.drivers.0.id', $driver->id)
        ->assertJsonPath('vehicles.0.drivers.0.reservation_eligible', false)
        ->assertJsonPath('vehicles.0.drivers.0.reservation_existing_assignment', true);
});
