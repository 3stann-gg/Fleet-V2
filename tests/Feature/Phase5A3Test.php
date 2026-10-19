<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TomTomRoutingService;
use Illuminate\Support\Facades\Crypt;

function p53User(): User
{
    return User::factory()->create(['role' => 'fleet_manager']);
}

/** @return array{0: Vehicle, 1: Driver} */
function p53Assignment(array $vehicleAttributes = [], array $driverAttributes = []): array
{
    $vehicle = Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P53-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Operations',
        'brand' => 'Phase',
        'model' => 'Five A Three',
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
    ], $vehicleAttributes));

    $driver = Driver::query()->create(array_merge([
        'first_name' => 'Phase',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P53-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ], $driverAttributes));

    $driver->licenseCodes()->create(['code' => 'B']);
    DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => $vehicle->vehicle_type,
    ]);

    return [$vehicle, $driver];
}

function p53Reservation(array $attributes = []): Reservation
{
    [$vehicle, $driver] = p53Assignment();

    return Reservation::query()->create(array_merge([
        'reservation_number' => fake()->unique()->bothify('P53-RSV-####'),
        'patient_name' => 'Emergency Patient',
        'request_type' => 'emergency_response',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Emergency Department',
        'destination' => 'Referral Hospital',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'emergency',
        'status' => 'Approved',
    ], $attributes));
}

function p53Route(Reservation $reservation, array $attributes = []): RoutePlan
{
    return RoutePlan::query()->create(array_merge([
        'route_number' => fake()->unique()->bothify('P53-ROUTE-####'),
        'reservation_id' => $reservation->id,
        'origin' => 'Emergency Department',
        'destination' => 'Referral Hospital',
        'priority' => $reservation->priority,
        'department' => 'Emergency',
        'status' => 'Planned',
        'departure_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
    ], $attributes));
}

function p53UpdatePayload(array $attributes = []): array
{
    return array_merge([
        'origin' => 'Emergency Department',
        'origin_latitude' => 14.5995,
        'origin_longitude' => 120.9842,
        'destination' => 'Referral Hospital',
        'destination_latitude' => 14.6091,
        'destination_longitude' => 121.0223,
        'department' => 'Emergency',
        'status' => 'Planned',
        'estimated_distance' => 12.5,
        'estimated_time' => 28,
        'optimization_strategy' => 'TomTom Traffic-Aware Fastest',
        'optimization_score' => 94,
        'optimization_applied' => true,
        'stops' => [],
    ], $attributes);
}

function p53OptimizationProof(User $user, Reservation $reservation, array $payload): string
{
    return Crypt::encryptString(json_encode([
        'user_id' => $user->id,
        'reservation_id' => $reservation->id,
        'issued_at' => now()->timestamp,
        'origin_latitude' => $payload['origin_latitude'],
        'origin_longitude' => $payload['origin_longitude'],
        'destination_latitude' => $payload['destination_latitude'],
        'destination_longitude' => $payload['destination_longitude'],
        'coordinates' => [
            [
                'latitude' => $payload['origin_latitude'],
                'longitude' => $payload['origin_longitude'],
            ],
            [
                'latitude' => $payload['destination_latitude'],
                'longitude' => $payload['destination_longitude'],
            ],
        ],
        'estimated_distance' => $payload['estimated_distance'],
        'estimated_time' => $payload['estimated_time'],
        'optimization_strategy' => $payload['optimization_strategy'],
        'provider' => 'tomtom',
    ], JSON_THROW_ON_ERROR));
}

function p53TomTomResult(): array
{
    return [
        'provider' => 'tomtom',
        'distance_meters' => 12500,
        'travel_time_seconds' => 1680,
        'traffic_delay_seconds' => 120,
        'traffic_length_meters' => 500,
        'no_traffic_travel_time_seconds' => 1560,
        'historic_traffic_travel_time_seconds' => 1620,
        'live_traffic_travel_time_seconds' => 1680,
        'departure_time' => now()->toIso8601String(),
        'arrival_time' => now()->addMinutes(28)->toIso8601String(),
        'optimized_waypoints' => [],
        'instructions' => [],
        'points' => [
            ['latitude' => 14.5995, 'longitude' => 120.9842],
            ['latitude' => 14.6091, 'longitude' => 121.0223],
        ],
    ];
}

