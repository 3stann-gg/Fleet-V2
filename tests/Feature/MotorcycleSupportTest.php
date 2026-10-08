<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Maintenance;
use App\Models\Reservation;
use App\Models\SanitationLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCapability;
use Illuminate\Database\QueryException;

function motorcycleVehiclePayload(array $attributes = []): array
{
    return array_merge([
        'plate_number' => fake()->unique()->bothify('MC-####'),
        'vehicle_type' => 'Motorcycle',
        'department' => 'Logistics',
        'brand' => 'Honda',
        'model' => 'Click',
        'capacity' => 2,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 5,
        'current_fuel' => 4,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
        'engine_displacement_cc' => 160,
        'transmission_type' => 'automatic',
        'helmet_available' => true,
        'cargo_box_available' => true,
        'cargo_capacity_kg' => 20,
        'registration_expiry' => now()->addYear()->toDateString(),
    ], $attributes);
}

function motorcycleVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(motorcycleVehiclePayload($attributes));
}

function motorcycleDriver(array $attributes = []): Driver
{
    return Driver::query()->create(array_merge([
        'first_name' => 'Motorcycle',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('MC-LIC-####'),
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'status' => 'Available',
    ], $attributes));
}

function motorcycleDispatch(Vehicle $vehicle, Driver $driver): Dispatch
{
    $reservation = Reservation::query()->create([
        'reservation_number' => fake()->unique()->bothify('RSV-MC-####'),
        'patient_name' => 'Motorcycle Delivery',
        'request_type' => 'Medical Supply Delivery',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Hospital Pharmacy',
        'destination' => 'Community Clinic',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Scheduled',
    ]);

    return Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('DSP-MC-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => 'Assigned',
    ]);
}

it('accepts Motorcycle as a canonical vehicle type and stores its profile fields', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $response = $this->actingAs($user)
        ->postJson(route('vehicles.store'), motorcycleVehiclePayload());

    $response->assertOk()
        ->assertJsonPath('vehicle.vehicle_type', 'Motorcycle')
        ->assertJsonPath('vehicle.engine_displacement_cc', 160)
        ->assertJsonPath('vehicle.helmet_available', true);

    $this->assertDatabaseHas('vehicles', [
        'vehicle_type' => 'Motorcycle',
        'engine_displacement_cc' => 160,
        'transmission_type' => 'automatic',
        'cargo_capacity_kg' => 20,
    ]);
});

it('rejects unknown vehicle types and non-v1 motorcycle powertrains', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($user)
        ->postJson(route('vehicles.store'), motorcycleVehiclePayload(['vehicle_type' => 'Scooter']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vehicle_type');

    $this->postJson(route('vehicles.store'), motorcycleVehiclePayload([
        'plate_number' => 'MC-HYBRID',
        'powertrain_type' => 'hybrid',
    ]))->assertUnprocessable()->assertJsonValidationErrors('powertrain_type');

    $this->postJson(route('vehicles.store'), motorcycleVehiclePayload([
        'plate_number' => 'MC-EV-ENGINE',
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'engine_displacement_cc' => 160,
    ]))->assertUnprocessable()->assertJsonValidationErrors('engine_displacement_cc');
});

it('validates motorcycle profile field values', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($user)->postJson(route('vehicles.store'), motorcycleVehiclePayload([
        'engine_displacement_cc' => -1,
        'transmission_type' => 'continuously_variable',
        'helmet_available' => 'sometimes',
        'cargo_box_available' => 'sometimes',
        'cargo_capacity_kg' => -1,
        'registration_expiry' => 'not-a-date',
    ]))->assertUnprocessable()->assertJsonValidationErrors([
        'engine_displacement_cc',
        'transmission_type',
        'helmet_available',
        'cargo_box_available',
        'cargo_capacity_kg',
        'registration_expiry',
    ]);
});

