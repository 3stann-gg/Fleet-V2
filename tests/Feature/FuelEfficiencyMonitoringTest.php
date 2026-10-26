<?php

use App\Models\ChargingLog;
use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelEfficiencyService;
use Illuminate\Support\Collection;

function fuelEfficiencyVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('FE-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Logistics',
        'brand' => 'Test',
        'model' => 'Vehicle',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 100,
        'current_fuel' => 10,
        'current_odometer' => 0,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function fuelEfficiencyDriver(): Driver
{
    return Driver::query()->create([
        'first_name' => 'Fuel',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('FE-LIC-####'),
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'status' => 'Available',
    ]);
}

function fuelEfficiencyLog(Vehicle $vehicle, float $liters, ?float $odometer, array $attributes = []): FuelLog
{
    static $sequence = 0;
    $sequence++;

    return FuelLog::query()->create(array_merge([
        'fuel_number' => sprintf('FE-FUEL-%04d-%04d', $vehicle->id, $sequence),
        'vehicle_id' => $vehicle->id,
        'driver_id' => fuelEfficiencyDriver()->id,
        'fuel_amount' => $liters,
        'cost_per_liter' => 2,
        'cost' => $liters * 2,
        'odometer' => $odometer,
        'date' => now()->toDateString(),
        'refuel_time' => sprintf('%02d:00:00', min($sequence, 23)),
        'fuel_type' => 'Gasoline',
    ], $attributes));
}

/** @param Collection<int, FuelLog> $logs */
function fuelEfficiencyAnalysis(Collection $logs): array
{
    return app(FuelEfficiencyService::class)->analyze($logs);
}

function analyzedFuelLog(FuelLog $fuelLog): FuelLog
{
    return fuelEfficiencyAnalysis(collect([$fuelLog]))['fuelLogs']->first();
}

it('represents the first Fuel Log as insufficient data instead of zero efficiency', function () {
    $first = fuelEfficiencyLog(fuelEfficiencyVehicle(), 15, 12000);
    $analyzed = analyzedFuelLog($first);

    expect($analyzed->distance_since_previous_fuel_log_km)->toBeNull()
        ->and($analyzed->estimated_fuel_efficiency_km_per_liter)->toBeNull()
        ->and($analyzed->fuel_efficiency_status)->toBe('Insufficient Data');
});

it('calculates distance and estimated efficiency from consecutive same-vehicle Fuel Logs', function () {
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 20, 12000);
    $current = fuelEfficiencyLog($vehicle, 15, 12150);
    $analyzed = analyzedFuelLog($current);

    expect($analyzed->distance_since_previous_fuel_log_km)->toBe(150.0)
        ->and($analyzed->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('returns N/A for zero liters without dividing by zero', function () {
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, 100);
    $current = fuelEfficiencyLog($vehicle, 0, 200);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBeNull();
});

it('returns N/A when the current odometer is missing', function () {
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, 100);
    $current = fuelEfficiencyLog($vehicle, 10, null);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBeNull();
});

it('returns N/A for historical odometer rollback data', function () {
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, 200);
    $current = fuelEfficiencyLog($vehicle, 10, 150);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBeNull()
        ->and(analyzedFuelLog($current)->fuel_efficiency_reason)->toContain('lower');
});

it('never uses a previous Fuel Log from another vehicle', function () {
    fuelEfficiencyLog(fuelEfficiencyVehicle(), 10, 100);
    $firstForVehicleB = fuelEfficiencyLog(fuelEfficiencyVehicle(), 10, 200);

    expect(analyzedFuelLog($firstForVehicleB)->estimated_fuel_efficiency_km_per_liter)->toBeNull();
});

