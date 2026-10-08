<?php

use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\SanitationLog;
use App\Models\TripLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCapability;
use Illuminate\Database\QueryException;

function priority3User(string $role = 'driver'): User
{
    return User::factory()->create(['role' => $role]);
}

function priority3Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P3-###'),
        'vehicle_type' => 'Ambulance',
        'brand' => 'Toyota',
        'model' => 'Hiace',
        'capacity' => 4,
        'fuel_type' => 'Diesel',
        'powertrain_type' => 'diesel',
        'tank_capacity' => 60,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function priority3Driver(array $attributes = []): Driver
{
    return Driver::query()->create(array_merge([
        'first_name' => 'Test',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('LIC-####'),
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'status' => 'Available',
    ], $attributes));
}

function priority3Dispatch(?Vehicle $vehicle = null, ?Driver $driver = null): Dispatch
{
    $reservation = Reservation::query()->create([
        'reservation_number' => fake()->unique()->bothify('RSV-P3-####'),
        'patient_name' => 'Priority Three Patient',
        'request_type' => 'Patient Transport',
        'vehicle_id' => $vehicle?->id,
        'driver_id' => $driver?->id,
        'pickup_location' => 'Ward A',
        'destination' => 'Diagnostic Center',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Scheduled',
    ]);

    return Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('DSP-P3-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => 'Assigned',
    ]);
}

it('validates the dispatch and assigned vehicle before a pre-trip checklist', function () {
    $user = priority3User();
    $this->actingAs($user)
        ->postJson(route('dispatch.pre-trip.store'), ['dispatch_id' => 999999, 'result' => 'passed'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dispatch_id');

    $dispatch = priority3Dispatch(null, priority3Driver(['user_id' => $user->id]));
    $this->postJson(route('dispatch.pre-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'result' => 'passed',
    ])->assertUnprocessable()->assertJsonPath('success', false);
});

it('stores checked_at, checklist JSON, and blocks duplicate pre-trip records', function () {
    $user = priority3User();
    $dispatch = priority3Dispatch(
        priority3Vehicle(),
        priority3Driver(['user_id' => $user->id])
    );
    $payload = [
        'dispatch_id' => $dispatch->id,
        'result' => 'passed',
        'checklist_data' => ['tires' => true, 'brakes' => true],
    ];

    $this->actingAs($user)->postJson(route('dispatch.pre-trip.store'), $payload)->assertCreated();
    $checklist = $dispatch->preTripChecklist()->first();
    expect($checklist->checked_at)->not->toBeNull()
        ->and($checklist->checklist_data['tires'])->toBeTrue();

    $this->postJson(route('dispatch.pre-trip.store'), $payload)->assertConflict();
});

it('marks a failed pre-trip vehicle unavailable', function () {
    $user = priority3User();
    $vehicle = priority3Vehicle(['readiness_status' => 'maintenance']);
    $dispatch = priority3Dispatch(
        $vehicle,
        priority3Driver(['user_id' => $user->id])
    );

    $this->actingAs($user)->postJson(route('dispatch.pre-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'result' => 'failed',
        'remarks' => 'Brake warning light is active.',
    ])->assertCreated();

    expect($vehicle->fresh()->readiness_status)->toBe('unavailable');
});

it('requires an assigned vehicle and blocks duplicate post-trip records', function () {
    $user = priority3User();
    $driver = priority3Driver(['user_id' => $user->id]);
    $missingVehicle = priority3Dispatch(null, $driver);
    $this->actingAs($user)->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $missingVehicle->id,
    ])->assertUnprocessable();

    $dispatch = priority3Dispatch(priority3Vehicle(), $driver);
    $this->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'final_odometer' => 120,
    ])->assertCreated();
    $this->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'final_odometer' => 120,
    ])->assertConflict();
});

it('rejects decreasing or contradictory post-trip odometers and uses TripLog as authoritative', function () {
    $user = priority3User();
    $vehicle = priority3Vehicle(['current_odometer' => 100]);
    $dispatch = priority3Dispatch(
        $vehicle,
        priority3Driver(['user_id' => $user->id])
    );

    $this->actingAs($user)->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'final_odometer' => 90,
    ])->assertUnprocessable();

    TripLog::query()->create([
        'dispatch_id' => $dispatch->id,
        'start_odometer' => 100,
        'end_odometer' => 140,
        'distance' => 40,
        'fuel_used' => 5,
    ]);
    $this->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $dispatch->id,
        'final_odometer' => 130,
    ])->assertUnprocessable();
    $this->postJson(route('dispatch.post-trip.store'), ['dispatch_id' => $dispatch->id])->assertCreated();

    expect((float) $dispatch->postTripChecklist()->first()->final_odometer)->toBe(140.0)
        ->and((float) $vehicle->fresh()->current_odometer)->toBe(140.0);
});

it('applies maintenance and cleaning readiness without creating downstream transactions', function () {
    $user = priority3User();
    $maintenanceVehicle = priority3Vehicle(['readiness_status' => 'in_use']);
    $driver = priority3Driver(['user_id' => $user->id]);
    $maintenanceDispatch = priority3Dispatch($maintenanceVehicle, $driver);
    $this->actingAs($user)->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $maintenanceDispatch->id,
        'maintenance_required' => true,
    ])->assertCreated();
    expect($maintenanceVehicle->fresh()->readiness_status)->toBe('maintenance');

    $cleaningVehicle = priority3Vehicle(['readiness_status' => 'in_use']);
    $cleaningDispatch = priority3Dispatch($cleaningVehicle, $driver);
    $this->postJson(route('dispatch.post-trip.store'), [
        'dispatch_id' => $cleaningDispatch->id,
        'cleaning_required' => true,
    ])->assertCreated();
    expect($cleaningVehicle->fresh()->readiness_status)->toBe('cleaning')
        ->and(SanitationLog::query()->where('vehicle_id', $cleaningVehicle->id)->exists())->toBeFalse();
});