it('keeps existing vehicle types valid and motorcycle fields optional', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $payload = motorcycleVehiclePayload([
        'plate_number' => 'LEGACY-AMB',
        'vehicle_type' => 'Ambulance',
        'powertrain_type' => 'diesel',
        'fuel_type' => 'Diesel',
        'tank_capacity' => 60,
        'current_fuel' => 30,
    ]);
    unset(
        $payload['engine_displacement_cc'],
        $payload['transmission_type'],
        $payload['helmet_available'],
        $payload['cargo_box_available'],
        $payload['cargo_capacity_kg'],
        $payload['registration_expiry'],
    );

    $this->actingAs($user)->postJson(route('vehicles.store'), $payload)->assertOk();

    $motorcycle = motorcycleVehicle([
        'plate_number' => 'MC-NULLABLE',
        'engine_displacement_cc' => null,
        'transmission_type' => null,
        'helmet_available' => null,
        'cargo_box_available' => null,
        'cargo_capacity_kg' => null,
        'registration_expiry' => null,
    ]);

    expect($motorcycle->isMotorcycle())->toBeTrue()
        ->and($motorcycle->engine_displacement_cc)->toBeNull()
        ->and($motorcycle->registration_expiry)->toBeNull();
});

it('supports gasoline and electric motorcycle energy semantics', function () {
    $gasoline = motorcycleVehicle();
    $electric = motorcycleVehicle([
        'plate_number' => 'MC-ELECTRIC',
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'battery_capacity_kwh' => 8,
        'battery_level_percent' => 80,
        'estimated_range_km' => 120,
        'charging_port_type' => 'Type 2',
        'engine_displacement_cc' => null,
    ]);

    expect($gasoline->supportsLiquidFuel())->toBeTrue()
        ->and($gasoline->supportsExternalCharging())->toBeFalse()
        ->and($electric->supportsLiquidFuel())->toBeFalse()
        ->and($electric->supportsExternalCharging())->toBeTrue()
        ->and($electric->energyAdvisory(50)['state'])->toBe('suitable');
});

it('records charging for an electric Motorcycle through the existing energy workflow', function () {
    $user = User::factory()->create(['role' => 'maintenance']);
    $vehicle = motorcycleVehicle([
        'plate_number' => 'MC-CHARGE',
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'battery_capacity_kwh' => 8,
        'battery_level_percent' => 20,
        'estimated_range_km' => 120,
        'charging_port_type' => 'Type 2',
        'engine_displacement_cc' => null,
    ]);

    $this->actingAs($user)->postJson(route('charging.store'), [
        'vehicle_id' => $vehicle->id,
        'charging_date' => now()->toDateString(),
        'charging_time' => '10:30',
        'battery_before_percent' => 20,
        'battery_after_percent' => 80,
        'energy_added_kwh' => 4.8,
        'charging_location' => 'Hospital Motor Pool',
        'charging_cost' => 72,
        'odometer' => 120,
    ])->assertCreated();

    expect($vehicle->fresh()->battery_level_percent)->toBe('80.00');
});

it('uses Motorcycle driver qualifications in dispatch suitability', function () {
    $driver = motorcycleDriver();

    expect($driver->qualificationAdvisory('Motorcycle')['state'])->toBe('neutral');

    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => 'Motorcycle',
    ]);
    $driver->unsetRelation('qualifications');

    expect($driver->qualificationAdvisory('Motorcycle')['state'])->toBe('suitable')
        ->and($driver->qualificationAdvisory('Ambulance')['state'])->toBe('warning');
});

it('blocks duplicate Motorcycle qualifications for the same driver', function () {
    $driver = motorcycleDriver();
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => 'Motorcycle',
    ]);

    expect(fn () => DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => 'Motorcycle',
    ]))->toThrow(QueryException::class);
});

it('reuses capabilities for motorcycle deliveries and warns for patient transport mismatch', function () {
    $medicalSupply = VehicleCapability::query()->create([
        'name' => 'Medical Supply Transport',
        'slug' => 'medical_supply_transport',
    ]);
    $patientTransport = VehicleCapability::query()->create([
        'name' => 'Patient Transport',
        'slug' => 'patient_transport',
    ]);
    $documentCourier = VehicleCapability::query()->create([
        'name' => 'Document Courier',
        'slug' => 'document_courier',
    ]);
    $vehicle = motorcycleVehicle();
    $vehicle->capabilities()->sync([$medicalSupply->id, $documentCourier->id]);
    $vehicle->load('capabilities');

    expect($vehicle->capabilityAdvisory([$medicalSupply])['state'])->toBe('suitable')
        ->and($vehicle->capabilityAdvisory([$documentCourier])['state'])->toBe('suitable')
        ->and($vehicle->capabilityAdvisory([$patientTransport])['state'])->toBe('warning');
});

