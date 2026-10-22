<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverLicenseCode;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;

function p52User(): User
{
    return User::factory()->create([
        'role' => 'fleet_manager',
        'department' => 'Operations',
    ]);
}

/** @return array{0: Vehicle, 1: Driver} */
function p52Assignment(): array
{
    $vehicle = Vehicle::query()->create([
        'plate_number' => fake()->unique()->bothify('P52-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Operations',
        'brand' => 'Phase',
        'model' => 'Five A Two',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 35,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available',
        'readiness_status' => 'ready',
    ]);

    $driver = Driver::query()->create([
        'first_name' => 'Emergency',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P52-LIC-####'),
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

function p52Payload(array $overrides = []): array
{
    return array_merge([
        'reservation_number' => fake()->unique()->bothify('P52-RSV-####'),
        'patient_name' => 'Emergency Patient',
        'request_type' => 'emergency_response',
        'pickup_location' => 'Emergency Department',
        'destination' => 'Referral Hospital',
        'schedule_date' => now()->addDays(5)->toDateString(),
        'schedule_time' => '23:45',
        'priority' => 'routine',
        'status' => 'Pending',
        'contact_number' => '09170000000',
    ], $overrides);
}

function p52Reservation(array $overrides = []): Reservation
{
    return Reservation::query()->create(p52Payload(array_merge([
        'request_type' => 'patient_transfer',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00',
        'priority' => 'routine',
        'status' => 'Pending',
    ], $overrides)));
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 16, 14, 37, 0, config('app.timezone')));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('centralizes canonical and legacy emergency request recognition', function () {
    expect(Reservation::emergencyRequestTypes())->toBe([
        'emergency_response',
        'Emergency Transfer',
    ])
        ->and(Reservation::isEmergencyRequestType('emergency_response'))->toBeTrue()
        ->and(Reservation::isEmergencyRequestType('Emergency Transfer'))->toBeTrue()
        ->and(Reservation::isEmergencyRequestType('patient_transfer'))->toBeFalse()
        ->and(Reservation::isEmergencyRequestType('medical_supply_delivery'))->toBeFalse();
});

it('normalizes a crafted canonical emergency request and preserves its assignment', function () {
    [$vehicle, $driver] = p52Assignment();

    $response = $this->actingAs(p52User())->postJson(route('reservation.store'), p52Payload([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]));

    $response->assertCreated()
        ->assertJsonPath('reservation.request_type', 'emergency_response')
        ->assertJsonPath('reservation.priority', 'emergency')
        ->assertJsonPath('reservation.status', 'Approved')
        ->assertJsonPath('reservation.vehicle_id', $vehicle->id)
        ->assertJsonPath('reservation.driver_id', $driver->id);

    $reservation = Reservation::query()->findOrFail($response->json('reservation.id'));

    expect($reservation->schedule_date->format('Y-m-d'))->toBe('2026-10-16')
        ->and(substr((string) $reservation->schedule_time, 0, 5))->toBe('14:37')
        ->and($reservation->priority)->toBe('emergency')
        ->and($reservation->status)->toBe('Approved')
        ->and($reservation->vehicle_id)->toBe($vehicle->id)
        ->and($reservation->driver_id)->toBe($driver->id)
        ->and(RoutePlan::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse()
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

it('keeps legacy Emergency Transfer automation with canonical emergency priority', function () {
    $response = $this->actingAs(p52User())->postJson(route('reservation.store'), p52Payload([
        'request_type' => 'Emergency Transfer',
        'priority' => 'Low',
        'status' => 'Pending',
    ]));

    $response->assertCreated();

    $this->assertDatabaseHas('reservations', [
        'id' => $response->json('reservation.id'),
        'request_type' => 'Emergency Transfer',
        'priority' => 'emergency',
        'schedule_date' => '2026-10-16',
        'schedule_time' => '14:37',
        'status' => 'Approved',
    ]);
});

it('derives emergency managed values when disabled controls are omitted', function () {
    $payload = p52Payload();
    unset(
        $payload['priority'],
        $payload['schedule_date'],
        $payload['schedule_time'],
        $payload['status']
    );

    $response = $this->actingAs(p52User())->postJson(
        route('reservation.store'),
        $payload
    );

    $response->assertCreated();

    $this->assertDatabaseHas('reservations', [
        'id' => $response->json('reservation.id'),
        'priority' => 'emergency',
        'schedule_date' => '2026-10-16',
        'schedule_time' => '14:37',
        'status' => 'Approved',
    ]);
});

it('leaves normal priority scheduling and approval behavior unchanged', function () {
    $response = $this->actingAs(p52User())->postJson(route('reservation.store'), p52Payload([
        'request_type' => 'staff_transport',
        'patient_name' => null,
        'schedule_date' => '2026-10-20',
        'schedule_time' => '10:15',
        'priority' => 'urgent',
        'status' => 'Approved',
    ]));

    $response->assertCreated();

    $this->assertDatabaseHas('reservations', [
        'id' => $response->json('reservation.id'),
        'request_type' => 'staff_transport',
        'priority' => 'urgent',
        'schedule_date' => '2026-10-20',
        'schedule_time' => '10:15',
        'status' => 'Pending',
    ]);

    $this->actingAs(p52User())->postJson(route('reservation.store'), p52Payload([
        'request_type' => 'administrative_trip',
        'patient_name' => null,
        'schedule_date' => '2026-10-15',
        'priority' => 'scheduled',
    ]))->assertUnprocessable()->assertJsonValidationErrors('schedule_date');
});

it('applies all automation when an editable reservation changes to emergency', function () {
    [$vehicle, $driver] = p52Assignment();
    $reservation = p52Reservation([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]);

    $this->actingAs(p52User())->putJson(
        route('reservation.update', $reservation),
        p52Payload([
            'reservation_number' => $reservation->reservation_number,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'request_type' => 'emergency_response',
            'schedule_date' => '2026-11-01',
            'schedule_time' => '23:59',
            'priority' => 'scheduled',
            'status' => 'Pending',
        ])
    )->assertSuccessful();

    $reservation->refresh();

    expect($reservation->request_type)->toBe('emergency_response')
        ->and($reservation->priority)->toBe('emergency')
        ->and($reservation->schedule_date->format('Y-m-d'))->toBe('2026-10-16')
        ->and(substr((string) $reservation->schedule_time, 0, 5))->toBe('14:37')
        ->and($reservation->status)->toBe('Approved')
        ->and($reservation->vehicle_id)->toBe($vehicle->id)
        ->and($reservation->driver_id)->toBe($driver->id)
        ->and($reservation->routePlan()->exists())->toBeFalse()
        ->and($reservation->dispatch()->exists())->toBeFalse();
});

it('requires and accepts a normal priority when changing from emergency', function () {
    $manager = p52User();
    $reservation = p52Reservation([
        'request_type' => 'emergency_response',
        'schedule_date' => '2026-10-16',
        'schedule_time' => '14:37',
        'priority' => 'emergency',
        'status' => 'Approved',
    ]);
    $normalPayload = p52Payload([
        'reservation_number' => $reservation->reservation_number,
        'request_type' => 'patient_transfer',
        'schedule_date' => '2026-10-20',
        'schedule_time' => '11:30',
        'status' => 'Pending',
    ]);

    $this->actingAs($manager)->putJson(
        route('reservation.update', $reservation),
        array_merge($normalPayload, ['priority' => 'emergency'])
    )->assertUnprocessable()->assertJsonValidationErrors('priority');

    $this->actingAs($manager)->putJson(
        route('reservation.update', $reservation),
        array_merge($normalPayload, ['priority' => 'Emergency'])
    )->assertUnprocessable()->assertJsonValidationErrors('priority');

    $this->actingAs($manager)->putJson(
        route('reservation.update', $reservation),
        array_merge($normalPayload, ['priority' => 'routine'])
    )->assertSuccessful();

    $reservation->refresh();

    expect($reservation->request_type)->toBe('patient_transfer')
        ->and($reservation->priority)->toBe('routine')
        ->and($reservation->schedule_date->format('Y-m-d'))->toBe('2026-10-20')
        ->and(substr((string) $reservation->schedule_time, 0, 5))->toBe('11:30')
        ->and($reservation->status)->toBe('Approved');
});

it('does not reopen a protected reservation when it is edited as emergency', function (string $status) {
    $reservation = p52Reservation(['status' => $status]);

    $this->actingAs(p52User())->putJson(
        route('reservation.update', $reservation),
        p52Payload([
            'reservation_number' => $reservation->reservation_number,
            'request_type' => 'emergency_response',
            'status' => 'Pending',
        ])
    )->assertSuccessful();

    $reservation->refresh();

    expect($reservation->request_type)->toBe('emergency_response')
        ->and($reservation->priority)->toBe('emergency')
        ->and($reservation->status)->toBe($status);
})->with(['Rejected', 'Cancelled', 'Completed', 'Scheduled']);