it('returns an unrestricted completed-trip vehicle from in-use to ready', function () {
    $user = priority3User();
    $vehicle = priority3Vehicle(['readiness_status' => 'in_use']);
    $dispatch = priority3Dispatch(
        $vehicle,
        priority3Driver(['user_id' => $user->id])
    );

    $this->actingAs($user)->postJson(route('dispatch.post-trip.store'), ['dispatch_id' => $dispatch->id])
        ->assertCreated();

    expect($vehicle->fresh()->readiness_status)->toBe('ready');
});

it('sets cleaning for pending and in-progress sanitation records', function (string $status) {
    $user = priority3User('fleet_manager');
    $vehicle = priority3Vehicle();

    $this->actingAs($user)->postJson(route('sanitation.store'), [
        'vehicle_id' => $vehicle->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
        'status' => $status,
    ])->assertCreated();

    expect($vehicle->fresh()->readiness_status)->toBe('cleaning');
})->with(['pending', 'in_progress']);

it('completes sanitation only when no active or higher restriction remains', function () {
    $user = priority3User('fleet_manager');
    $vehicle = priority3Vehicle(['readiness_status' => 'cleaning']);
    $first = SanitationLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
        'status' => 'in_progress',
    ]);
    SanitationLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'reason' => 'contamination_concern',
        'cleaning_type' => 'disinfection',
        'status' => 'pending',
    ]);

    $this->actingAs($user)->putJson(route('sanitation.update', $first), ['status' => 'completed'])->assertOk();
    expect($vehicle->fresh()->readiness_status)->toBe('cleaning');

    $vehicle->sanitationLogs()->where('status', 'pending')->update(['status' => 'completed']);
    $vehicle->update(['readiness_status' => 'maintenance']);
    expect($vehicle->fresh()->canReturnToReady())->toBeFalse();
});

it('returns a cleaned available vehicle to ready after its final sanitation record completes', function () {
    $user = priority3User('fleet_manager');
    $vehicle = priority3Vehicle(['readiness_status' => 'cleaning']);
    $sanitation = SanitationLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'reason' => 'routine_end_of_trip',
        'cleaning_type' => 'routine_cleaning',
        'status' => 'in_progress',
    ]);

    $this->actingAs($user)->putJson(route('sanitation.update', $sanitation), [
        'status' => 'completed',
    ])->assertOk();

    expect($vehicle->fresh()->readiness_status)->toBe('ready');
});

it('syncs capability pivots and reports capability gaps', function () {
    $vehicle = priority3Vehicle();
    $reservation = priority3Dispatch($vehicle, priority3Driver())->reservation;
    $stretcher = VehicleCapability::query()->create(['name' => 'Stretcher Equipped', 'slug' => 'stretcher_equipped']);
    $oxygen = VehicleCapability::query()->create(['name' => 'Oxygen Equipment', 'slug' => 'oxygen_equipment_available']);

    $vehicle->capabilities()->sync([$stretcher->id]);
    $reservation->requiredCapabilities()->sync([$stretcher->id, $oxygen->id]);
    $advisory = $vehicle->fresh()->capabilityAdvisory($reservation->requiredCapabilities()->get());

    expect($vehicle->capabilities()->count())->toBe(1)
        ->and($reservation->requiredCapabilities()->count())->toBe(2)
        ->and($advisory['state'])->toBe('warning')
        ->and($advisory['missing'])->toContain('Oxygen Equipment');
});

it('enforces unique qualifications and reports driver advisories', function () {
    $driver = priority3Driver();
    DriverVehicleQualification::query()->create(['driver_id' => $driver->id, 'vehicle_type' => 'Ambulance']);
    expect(fn () => DriverVehicleQualification::query()->create([
        'driver_id' => $driver->id,
        'vehicle_type' => 'Ambulance',
    ]))->toThrow(QueryException::class);

    expect($driver->fresh()->qualificationAdvisory('Ambulance')['state'])->toBe('suitable')
        ->and($driver->fresh()->qualificationAdvisory('SUV')['state'])->toBe('warning');
});

it('warns for expired licenses and treats missing qualification history neutrally', function () {
    $expired = priority3Driver(['license_expiry' => now()->subDay()->toDateString()]);
    expect($expired->qualificationAdvisory('Ambulance')['label'])->toBe('License Expired');

    $legacy = priority3Driver(['license_expiry' => null]);
    $advisory = $legacy->qualificationAdvisory('Ambulance');
    expect($advisory['state'])->toBe('neutral')
        ->and($advisory['label'])->toBe('Qualification Information Unavailable')
        ->and($legacy->license_status)->toBe('Not Specified');
});

it('keeps legacy records safe and preserves Priority 1 and Priority 2 behavior', function () {
    $vehicle = new Vehicle([
        'status' => 'Available',
        'readiness_status' => null,
        'powertrain_type' => 'hybrid',
    ]);
    $reservation = new Reservation(['priority' => 'Emergency']);

    expect($vehicle->capabilityAdvisory(collect())['state'])->toBe('neutral')
        ->and($vehicle->supportsLiquidFuel())->toBeTrue()
        ->and($vehicle->supportsExternalCharging())->toBeFalse()
        ->and($vehicle->isOperationallyReady())->toBeTrue()
        ->and($reservation->priorityRank())->toBe(1);
});