it('centralizes canonical priority normalization and emergency detection', function () {
    expect(Reservation::canonicalPriority('emergency'))->toBe('emergency')
        ->and(Reservation::canonicalPriority('Emergency'))->toBe('emergency')
        ->and(Reservation::canonicalPriority('High'))->toBe('urgent')
        ->and(Reservation::canonicalPriority('Normal'))->toBe('routine')
        ->and(Reservation::canonicalPriority('Low'))->toBe('scheduled')
        ->and(Reservation::isEmergencyRequestType('emergency_response'))->toBeTrue()
        ->and(Reservation::isEmergencyRequestType('Emergency Transfer'))->toBeTrue();
});

it('copies canonical reservation priority on create and ignores crafted route priority', function () {
    $reservation = p53Reservation([
        'request_type' => 'staff_transport',
        'patient_name' => null,
        'priority' => 'High',
    ]);

    $response = $this->actingAs(p53User())->postJson(route('route-planning.store'), [
        'reservation_id' => $reservation->id,
        'department' => 'Operations',
        'priority' => 'Emergency',
    ]);

    $response->assertCreated()->assertJsonPath('routePlan.priority', 'urgent');
    $this->assertDatabaseHas('route_plans', [
        'reservation_id' => $reservation->id,
        'priority' => 'urgent',
        'status' => 'Draft',
    ]);
});

it('re-synchronizes priority from the reservation on edit and exposes its canonical label', function () {
    $reservation = p53Reservation([
        'request_type' => 'staff_transport',
        'patient_name' => null,
        'priority' => 'Normal',
    ]);
    $routePlan = p53Route($reservation, [
        'priority' => 'Emergency',
        'status' => 'Draft',
    ]);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'status' => 'Planned',
            'priority' => 'Emergency',
            'optimization_applied' => false,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.priority', 'routine')
        ->assertJsonPath('routePlan.reservation.priority_label', 'Routine');
});

it('duplicates with the target reservation priority instead of the source route priority', function () {
    $sourceReservation = p53Reservation(['priority' => 'emergency']);
    $sourceRoute = p53Route($sourceReservation, ['priority' => 'Emergency']);
    $targetReservation = p53Reservation([
        'request_type' => 'administrative_trip',
        'patient_name' => null,
        'priority' => 'Low',
    ]);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.duplicate', $sourceRoute), [
            'reservation_id' => $targetReservation->id,
        ])
        ->assertCreated()
        ->assertJsonPath('routePlan.priority', 'scheduled');
});

it('automatically makes a planned emergency route ready only after a valid optimization is persisted', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $vehicleId = $reservation->vehicle_id;
    $driverId = $reservation->driver_id;
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    expect($routePlan->fresh()->status)->toBe('Ready For Dispatch')
        ->and($reservation->fresh()->vehicle_id)->toBe($vehicleId)
        ->and($reservation->fresh()->driver_id)->toBe($driverId)
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

it('promotes a draft emergency route through its valid planned transition when trusted optimization is applied', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['status' => 'Draft']);
    $user = p53User();
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    expect($routePlan->fresh()->status)->toBe('Ready For Dispatch');
});

it('does not let an OSRM fallback authorize draft emergency promotion', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['status' => 'Draft']);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_strategy' => 'OSRM Fastest Route (Fallback)',
            'optimization_proof' => null,
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Draft')
        ->and($routePlan->fresh()->estimated_distance)->toBeNull();
});

it('does not mark an emergency route ready when optimization is calculated but not persisted', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    expect($routePlan->fresh()->status)->toBe('Planned');
});

it('rejects incomplete or unsafe emergency readiness without partially mutating the route', function (string $case) {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $payload = p53UpdatePayload();
    $payload['optimization_proof'] = p53OptimizationProof($user, $reservation, $payload);

    match ($case) {
        'distance' => $payload['estimated_distance'] = null,
        'time' => $payload['estimated_time'] = null,
        'strategy' => $payload['optimization_strategy'] = null,
        'coordinates' => $payload['origin_latitude'] = null,
        'vehicle' => $reservation->update(['vehicle_id' => null]),
        'driver' => $reservation->update(['driver_id' => null]),
        'pair' => $reservation->driver->update(['status' => 'Off Duty']),
        'approval' => $reservation->update(['status' => 'Pending']),
    };

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), $payload)
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->fresh()->estimated_distance)->toBeNull()
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
})->with(['distance', 'time', 'strategy', 'coordinates', 'vehicle', 'driver', 'pair', 'approval']);

