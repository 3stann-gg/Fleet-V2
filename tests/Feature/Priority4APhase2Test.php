<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Maintenance;
use App\Models\PostTripChecklist;
use App\Models\PreTripChecklist;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\SanitationLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCapability;
use App\Services\DispatchSuitabilityService;

function p42Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P42-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Logistics',
        'brand' => 'Test',
        'model' => 'Candidate',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function p42Driver(array $attributes = []): Driver
{
    return Driver::query()->create(array_merge([
        'first_name' => 'Phase',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P42-LIC-####'),
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'status' => 'Available',
    ], $attributes));
}

function p42Reservation(array $attributes = [], ?float $distance = 20): Reservation
{
    $reservation = Reservation::query()->create(array_merge([
        'reservation_number' => fake()->unique()->bothify('P42-RSV-####'),
        'patient_name' => 'Phase Two Request',
        'request_type' => 'Administrative Trip',
        'pickup_location' => 'Hospital',
        'destination' => 'Clinic',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Approved',
    ], $attributes));

    RoutePlan::query()->create([
        'route_number' => fake()->unique()->bothify('P42-ROUTE-####'),
        'reservation_id' => $reservation->id,
        'origin' => 'Hospital',
        'destination' => 'Clinic',
        'priority' => 'Normal',
        'department' => 'Logistics',
        'status' => 'Ready For Dispatch',
        'departure_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'estimated_distance' => $distance,
    ]);

    return $reservation->fresh();
}

function p42Qualification(Driver $driver, string $vehicleType): void
{
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => $vehicleType,
    ]);
}

function p42Evaluate(Reservation $reservation, Vehicle $vehicle, ?Driver $driver = null): array
{
    return app(DispatchSuitabilityService::class)->evaluate(
        $reservation->fresh(),
        $vehicle->fresh(),
        $driver?->fresh(),
    );
}

function p42Maintenance(Vehicle $vehicle, int $days): void
{
    Maintenance::query()->create([
        'maintenance_number' => fake()->unique()->bothify('P42-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Scheduled service',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => now()->addDays($days)->toDateString(),
        'cost' => 0,
        'status' => 'Completed',
    ]);
}

function p42Dispatch(Reservation $reservation): Dispatch
{
    return Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('P42-DSP-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => 'Pending',
    ]);
}

it('loads all non-archived fleet candidates through the authorized endpoint', function () {
    $user = User::factory()->create(['role' => 'dispatcher']);
    $reservation = p42Reservation();
    $ready = p42Vehicle();
    $unavailable = p42Vehicle(['readiness_status' => 'unavailable']);
    p42Vehicle(['archived_at' => now()]);

    $this->actingAs($user)
        ->getJson(route('dispatch.candidate-vehicles', $reservation))
        ->assertSuccessful()
        ->assertJsonCount(2, 'candidates')
        ->assertJsonPath('candidates.0.vehicle_id', $ready->id)
        ->assertJsonPath('candidates.1.vehicle_id', $unavailable->id)
        ->assertJsonMissingPath('candidates.0.score');
});

it('keeps capability outcomes transparent', function (bool $attachToVehicle, string $expectedState, string $expectedStatus) {
    $capability = VehicleCapability::query()->create([
        'name' => 'Stretcher Equipped',
        'slug' => 'stretcher_equipped',
    ]);
    $reservation = p42Reservation();
    $reservation->requiredCapabilities()->attach($capability);
    $vehicle = p42Vehicle();
    if ($attachToVehicle) {
        $vehicle->capabilities()->attach($capability);
    }

    $result = p42Evaluate($reservation, $vehicle);

    expect($result['capabilities']['state'])->toBe($expectedState)
        ->and($result['status'])->toBe($expectedStatus);
})->with([
    'match' => [true, 'suitable', 'suitable'],
    'missing requirement' => [false, 'warning', 'not_recommended'],
]);

it('treats a legacy request without capability requirements as neutral', function () {
    $result = p42Evaluate(p42Reservation(['request_type' => 'Administrative Trip']), p42Vehicle());

    expect($result['capabilities']['state'])->toBe('neutral')
        ->and($result['capabilities']['label'])->toBe('No special capability requirements');
});

