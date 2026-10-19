<?php

use App\Http\Controllers\ReservationController;
use App\Models\Dispatch;
use App\Models\DispatchReassignment;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

function p51Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P51-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Logistics',
        'brand' => 'Phase',
        'model' => 'Five',
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
    ], $attributes));
}

function p51Driver(Vehicle $vehicle, array $attributes = [], array $codes = ['B'], bool $qualified = true): Driver
{
    $driver = Driver::query()->create(array_merge([
        'first_name' => 'Phase',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P51-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'MT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ], $attributes));

    foreach ($codes as $code) {
        $driver->licenseCodes()->create(['code' => $code]);
    }

    if ($qualified) {
        DriverVehicleQualification::query()->create([
            'driver_id' => $driver->id,
            'vehicle_type' => $vehicle->vehicle_type,
        ]);
    }

    return $driver;
}

function p51Reservation(Vehicle $vehicle, Driver $driver): Reservation
{
    $reservation = Reservation::query()->create([
        'reservation_number' => fake()->unique()->bothify('P51-RSV-####'),
        'patient_name' => 'Phase 5A1',
        'request_type' => 'Administrative Trip',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Hospital',
        'destination' => 'Clinic',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Approved',
    ]);

    RoutePlan::query()->create([
        'route_number' => fake()->unique()->bothify('P51-ROUTE-####'),
        'reservation_id' => $reservation->id,
        'origin' => 'Hospital',
        'destination' => 'Clinic',
        'priority' => 'Normal',
        'department' => 'Logistics',
        'status' => 'Ready For Dispatch',
        'departure_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'estimated_distance' => 10,
    ]);

    return $reservation->fresh();
}

function p51Dispatch(Reservation $reservation): Dispatch
{
    return Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('P51-DSP-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => 'Pending',
    ]);
}

function p51VehiclePayload(string $vehicleType): array
{
    $payload = [
        'plate_number' => fake()->unique()->bothify('TYPE-####'),
        'vehicle_type' => $vehicleType,
        'brand' => 'Canonical',
        'model' => 'Type',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 25,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ];

    if ($vehicleType === 'Motorcycle') {
        $payload = array_merge($payload, [
            'capacity' => 2,
            'engine_displacement_cc' => 160,
            'transmission_type' => 'automatic',
            'helmet_available' => true,
            'cargo_box_available' => true,
            'cargo_capacity_kg' => 20,
            'registration_expiry' => now()->addYear()->toDateString(),
        ]);
    }

    return $payload;
}

it('removes Patient Van from canonical vehicle and driver qualification choices without deleting data', function () {
    $vehicleCount = Vehicle::query()->count();
    $qualificationCount = DriverVehicleQualification::query()->count();

    expect(Vehicle::TYPE_OPTIONS)->toBe(['Ambulance', 'Service Vehicle', 'SUV', 'Motorcycle'])
        ->and(Driver::VEHICLE_TYPE_OPTIONS)->toBe(Vehicle::TYPE_OPTIONS)
        ->and(file_get_contents(resource_path('views/components/vehicle/add-vehicle-modal.blade.php')))->not->toContain('Patient Van')
        ->and(file_get_contents(resource_path('views/components/vehicle/edit-vehicle-modal.blade.php')))->not->toContain('Patient Van')
        ->and(file_get_contents(resource_path('views/route-planning/index.blade.php')))->not->toContain('Patient Van 02')
        ->and(Vehicle::query()->count())->toBe($vehicleCount)
        ->and(DriverVehicleQualification::query()->count())->toBe($qualificationCount);
});

it('rejects Patient Van for new vehicles and driver qualifications', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('vehicles.store'), p51VehiclePayload('Patient Van'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vehicle_type');

    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);

    $this->putJson(route('drivers.update', $driver), [
        'first_name' => $driver->first_name,
        'last_name' => $driver->last_name,
        'license_number' => $driver->license_number,
        'license_class' => $driver->license_class,
        'license_expiry' => $driver->license_expiry?->format('Y-m-d'),
        'dl_codes_present' => true,
        'dl_codes' => $driver->dl_codes,
        'clutch_authorization' => $driver->clutch_authorization,
        'contact_number' => $driver->contact_number,
        'email' => $driver->email,
        'status' => $driver->status,
        'qualifications' => ['Patient Van'],
    ])->assertUnprocessable()->assertJsonValidationErrors('qualifications.0');
});

it('accepts every remaining canonical vehicle type', function (string $vehicleType) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('vehicles.store'), p51VehiclePayload($vehicleType))
        ->assertOk()
        ->assertJsonPath('vehicle.vehicle_type', $vehicleType);
})->with(['Ambulance', 'Service Vehicle', 'SUV', 'Motorcycle']);

it('returns selectable candidates as explicit vehicle and driver pairs', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $candidateVehicle = p51Vehicle();
    $candidateDriver = p51Driver($originalVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)
        ->getJson(route('dispatch.candidate-vehicles', $reservation))
        ->assertSuccessful()
        ->assertJsonFragment([
            'vehicle_id' => $candidateVehicle->id,
            'driver_id' => $candidateDriver->id,
            'driver_name' => trim("{$candidateDriver->first_name} {$candidateDriver->last_name}"),
        ]);
});

it('creates a dispatch and replaces both reservation assignment IDs atomically', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $newVehicle = p51Vehicle();
    $newDriver = p51Driver($originalVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $newVehicle->id,
        'driver_id' => $newDriver->id,
    ])->assertCreated();

    expect($reservation->fresh())
        ->vehicle_id->toBe($newVehicle->id)
        ->driver_id->toBe($newDriver->id);
});