it('keeps multiple vehicle histories isolated', function () {
    $vehicleA = fuelEfficiencyVehicle();
    $vehicleB = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicleA, 10, 100);
    fuelEfficiencyLog($vehicleB, 10, 1000);
    $currentA = fuelEfficiencyLog($vehicleA, 10, 200);
    $currentB = fuelEfficiencyLog($vehicleB, 20, 1200);

    expect(analyzedFuelLog($currentA)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0)
        ->and(analyzedFuelLog($currentB)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('handles incomplete historical records without crashing', function () {
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, null);
    fuelEfficiencyLog($vehicle, 0, 50);
    $current = fuelEfficiencyLog($vehicle, 10, 100);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBeNull();
});

it('includes Fuel Logs for archived vehicles as protected historical records', function () {
    $vehicle = fuelEfficiencyVehicle(['archived_at' => now()]);
    fuelEfficiencyLog($vehicle, 10, 100);
    $current = fuelEfficiencyLog($vehicle, 10, 200);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('summarizes total liters recorded', function () {
    $vehicle = fuelEfficiencyVehicle();
    $logs = collect([
        fuelEfficiencyLog($vehicle, 10, 100),
        fuelEfficiencyLog($vehicle, 20, 300),
    ]);

    expect(fuelEfficiencyAnalysis($logs)['summary']['total_liters_recorded'])->toBe(30.0);
});

it('summarizes total Fuel Log cost', function () {
    $vehicle = fuelEfficiencyVehicle();
    $logs = collect([
        fuelEfficiencyLog($vehicle, 10, 100, ['cost' => 100]),
        fuelEfficiencyLog($vehicle, 20, 300, ['cost' => 300]),
    ]);

    expect(fuelEfficiencyAnalysis($logs)['summary']['total_fuel_cost'])->toBe(400.0);
});

it('reports the latest valid estimated km per liter', function () {
    $vehicle = fuelEfficiencyVehicle();
    $logs = collect([
        fuelEfficiencyLog($vehicle, 10, 100),
        fuelEfficiencyLog($vehicle, 10, 200),
        fuelEfficiencyLog($vehicle, 10, 350),
    ]);

    expect(fuelEfficiencyAnalysis($logs)['summary']['latest_valid_estimated_efficiency_km_per_liter'])->toBe(15.0);
});

it('uses weighted valid distance divided by valid interval liters for aggregate efficiency', function () {
    $vehicle = fuelEfficiencyVehicle();
    $logs = collect([
        fuelEfficiencyLog($vehicle, 5, 0),
        fuelEfficiencyLog($vehicle, 10, 100),
        fuelEfficiencyLog($vehicle, 20, 400),
    ]);
    $summary = fuelEfficiencyAnalysis($logs)['summary'];

    expect($summary['aggregate_estimated_efficiency_km_per_liter'])->toBe(13.33)
        ->and($summary['total_valid_interval_distance_km'])->toBe(400.0)
        ->and($summary['liters_in_valid_intervals'])->toBe(30.0);
});

it('excludes invalid observations from aggregate efficiency', function () {
    $vehicle = fuelEfficiencyVehicle();
    $logs = collect([
        fuelEfficiencyLog($vehicle, 10, 100),
        fuelEfficiencyLog($vehicle, 10, 200),
        fuelEfficiencyLog($vehicle, 0, 300),
        fuelEfficiencyLog($vehicle, 20, 400),
    ]);
    $summary = fuelEfficiencyAnalysis($logs)['summary'];

    expect($summary['valid_efficiency_interval_count'])->toBe(2)
        ->and($summary['aggregate_estimated_efficiency_km_per_liter'])->toBe(10.0);
});

it('does not fabricate aggregate efficiency from only one Fuel Log', function () {
    $summary = fuelEfficiencyAnalysis(collect([
        fuelEfficiencyLog(fuelEfficiencyVehicle(), 10, 100),
    ]))['summary'];

    expect($summary['aggregate_estimated_efficiency_km_per_liter'])->toBeNull()
        ->and($summary['valid_efficiency_interval_count'])->toBe(0);
});

it('returns a safe empty summary when there are no Fuel Logs', function () {
    $summary = fuelEfficiencyAnalysis(collect())['summary'];

    expect($summary['total_liters_recorded'])->toBe(0.0)
        ->and($summary['total_fuel_cost'])->toBe(0.0)
        ->and($summary['aggregate_estimated_efficiency_km_per_liter'])->toBeNull();
});

it('calculates estimated km per liter for an ICE vehicle', function () {
    $vehicle = fuelEfficiencyVehicle(['powertrain_type' => 'diesel', 'fuel_type' => 'Diesel']);
    fuelEfficiencyLog($vehicle, 10, 100, ['fuel_type' => 'Diesel']);
    $current = fuelEfficiencyLog($vehicle, 10, 200, ['fuel_type' => 'Diesel']);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('never calculates fake km per liter for a pure EV', function () {
    $vehicle = fuelEfficiencyVehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
    ]);
    fuelEfficiencyLog($vehicle, 10, 100);
    $current = fuelEfficiencyLog($vehicle, 10, 200);
    $analyzed = analyzedFuelLog($current);

    expect($analyzed->estimated_fuel_efficiency_km_per_liter)->toBeNull()
        ->and($analyzed->fuel_efficiency_status)->toBe('Not Applicable');
});

it('calculates the liquid-fuel side of a Hybrid independently', function () {
    $vehicle = fuelEfficiencyVehicle(['powertrain_type' => 'hybrid']);
    fuelEfficiencyLog($vehicle, 10, 100);
    $current = fuelEfficiencyLog($vehicle, 20, 300);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('does not include charging kilowatt-hours in fuel-liter calculations', function () {
    $vehicle = fuelEfficiencyVehicle(['powertrain_type' => 'plug_in_hybrid']);
    fuelEfficiencyLog($vehicle, 10, 100);
    $current = fuelEfficiencyLog($vehicle, 10, 200);
    ChargingLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'charging_date' => now()->toDateString(),
        'battery_before_percent' => 20,
        'battery_after_percent' => 80,
        'energy_added_kwh' => 100,
        'charging_location' => 'Hospital',
        'charging_cost' => 100,
    ]);

    expect(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('exposes interval distance and estimated efficiency through the Fuel index API', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, 12000);
    $current = fuelEfficiencyLog($vehicle, 15, 12150);

    $this->actingAs($user)->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('fuelLogs.0.id', $current->id)
        ->assertJsonPath('fuelLogs.0.distance_since_previous_fuel_log_km', 150)
        ->assertJsonPath('fuelLogs.0.estimated_fuel_efficiency_km_per_liter', 10);
});

it('represents N/A efficiency as null in the API contract', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    fuelEfficiencyLog(fuelEfficiencyVehicle(), 10, 100);

    $this->actingAs($user)->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('fuelLogs.0.estimated_fuel_efficiency_km_per_liter', null)
        ->assertJsonPath('efficiencySummary.aggregate_estimated_efficiency_km_per_liter', null);
});

it('keeps Fuel Log creation behavior and returns dynamic efficiency fields', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = fuelEfficiencyVehicle(['current_odometer' => 100]);
    $driver = fuelEfficiencyDriver();
    fuelEfficiencyLog($vehicle, 10, 100);

    $this->actingAs($user)->postJson(route('fuel.store'), [
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'fuel_amount' => 10,
        'cost_per_liter' => 2,
        'odometer' => 200,
        'date' => now()->toDateString(),
        'refuel_time' => '23:30',
        'fuel_type' => 'Gasoline',
    ])->assertCreated()
        ->assertJsonPath('fuelLog.distance_since_previous_fuel_log_km', 100)
        ->assertJsonPath('fuelLog.estimated_fuel_efficiency_km_per_liter', 10);
});

it('keeps Fuel Log editing behavior and recalculates ordering-sensitive metrics', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, 100, ['date' => now()->subDay()->toDateString()]);
    $current = fuelEfficiencyLog($vehicle, 10, 200);

    $this->actingAs($user)->putJson(route('fuel.update', $current), [
        'date' => now()->toDateString(),
        'refuel_time' => '22:00',
        'cost_per_liter' => 3,
        'fuel_station' => null,
        'receipt_number' => 'EDITED',
        'payment_method' => 'Cash',
        'notes' => 'Updated transaction details',
    ])->assertSuccessful()
        ->assertJsonPath('fuelLog.cost', '30.00')
        ->assertJsonPath('fuelLog.estimated_fuel_efficiency_km_per_liter', 10);

    expect($current->fresh()->odometer)->toBe('200.00');
});