it('maps readiness and lifecycle concerns without changing them', function (array $attributes, string $expected) {
    $vehicle = p42Vehicle($attributes);
    $before = $vehicle->readiness_status;

    $result = p42Evaluate(p42Reservation(), $vehicle);

    expect($result['status'])->toBe($expected)
        ->and($vehicle->fresh()->readiness_status)->toBe($before);
})->with([
    'ready' => [[], 'suitable'],
    'unavailable' => [['readiness_status' => 'unavailable'], 'not_recommended'],
    'maintenance readiness' => [['readiness_status' => 'maintenance'], 'not_recommended'],
    'cleaning readiness' => [['readiness_status' => 'cleaning'], 'not_recommended'],
    'charging readiness' => [['readiness_status' => 'charging'], 'not_recommended'],
    'in use lifecycle' => [['status' => 'On Trip', 'readiness_status' => 'in_use'], 'not_recommended'],
]);

it('evaluates driver qualification and license separately', function (string $case, string $expectedLabel, string $expectedStatus) {
    $vehicle = p42Vehicle(['vehicle_type' => 'Ambulance']);
    $driver = p42Driver([
        'license_expiry' => $case === 'expired'
            ? now()->subDay()->toDateString()
            : ($case === 'null_license' ? null : now()->addYear()->toDateString()),
    ]);
    if ($case !== 'unknown_qualification') {
        p42Qualification($driver, $case === 'mismatch' ? 'Motorcycle' : 'Ambulance');
    }

    $result = p42Evaluate(p42Reservation(), $vehicle, $driver);

    expect($result['driver_match']['label'])->toBe($expectedLabel)
        ->and($result['status'])->toBe($expectedStatus);
})->with([
    'qualified' => ['qualified', 'Qualified', 'suitable'],
    'mismatch' => ['mismatch', 'Qualification Mismatch', 'not_recommended'],
    'expired license' => ['expired', 'License Expired', 'not_recommended'],
    'null license expiry' => ['null_license', 'Qualified - License Expiry Not Recorded', 'suitable'],
    'unknown qualification' => ['unknown_qualification', 'Qualification Information Unavailable', 'insufficient_information'],
]);

it('does not fail candidates when no driver is selected', function () {
    $result = p42Evaluate(p42Reservation(['driver_id' => null]), p42Vehicle());

    expect($result['driver_match']['label'])->toBe('Not Evaluated - No Driver Selected')
        ->and($result['status'])->toBe('suitable');
});

it('evaluates electric range with no arbitrary reserve', function (?float $range, ?float $distance, string $expectedEnergy, string $expectedStatus) {
    $vehicle = p42Vehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'estimated_range_km' => $range,
        'battery_health_percent' => 90,
    ]);

    $result = p42Evaluate(p42Reservation([], $distance), $vehicle);

    expect($result['energy']['label'])->toBe($expectedEnergy)
        ->and($result['status'])->toBe($expectedStatus);
})->with([
    'range covers route' => [20, 20, 'Energy Suitable', 'suitable'],
    'range below route' => [19, 20, 'Energy Warning', 'not_recommended'],
    'missing route distance' => [20, null, 'Insufficient Information', 'insufficient_information'],
    'missing estimated range' => [null, 20, 'Insufficient Information', 'insufficient_information'],
]);

it('keeps PHEV fuel and battery semantics separate', function () {
    $vehicle = p42Vehicle([
        'powertrain_type' => 'plug_in_hybrid',
        'estimated_range_km' => 10,
        'battery_health_percent' => 90,
    ]);

    $result = p42Evaluate(p42Reservation([], 20), $vehicle);

    expect($result['energy']['label'])->toBe('Review Fuel and Battery')
        ->and($result['energy']['message'])->toContain('no combined driving range')
        ->and($result['status'])->toBe('suitable_with_advisory');
});

it('applies registration only where the current schema makes it relevant', function (string $type, ?int $days, string $expectedState, string $expectedStatus) {
    $vehicle = p42Vehicle([
        'vehicle_type' => $type,
        'registration_expiry' => $days === null ? null : now()->addDays($days)->toDateString(),
    ]);

    $result = p42Evaluate(p42Reservation(), $vehicle);

    expect($result['registration']['state'])->toBe($expectedState)
        ->and($result['status'])->toBe($expectedStatus);
})->with([
    'expired motorcycle' => ['Motorcycle', -1, 'expired', 'not_recommended'],
    'expiring motorcycle' => ['Motorcycle', 20, 'expiring_30_days', 'suitable_with_advisory'],
    'motorcycle not recorded' => ['Motorcycle', null, 'not_recorded', 'suitable_with_advisory'],
    'legacy non-motorcycle not applicable' => ['Service Vehicle', null, 'not_applicable', 'suitable'],
]);