it('rejects a forged applied marker and fabricated route metrics', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['priority' => 'Normal']);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload())
        ->assertUnprocessable()
        ->assertJsonPath('success', false);

    expect($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->fresh()->estimated_distance)->toBeNull()
        ->and($routePlan->fresh()->priority)->toBe('Normal');
});

it('rejects a valid server proof when submitted stops use a different order', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);
    $user = p53User();
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn(p53TomTomResult());
    $this->app->instance(TomTomRoutingService::class, $tomTom);
    $stopOne = ['latitude' => 14.6010, 'longitude' => 120.9900];
    $stopTwo = ['latitude' => 14.6050, 'longitude' => 121.0100];

    $optimization = $this->actingAs($user)
        ->postJson(route('route-planning.traffic-route'), [
            'reservation_id' => $reservation->id,
            'coordinates' => [
                ['latitude' => 14.5995, 'longitude' => 120.9842],
                $stopOne,
                $stopTwo,
                ['latitude' => 14.6091, 'longitude' => 121.0223],
            ],
        ])
        ->assertSuccessful();

    $this->actingAs($user)
        ->postJson(route('route-planning.transition', $routePlan), p53UpdatePayload([
            'optimization_proof' => $optimization->json('optimization_proof'),
            'optimization_strategy' => 'TomTom Traffic-Aware Optimized',
            'stops' => [
                ['location' => 'Stop Two', ...$stopTwo],
                ['location' => 'Stop One', ...$stopOne],
            ],
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Planned')
        ->and($routePlan->stops()->count())->toBe(0);
});

it('keeps emergency readiness server-owned instead of accepting a manual status selection', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation);

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $routePlan), p53UpdatePayload([
            'status' => 'Ready For Dispatch',
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe('Planned');
});

it('protects dispatch-locked, archived, and completed emergency routes from readiness regression', function (string $state) {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, ['status' => $state === 'dispatch' ? 'Planned' : $state]);

    if ($state === 'dispatch') {
        Dispatch::query()->create([
            'dispatch_number' => fake()->unique()->bothify('P53-DSP-####'),
            'reservation_id' => $reservation->id,
            'dispatch_date' => now()->addDay()->toDateString(),
            'departure_time' => '09:00:00',
            'trip_status' => 'Pending',
        ]);
    }

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $routePlan), p53UpdatePayload([
            'status' => $state === 'dispatch' ? 'Planned' : 'Ready For Dispatch',
        ]))
        ->assertUnprocessable();

    expect($routePlan->fresh()->status)->toBe($state === 'dispatch' ? 'Planned' : $state);
})->with(['dispatch', 'Archived', 'Completed']);

it('keeps an already ready emergency route stable and does not create a dispatch', function () {
    $reservation = p53Reservation();
    $routePlan = p53Route($reservation, [
        'status' => 'Ready For Dispatch',
        'estimated_distance' => 12.5,
        'estimated_time' => 28,
        'optimization_strategy' => 'Fastest',
    ]);

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $routePlan), p53UpdatePayload([
            'status' => 'Ready For Dispatch',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch');

    expect(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse();
});

it('preserves draft planning and non-emergency route lifecycle behavior', function () {
    $draftEmergency = p53Route(p53Reservation(), ['status' => 'Draft']);
    $normalReservation = p53Reservation([
        'request_type' => 'administrative_trip',
        'patient_name' => null,
        'priority' => 'routine',
    ]);
    $normalRoute = p53Route($normalReservation);

    $this->actingAs(p53User())
        ->postJson(route('route-planning.transition', $draftEmergency), p53UpdatePayload([
            'optimization_applied' => false,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Planned');

    $this->actingAs(p53User())
        ->putJson(route('route-planning.update', $normalRoute), p53UpdatePayload())
        ->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Planned');
});