it('keeps the existing client-side pagination API shape intact', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 10, 100);
    fuelEfficiencyLog($vehicle, 10, 200);

    $this->actingAs($user)->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonCount(2, 'fuelLogs')
        ->assertJsonStructure([
            'fuelLogs' => [['id', 'vehicle_id', 'fuel_amount']],
            'efficiencySummary',
            'settings',
        ]);
});

it('uses direct valid-observation comparison for trend and keeps advisories informational', function () {
    $vehicle = fuelEfficiencyVehicle();
    $logs = collect([
        fuelEfficiencyLog($vehicle, 10, 100),
        fuelEfficiencyLog($vehicle, 10, 200),
        fuelEfficiencyLog($vehicle, 20, 300),
    ]);
    $analyzed = fuelEfficiencyAnalysis($logs)['fuelLogs']->last();

    expect($analyzed->fuel_efficiency_trend)->toBe('Declining')
        ->and($analyzed->fuel_efficiency_advisory)->toContain('Review vehicle, route, load, traffic, and maintenance conditions')
        ->and($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready');
});

it('orders same-day Fuel Logs by actual refueling time', function () {
    $vehicle = fuelEfficiencyVehicle();
    fuelEfficiencyLog($vehicle, 5, 12000, [
        'date' => '2026-10-26',
        'refuel_time' => '15:08:00',
    ]);
    $current = fuelEfficiencyLog($vehicle, 5, 12050, [
        'date' => '2026-10-26',
        'refuel_time' => '16:08:00',
    ]);

    expect(analyzedFuelLog($current)->distance_since_previous_fuel_log_km)->toBe(50.0)
        ->and(analyzedFuelLog($current)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('uses stable creation order for a same-day history containing a null refueling time', function () {
    $vehicle = fuelEfficiencyVehicle();
    $first = fuelEfficiencyLog($vehicle, 5, 12000, [
        'date' => '2026-10-26',
        'refuel_time' => '15:08:00',
    ]);
    $historicalNullTime = fuelEfficiencyLog($vehicle, 5, 12050, [
        'date' => '2026-10-26',
        'refuel_time' => null,
    ]);
    $analysis = fuelEfficiencyAnalysis(collect([$first, $historicalNullTime]))['fuelLogs'];

    expect($analysis->firstWhere('id', $first->id)->fuel_efficiency_reason)
        ->toBe('No previous valid same-vehicle fuel log.')
        ->and($analysis->firstWhere('id', $historicalNullTime->id)->distance_since_previous_fuel_log_km)
        ->toBe(50.0)
        ->and($analysis->firstWhere('id', $historicalNullTime->id)->estimated_fuel_efficiency_km_per_liter)
        ->toBe(10.0);
});

it('orders multiple same-day Fuel Logs chronologically even when inserted out of order', function () {
    $vehicle = fuelEfficiencyVehicle();
    $last = fuelEfficiencyLog($vehicle, 5, 12100, [
        'date' => '2026-10-26',
        'refuel_time' => '17:00:00',
    ]);
    $first = fuelEfficiencyLog($vehicle, 5, 12000, [
        'date' => '2026-10-26',
        'refuel_time' => '15:00:00',
    ]);
    $middle = fuelEfficiencyLog($vehicle, 5, 12050, [
        'date' => '2026-10-26',
        'refuel_time' => '16:00:00',
    ]);
    $analysis = fuelEfficiencyAnalysis(collect([$last, $first, $middle]))['fuelLogs'];

    expect($analysis->firstWhere('id', $first->id)->estimated_fuel_efficiency_km_per_liter)->toBeNull()
        ->and($analysis->firstWhere('id', $middle->id)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0)
        ->and($analysis->firstWhere('id', $last->id)->estimated_fuel_efficiency_km_per_liter)->toBe(10.0);
});

it('uses record ID as the stable tie-break for equal date and refueling time', function () {
    $vehicle = fuelEfficiencyVehicle();
    $first = fuelEfficiencyLog($vehicle, 5, 12000, [
        'date' => '2026-10-26',
        'refuel_time' => '15:08:00',
    ]);
    $second = fuelEfficiencyLog($vehicle, 5, 12050, [
        'date' => '2026-10-26',
        'refuel_time' => '15:08:00',
    ]);

    expect($first->id)->toBeLessThan($second->id)
        ->and(analyzedFuelLog($second)->distance_since_previous_fuel_log_km)->toBe(50.0);
});

it('keeps a historical null-time Fuel Log readable without calculation errors', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $historical = fuelEfficiencyLog(fuelEfficiencyVehicle(), 5, 12000, [
        'date' => '2026-10-26',
        'refuel_time' => null,
    ]);

    $this->actingAs($user)->getJson(route('fuel.show', $historical))
        ->assertSuccessful()
        ->assertJsonPath('fuelLog.id', $historical->id)
        ->assertJsonPath('fuelLog.refuel_time', null)
        ->assertJsonPath('fuelLog.estimated_fuel_efficiency_km_per_liter', null);
});

it('requires refueling time when creating a new Fuel Log', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = fuelEfficiencyVehicle();
    $driver = fuelEfficiencyDriver();

    $this->actingAs($user)->postJson(route('fuel.store'), [
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'fuel_amount' => 5,
        'cost_per_liter' => 2,
        'odometer' => 100,
        'date' => '2026-10-26',
        'fuel_type' => 'Gasoline',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('refuel_time');
});

it('accepts a valid refueling time for a new Fuel Log', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = fuelEfficiencyVehicle();
    $driver = fuelEfficiencyDriver();

    $this->actingAs($user)->postJson(route('fuel.store'), [
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'fuel_amount' => 5,
        'cost_per_liter' => 2,
        'odometer' => 100,
        'date' => '2026-10-26',
        'refuel_time' => '15:08',
        'fuel_type' => 'Gasoline',
    ])->assertCreated()
        ->assertJsonPath('fuelLog.refuel_time', '15:08');
});

it('requires refueling time when editing a Fuel Log', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $fuelLog = fuelEfficiencyLog(fuelEfficiencyVehicle(), 5, 100);

    $this->actingAs($user)->putJson(route('fuel.update', $fuelLog), [
        'date' => '2026-10-26',
        'cost_per_liter' => 2,
        'fuel_station' => null,
        'receipt_number' => null,
        'payment_method' => null,
        'notes' => null,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('refuel_time');
});