it('edits a pending dispatch and replaces both reservation assignment IDs atomically', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $newVehicle = p51Vehicle();
    $newDriver = p51Driver($originalVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);
    $dispatch = p51Dispatch($reservation);

    $this->actingAs($dispatcher)->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'Pending',
        'vehicle_id' => $newVehicle->id,
        'driver_id' => $newDriver->id,
    ])->assertSuccessful();

    expect($reservation->fresh())
        ->vehicle_id->toBe($newVehicle->id)
        ->driver_id->toBe($newDriver->id);
});

it('preserves the existing reservation pair when no manual replacement is submitted', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);
    $reservation = p51Reservation($vehicle, $driver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
    ])->assertCreated();

    expect($reservation->fresh())
        ->vehicle_id->toBe($vehicle->id)
        ->driver_id->toBe($driver->id);
});

it('rejects a crafted mismatched pair without partially changing the reservation', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $otherVehicle = p51Vehicle(['vehicle_type' => 'Ambulance']);
    $otherDriver = p51Driver($otherVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $originalDriver->id,
    ])->assertUnprocessable();

    expect($reservation->fresh())
        ->vehicle_id->toBe($originalVehicle->id)
        ->driver_id->toBe($originalDriver->id)
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse()
        ->and($otherDriver->fresh())->not->toBeNull();
});

it('leaves both assignment IDs unchanged when an edit pair fails validation', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $ineligibleVehicle = p51Vehicle(['vehicle_type' => 'Ambulance']);
    $reservation = p51Reservation($originalVehicle, $originalDriver);
    $dispatch = p51Dispatch($reservation);

    $this->actingAs($dispatcher)->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'Pending',
        'vehicle_id' => $ineligibleVehicle->id,
        'driver_id' => $originalDriver->id,
    ])->assertUnprocessable();

    expect($reservation->fresh())
        ->vehicle_id->toBe($originalVehicle->id)
        ->driver_id->toBe($originalDriver->id);
});

it('rejects ineligible manual pairs and leaves the original assignment unchanged', function (string $case) {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $candidateVehicle = p51Vehicle();
    $driverAttributes = [];
    $codes = ['B'];
    $qualified = true;

    if ($case === 'lto') {
        $codes = ['A'];
    } elseif ($case === 'clutch') {
        $candidateVehicle->update(['transmission_type' => 'manual']);
        $driverAttributes['clutch_authorization'] = 'AT';
    } elseif ($case === 'qualification') {
        $qualified = false;
    } elseif ($case === 'readiness') {
        $candidateVehicle->update(['readiness_status' => 'maintenance']);
    } elseif ($case === 'driver availability') {
        $driverAttributes['status'] = 'On Leave';
    }

    $candidateDriver = p51Driver($candidateVehicle, $driverAttributes, $codes, $qualified);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $candidateVehicle->id,
        'driver_id' => $candidateDriver->id,
    ])->assertUnprocessable();

    expect($reservation->fresh())
        ->vehicle_id->toBe($originalVehicle->id)
        ->driver_id->toBe($originalDriver->id);
})->with(['lto', 'clutch', 'qualification', 'readiness', 'driver availability']);

it('keeps duplicate dispatch protection and reservation ownership unchanged', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);
    $reservation = p51Reservation($vehicle, $driver);
    p51Dispatch($reservation);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
    ])->assertUnprocessable();

    expect(Dispatch::query()->where('reservation_id', $reservation->id)->count())->toBe(1)
        ->and(Schema::hasColumn('dispatch', 'vehicle_id'))->toBeFalse()
        ->and(Schema::hasColumn('dispatch', 'driver_id'))->toBeFalse();
});

it('preserves recommendation pair persistence', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $replacementVehicle = p51Vehicle();
    $replacementDriver = p51Driver($replacementVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher);
    $request = Request::create('/internal/recommendation', 'POST', [
        'vehicle_id' => $replacementVehicle->id,
        'driver_id' => $replacementDriver->id,
    ]);
    $request->setUserResolver(fn (): User => $dispatcher);

    $response = app(ReservationController::class)
        ->applyDispatchRecommendation($request, $reservation);

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->fresh())
        ->vehicle_id->toBe($replacementVehicle->id)
        ->driver_id->toBe($replacementDriver->id);
});

it('preserves reassignment pair persistence', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $replacementVehicle = p51Vehicle();
    $replacementDriver = p51Driver($replacementVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);
    $dispatch = p51Dispatch($reservation);
    $reassignment = DispatchReassignment::query()->create([
        'dispatch_id' => $dispatch->id,
        'requested_by' => $dispatcher->id,
        'reason' => 'Focused Phase 5A1 reassignment regression coverage.',
        'status' => 'Requested',
        'original_vehicle_id' => $originalVehicle->id,
        'original_driver_id' => $originalDriver->id,
    ]);

    $this->actingAs($dispatcher)->postJson(route('dispatch.reassignments.approve', $reassignment), [
        'new_vehicle_id' => $replacementVehicle->id,
        'new_driver_id' => $replacementDriver->id,
    ])->assertSuccessful();

    expect($reservation->fresh())
        ->vehicle_id->toBe($replacementVehicle->id)
        ->driver_id->toBe($replacementDriver->id);
});

it('contains candidate comparison rows inside a responsive scroll region', function () {
    $component = file_get_contents(resource_path('views/components/dispatch/candidate-vehicle-comparison.blade.php'));
    $stylesheet = file_get_contents(public_path('assets/css/pages/dispatch.css'));

    expect($component)
        ->toContain('table-responsive dispatch-candidate-table-scroll')
        ->and($stylesheet)
        ->toContain('.dispatch-candidate-table-scroll')
        ->toContain('max-height: min(55vh, 36rem)')
        ->toContain('overflow: auto')
        ->toContain('position: sticky');
});