it('returns an insurance warning without disabling records', function (int $days, string $expectedStatus) {
    $vehicle = p42Vehicle(['insurance_expiry' => now()->addDays($days)->toDateString()]);

    expect(p42Evaluate(p42Reservation(), $vehicle)['status'])->toBe($expectedStatus);
})->with([
    'expired' => [-1, 'not_recommended'],
    'expiring' => [20, 'suitable_with_advisory'],
]);

it('uses existing maintenance next schedules', function (int $days, string $expectedMaintenance, string $expectedStatus) {
    $vehicle = p42Vehicle();
    p42Maintenance($vehicle, $days);

    $result = p42Evaluate(p42Reservation(), $vehicle);

    expect($result['maintenance']['state'])->toBe($expectedMaintenance)
        ->and($result['status'])->toBe($expectedStatus);
})->with([
    'overdue' => [-1, 'overdue', 'not_recommended'],
    'due' => [0, 'due', 'not_recommended'],
    'due soon' => [20, 'due_soon', 'suitable_with_advisory'],
    'not due' => [90, 'not_due', 'suitable'],
]);

it('uses battery health independently of state of charge', function (float $health, string $expectedBattery, string $expectedStatus) {
    $vehicle = p42Vehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'battery_level_percent' => 20,
        'battery_health_percent' => $health,
        'estimated_range_km' => 50,
    ]);

    $result = p42Evaluate(p42Reservation([], 20), $vehicle);

    expect($result['battery_health']['label'])->toBe($expectedBattery)
        ->and($result['status'])->toBe($expectedStatus);
})->with([
    'good' => [90, 'Good', 'suitable'],
    'monitor' => [70, 'Monitor', 'suitable_with_advisory'],
    'service review' => [50, 'Service Review', 'suitable_with_advisory'],
]);

it('surfaces unresolved checklist and sanitation concerns', function (string $concern) {
    $vehicle = p42Vehicle();
    $reservation = p42Reservation(['vehicle_id' => $vehicle->id]);
    $dispatch = p42Dispatch($reservation);

    if ($concern === 'pre_trip') {
        PreTripChecklist::query()->create([
            'dispatch_id' => $dispatch->id,
            'vehicle_id' => $vehicle->id,
            'result' => 'failed',
            'checked_at' => now(),
        ]);
    } elseif ($concern === 'post_trip') {
        PostTripChecklist::query()->create([
            'dispatch_id' => $dispatch->id,
            'vehicle_id' => $vehicle->id,
            'maintenance_required' => true,
            'submitted_at' => now(),
        ]);
    } else {
        SanitationLog::query()->create([
            'vehicle_id' => $vehicle->id,
            'dispatch_id' => $dispatch->id,
            'reason' => 'routine_end_of_trip',
            'cleaning_type' => 'routine_cleaning',
            'status' => 'pending',
        ]);
    }

    $result = p42Evaluate($reservation, $vehicle);

    expect($result['status'])->toBe('not_recommended')
        ->and($result['operational_health']['reasons'])->not->toBeEmpty();
})->with(['failed pre-trip' => 'pre_trip', 'post-trip maintenance' => 'post_trip', 'active sanitation' => 'sanitation']);

it('supports motorcycle specimen transport and exposes patient transport mismatch', function (string $requestType, bool $attachCapability, string $expectedStatus) {
    $slug = $requestType === 'laboratory_specimen_transport'
        ? 'laboratory_specimen_transport'
        : 'patient_transport';
    $capability = VehicleCapability::query()->create([
        'name' => str($slug)->headline()->toString(),
        'slug' => $slug,
    ]);
    $reservation = p42Reservation(['request_type' => $requestType]);
    $reservation->requiredCapabilities()->attach($capability);
    $vehicle = p42Vehicle([
        'vehicle_type' => 'Motorcycle',
        'registration_expiry' => now()->addYear()->toDateString(),
    ]);
    if ($attachCapability) {
        $vehicle->capabilities()->attach($capability);
    }

    expect(p42Evaluate($reservation, $vehicle)['status'])->toBe($expectedStatus);
})->with([
    'specimen match' => ['laboratory_specimen_transport', true, 'suitable'],
    'patient transport mismatch' => ['patient_transfer', false, 'not_recommended'],
]);

