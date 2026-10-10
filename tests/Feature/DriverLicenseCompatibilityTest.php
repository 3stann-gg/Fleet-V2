<?php

use App\Models\Driver;
use App\Models\DriverLicenseCode;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\DispatchSuitabilityService;
use App\Services\DriverLicenseCompatibilityService;
use Illuminate\Database\QueryException;

function ltoDriver(array $attributes = [], array $codes = ['B']): Driver
{
    $driver = Driver::query()->create(array_merge([
        'first_name' => 'LTO',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('LTO-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'MT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'status' => 'Available',
    ], $attributes));

    foreach ($codes as $code) {
        $driver->licenseCodes()->create(['code' => $code]);
    }

    return $driver->fresh('licenseCodes');
}

function ltoVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('LTO-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Logistics',
        'brand' => 'Test',
        'model' => 'Vehicle',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function ltoReservation(array $attributes = []): Reservation
{
    $reservation = Reservation::query()->create(array_merge([
        'reservation_number' => fake()->unique()->bothify('LTO-RSV-####'),
        'patient_name' => 'Compatibility Review',
        'request_type' => 'Administrative Trip',
        'pickup_location' => 'Hospital',
        'destination' => 'Clinic',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Approved',
    ], $attributes));

    RoutePlan::query()->create([
        'route_number' => fake()->unique()->bothify('LTO-ROUTE-####'),
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

function ltoCompatibility(Driver $driver, Vehicle $vehicle): array
{
    return app(DriverLicenseCompatibilityService::class)->evaluate(
        $driver->fresh('licenseCodes'),
        $vehicle->fresh(),
    );
}

function ltoDriverUpdatePayload(Driver $driver, array $overrides = []): array
{
    return array_merge([
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
    ], $overrides);
}

it('maps LTO vehicle categories to the canonical required DL Code', function (string $category, string $code) {
    expect(DriverLicenseCompatibilityService::requiredDlCode($category))->toBe($code);
})->with([
    'L1' => ['L1', 'A'], 'L2' => ['L2', 'A'], 'L3' => ['L3', 'A'],
    'L4' => ['L4', 'A1'], 'L5' => ['L5', 'A1'], 'L6' => ['L6', 'A1'], 'L7' => ['L7', 'A1'],
    'M1' => ['M1', 'B'], 'M2' => ['M2', 'B1'], 'M3' => ['M3', 'D'],
    'N1' => ['N1', 'B2'], 'N2' => ['N2', 'C'], 'N3' => ['N3', 'C'],
    'O1' => ['O1', 'BE'], 'O2' => ['O2', 'BE'], 'O3' => ['O3', 'CE'], 'O4' => ['O4', 'CE'],
]);

it('does not guess a DL Code when vehicle category is missing', function () {
    $result = ltoCompatibility(ltoDriver(), ltoVehicle(['lto_vehicle_category' => null]));

    expect($result['overall_status'])->toBe('insufficient_information')
        ->and($result['required_dl_code'])->toBeNull()
        ->and($result['dl_code_status']['label'])->toContain('Not Determined');
});

it('supports multiple DL Codes and matches the required code', function () {
    $result = ltoCompatibility(ltoDriver([], ['A', 'B', 'B1']), ltoVehicle(['lto_vehicle_category' => 'M2']));

    expect($result['overall_status'])->toBe('compatible')
        ->and($result['required_dl_code'])->toBe('B1')
        ->and($result['driver_dl_codes'])->toBe(['A', 'B', 'B1']);
});

it('marks a missing required DL Code as incompatible', function () {
    $result = ltoCompatibility(ltoDriver([], ['B', 'B1']), ltoVehicle(['lto_vehicle_category' => 'L3']));

    expect($result['overall_status'])->toBe('not_compatible')
        ->and($result['dl_code_status']['state'])->toBe('missing_required_code');
});

it('handles a legacy driver without recorded DL Codes neutrally', function () {
    $result = ltoCompatibility(ltoDriver([], []), ltoVehicle());

    expect($result['overall_status'])->toBe('insufficient_information')
        ->and($result['dl_code_status']['state'])->toBe('not_recorded');
});

it('prevents duplicate DL Codes at the database level', function () {
    $driver = ltoDriver([], ['B']);

    expect(fn () => DriverLicenseCode::query()->create([
        'driver_id' => $driver->id,
        'code' => 'B',
    ]))->toThrow(QueryException::class);
});

it('evaluates clutch authorization against vehicle transmission', function (string $authorization, string $transmission, string $expectedState) {
    $result = ltoCompatibility(
        ltoDriver(['clutch_authorization' => $authorization]),
        ltoVehicle(['transmission_type' => $transmission]),
    );

    expect($result['transmission_status']['state'])->toBe($expectedState);
})->with([
    'MT with Manual' => ['MT', 'manual', 'compatible'],
    'MT with Automatic' => ['MT', 'automatic', 'compatible'],
    'AT with Automatic' => ['AT', 'automatic', 'compatible'],
    'AT with Manual' => ['AT', 'manual', 'not_compatible'],
]);

it('treats missing clutch authorization and semi-automatic transmission as information gaps', function (?string $authorization, ?string $transmission, string $expectedState) {
    $result = ltoCompatibility(
        ltoDriver(['clutch_authorization' => $authorization]),
        ltoVehicle(['transmission_type' => $transmission]),
    );

    expect($result['overall_status'])->toBe('insufficient_information')
        ->and($result['transmission_status']['state'])->toBe($expectedState);
})->with([
    'missing authorization' => [null, 'manual', 'not_recorded'],
    'missing transmission' => ['MT', null, 'not_recorded'],
    'semi automatic' => ['MT', 'semi_automatic', 'requires_review'],
]);

it('evaluates license expiry consistently with fleet compliance thresholds', function (?string $expiry, string $expectedState, string $expectedOverall) {
    $result = ltoCompatibility(ltoDriver(['license_expiry' => $expiry]), ltoVehicle());

    expect($result['license_expiry_status']['state'])->toBe($expectedState)
        ->and($result['overall_status'])->toBe($expectedOverall);
})->with([
    'valid' => [now()->addYear()->toDateString(), 'valid', 'compatible'],
    'expired' => [now()->subDay()->toDateString(), 'expired', 'not_compatible'],
    'expiring soon' => [now()->addDays(20)->toDateString(), 'expiring_30_days', 'compatible_with_advisory'],
    'not recorded' => [null, 'not_recorded', 'insufficient_information'],
]);

it('uses an Ambulance actual category and enforces Professional classification', function (string $classification, string $expectedOverall) {
    $vehicle = ltoVehicle(['vehicle_type' => 'Ambulance', 'lto_vehicle_category' => 'M2']);
    $driver = ltoDriver(['license_class' => $classification], ['B1']);
    $result = ltoCompatibility($driver, $vehicle);

    expect($result['required_dl_code'])->toBe('B1')
        ->and($result['overall_status'])->toBe($expectedOverall)
        ->and($result['special_purpose_status']['label'])->toBe('Not Recorded');
})->with([
    'professional' => ['Professional', 'compatible_with_advisory'],
    'non professional' => ['Non-Professional', 'not_compatible'],
    'legacy unknown' => ['Legacy Class', 'insufficient_information'],
]);

it('keeps hospital qualification and legal LTO compatibility separate', function (bool $hospitalQualified, array $codes, string $expectedHospitalImpact, string $expectedLegalStatus) {
    $driver = ltoDriver([], $codes);
    $vehicle = ltoVehicle(['vehicle_type' => 'Motorcycle', 'lto_vehicle_category' => 'L3']);
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => $hospitalQualified ? 'Motorcycle' : 'Service Vehicle',
    ]);

    $result = app(DispatchSuitabilityService::class)->evaluate(ltoReservation(), $vehicle, $driver);

    expect($result['driver_match']['impact'])->toBe($expectedHospitalImpact)
        ->and($result['driver_license']['overall_status'])->toBe($expectedLegalStatus);
})->with([
    'both pass' => [true, ['A'], 'suitable', 'compatible'],
    'hospital passes legal fails' => [true, ['B'], 'suitable', 'not_compatible'],
    'legal passes hospital fails' => [false, ['A'], 'blocking', 'compatible'],
]);

it('does not fail a vehicle when no driver is selected', function () {
    $result = app(DispatchSuitabilityService::class)->evaluate(ltoReservation(), ltoVehicle(), null);

    expect($result['driver_license']['overall_status'])->toBe('not_evaluated')
        ->and($result['driver_license']['label'])->toContain('No Driver Selected');
});

it('rejects arbitrary and duplicate DL Codes server-side', function (array $codes) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $driver = ltoDriver();

    $this->actingAs($manager)
        ->putJson(route('drivers.update', $driver), ltoDriverUpdatePayload($driver, ['dl_codes' => $codes]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dl_codes.1');
})->with([
    'arbitrary code' => [['B', 'X']],
    'duplicate code' => [['B', 'B']],
]);

it('rejects invalid clutch authorization server-side', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $driver = ltoDriver();

    $this->actingAs($manager)
        ->putJson(route('drivers.update', $driver), ltoDriverUpdatePayload($driver, ['clutch_authorization' => 'CVT']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('clutch_authorization');
});

it('rejects an invalid LTO vehicle category server-side', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = ltoVehicle();

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), [
            'plate_number' => $vehicle->plate_number,
            'vehicle_type' => $vehicle->vehicle_type,
            'lto_vehicle_category' => 'M9',
            'department' => $vehicle->department,
            'capacity' => $vehicle->capacity,
            'powertrain_type' => $vehicle->powertrain_type,
            'fuel_type' => $vehicle->fuel_type,
            'tank_capacity' => $vehicle->tank_capacity,
            'transmission_type' => $vehicle->transmission_type,
            'status' => $vehicle->status,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('lto_vehicle_category');
});

it('allows Fleet Manager to update canonical licensing data', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $driver = ltoDriver([], ['B']);

    $this->actingAs($manager)
        ->putJson(route('drivers.update', $driver), ltoDriverUpdatePayload($driver, [
            'dl_codes' => ['A', 'B1'],
            'clutch_authorization' => 'AT',
        ]))
        ->assertSuccessful();

    expect($driver->fresh('licenseCodes')->dl_codes)->toBe(['A', 'B1'])
        ->and($driver->fresh()->clutch_authorization)->toBe('AT');
});

it('allows Fleet Manager to record vehicle category and transmission for a non-motorcycle', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = ltoVehicle(['lto_vehicle_category' => null, 'transmission_type' => null]);

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), [
            'plate_number' => $vehicle->plate_number,
            'vehicle_type' => $vehicle->vehicle_type,
            'lto_vehicle_category' => 'N1',
            'department' => $vehicle->department,
            'capacity' => $vehicle->capacity,
            'powertrain_type' => $vehicle->powertrain_type,
            'fuel_type' => $vehicle->fuel_type,
            'tank_capacity' => $vehicle->tank_capacity,
            'transmission_type' => 'manual',
            'status' => $vehicle->status,
        ])
        ->assertSuccessful();

    expect($vehicle->fresh()->lto_vehicle_category)->toBe('N1')
        ->and($vehicle->fresh()->transmission_type)->toBe('manual')
        ->and($vehicle->fresh()->required_dl_code)->toBe('B2');
});

it('prevents Maintenance from changing vehicle legal classification fields', function () {
    $maintenance = User::factory()->create(['role' => 'maintenance']);
    $vehicle = ltoVehicle();

    $this->actingAs($maintenance)
        ->putJson(route('vehicles.update', $vehicle), [
            'lto_vehicle_category' => 'N1',
            'transmission_type' => 'manual',
        ])
        ->assertForbidden();

    expect($vehicle->fresh()->lto_vehicle_category)->toBe('M1')
        ->and($vehicle->fresh()->transmission_type)->toBe('automatic');
});

it('forbids non-Fleet-Manager roles from mutating Driver legal-license data', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $driver = ltoDriver();

    $this->actingAs($user)
        ->putJson(route('drivers.update', $driver), ltoDriverUpdatePayload($driver, ['dl_codes' => ['A']]))
        ->assertForbidden();
})->with([
    'dispatcher', 'driver', 'maintenance', 'finance', 'department_head', 'it_admin',
]);