it('stores motorcycle pre-trip checks using the existing checklist workflow', function () {
    $user = User::factory()->create(['role' => 'driver']);
    $dispatch = motorcycleDispatch(
        motorcycleVehicle(),
        motorcycleDriver(['user_id' => $user->id])
    );

    $this->actingAs($user)->postJson(route('dispatch.pre-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'result' => 'passed',
        'checklist_data' => [
            'headlight' => true,
            'tail_light' => true,
            'turn_signals' => true,
            'helmet_available' => true,
            'cargo_box_secure' => true,
            'medical_supply_storage_available' => true,
            'cargo_secured' => true,
        ],
    ])->assertCreated();

    expect($dispatch->preTripChecklist()->first()->checklist_data)
        ->toMatchArray(['headlight' => true, 'cargo_secured' => true]);
});

it('updates the correct motorcycle energy source after a post-trip checklist', function (string $powertrain, array $attributes, array $payload, string $column, float $expected) {
    $user = User::factory()->create(['role' => 'driver']);
    $vehicle = motorcycleVehicle(array_merge(['powertrain_type' => $powertrain], $attributes));
    $dispatch = motorcycleDispatch(
        $vehicle,
        motorcycleDriver(['user_id' => $user->id])
    );

    $this->actingAs($user)->postJson(route('dispatch.post-trip.store'), array_merge([
        'dispatch_id' => $dispatch->id,
    ], $payload))->assertCreated();

    expect((float) $vehicle->fresh()->{$column})->toBe($expected);
})->with([
    'gasoline' => ['gasoline', [], ['fuel_level_remaining' => 50], 'current_fuel', 2.5],
    'electric' => ['electric', [
        'plate_number' => 'MC-POST-EV',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'battery_capacity_kwh' => 8,
        'battery_level_percent' => 80,
        'estimated_range_km' => 120,
        'engine_displacement_cc' => null,
    ], ['battery_level_remaining' => 45], 'battery_level_percent', 45.0],
]);

it('reuses maintenance and sanitation workflows for motorcycles', function () {
    $vehicle = motorcycleVehicle();
    $maintenance = Maintenance::query()->create([
        'maintenance_number' => 'MNT-MC-0001',
        'vehicle_id' => $vehicle->id,
        'maintenance_type' => 'Preventive',
        'description' => 'Motorcycle chain and brake inspection',
        'maintenance_date' => now()->toDateString(),
        'next_schedule' => now()->addMonth()->toDateString(),
        'cost' => 500,
        'status' => 'Scheduled',
    ]);
    $sanitation = SanitationLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
        'status' => 'pending',
    ]);

    expect($vehicle->maintenances()->find($maintenance->id))->not->toBeNull()
        ->and($vehicle->sanitationLogs()->find($sanitation->id))->not->toBeNull();
});

it('supports motorcycle fleet filtering without changing report data semantics', function () {
    motorcycleVehicle(['plate_number' => 'MC-REPORT']);
    Vehicle::query()->create([
        'plate_number' => 'AMB-REPORT',
        'vehicle_type' => 'Ambulance',
        'brand' => 'Toyota',
        'model' => 'Hiace',
        'capacity' => 4,
        'powertrain_type' => 'diesel',
        'fuel_type' => 'Diesel',
        'tank_capacity' => 60,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'status' => 'Available',
    ]);

    expect(Vehicle::query()->where('vehicle_type', 'Motorcycle')->pluck('plate_number')->all())
        ->toBe(['MC-REPORT'])
        ->and(Vehicle::query()->selectRaw('vehicle_type, count(*) as total')->groupBy('vehicle_type')->pluck('total', 'vehicle_type')->all())
        ->toMatchArray(['Motorcycle' => 1, 'Ambulance' => 1]);
});