it('supports gasoline and electric motorcycle energy paths', function (string $powertrain, float $range, string $expectedEnergy) {
    $vehicle = p42Vehicle([
        'vehicle_type' => 'Motorcycle',
        'powertrain_type' => $powertrain,
        'fuel_type' => $powertrain === 'electric' ? 'Electric' : 'Gasoline',
        'tank_capacity' => $powertrain === 'electric' ? null : 5,
        'current_fuel' => $powertrain === 'electric' ? 0 : 4,
        'estimated_range_km' => $range,
        'battery_health_percent' => $powertrain === 'electric' ? 90 : null,
        'registration_expiry' => now()->addYear()->toDateString(),
    ]);

    expect(p42Evaluate(p42Reservation([], 20), $vehicle)['energy']['label'])->toBe($expectedEnergy);
})->with([
    'gasoline motorcycle' => ['gasoline', 0, 'Energy Information Available'],
    'electric motorcycle' => ['electric', 50, 'Energy Suitable'],
]);

it('orders categories deterministically without a score', function () {
    $reservation = p42Reservation();
    $notRecommended = p42Vehicle(['readiness_status' => 'unavailable']);
    $insufficient = p42Vehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'estimated_range_km' => null,
        'battery_health_percent' => 90,
    ]);
    $advisory = p42Vehicle(['insurance_expiry' => now()->addDays(20)->toDateString()]);
    $suitable = p42Vehicle();

    $result = app(DispatchSuitabilityService::class)->compare($reservation);

    expect(collect($result['candidates'])->pluck('vehicle_id')->all())->toBe([
        $suitable->id,
        $advisory->id,
        $insufficient->id,
        $notRecommended->id,
    ])->and($result['candidates'][0])->not->toHaveKey('score');
});

it('does not persist a vehicle merely by loading candidate comparison', function () {
    $user = User::factory()->create(['role' => 'dispatcher']);
    $original = p42Vehicle();
    $candidate = p42Vehicle();
    $reservation = p42Reservation(['vehicle_id' => $original->id]);

    $this->actingAs($user)
        ->getJson(route('dispatch.candidate-vehicles', $reservation))
        ->assertSuccessful();

    expect($reservation->fresh()->vehicle_id)->toBe($original->id)
        ->and(Dispatch::query()->count())->toBe(0)
        ->and($candidate->fresh())->not->toBeNull();
});

it('persists a manually selected vehicle only with dispatch save', function () {
    $user = User::factory()->create(['role' => 'dispatcher']);
    $original = p42Vehicle();
    $candidate = p42Vehicle();
    $driver = p42Driver();
    $reservation = p42Reservation([
        'vehicle_id' => $original->id,
        'driver_id' => $driver->id,
    ]);

    $this->actingAs($user)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $candidate->id,
    ])->assertCreated();

    expect($reservation->fresh()->vehicle_id)->toBe($candidate->id)
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeTrue();
});

it('shows the current live suitability review on dispatch view', function () {
    $user = User::factory()->create(['role' => 'dispatcher']);
    $vehicle = p42Vehicle();
    $driver = p42Driver();
    p42Qualification($driver, $vehicle->vehicle_type);
    $reservation = p42Reservation([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
    ]);
    $dispatch = p42Dispatch($reservation);

    $this->actingAs($user)
        ->getJson(route('dispatch.show', $dispatch))
        ->assertSuccessful()
        ->assertJsonPath('dispatch.reservation.current_suitability_review.label', 'Suitable')
        ->assertJsonStructure([
            'dispatch' => [
                'reservation' => [
                    'current_suitability_review' => [
                        'reasons',
                        'warnings',
                        'blocking_concerns',
                        'information_gaps',
                        'operational_health',
                    ],
                ],
            ],
        ]);
});
