<?php

use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FuelEfficiencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['app.timezone' => 'Asia/Manila']);
    $this->travelTo(CarbonImmutable::parse('2026-11-08 02:00:00', 'Asia/Manila'));
});

function comparisonDriver(?User $user = null): Driver
{
    return Driver::query()->create([
        'user_id' => $user?->id,
        'first_name' => 'Comparison', 'last_name' => 'Test',
        'license_number' => fake()->unique()->bothify('CMP-########'),
        'license_class' => 'Professional', 'license_expiry' => '2099-01-01',
        'contact_number' => '09170000000', 'status' => 'Available',
    ]);
}

function comparisonVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('CMP-######'),
        'vehicle_type' => 'Service Vehicle', 'brand' => 'Synthetic', 'model' => 'Comparison',
        'capacity' => 4, 'powertrain_type' => 'gasoline', 'fuel_type' => 'Gasoline',
        'status' => 'Available', 'readiness_status' => 'ready',
    ], $attributes));
}

function comparisonLog(Vehicle $vehicle, Driver $driver, string $date, ?float $odometer, array $attributes = []): FuelLog
{
    return FuelLog::query()->create(array_merge([
        'fuel_number' => fake()->unique()->bothify('CMP-FUEL-########'),
        'vehicle_id' => $vehicle->id, 'driver_id' => $driver->id,
        'date' => $date, 'refuel_time' => '12:00:00',
        'odometer' => $odometer, 'fuel_amount' => 10,
        'cost' => 100, 'cost_per_liter' => 10, 'fuel_type' => 'Gasoline',
    ], $attributes));
}

it('adds the comparison without changing legacy responses for authorized fleet viewers', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $vehicle = comparisonVehicle();
    $driver = comparisonDriver();
    comparisonLog($vehicle, $driver, '2026-08-01', 0);
    comparisonLog($vehicle, $driver, '2026-09-15', 100);
    comparisonLog($vehicle, $driver, '2026-10-15', 300, ['cost' => 150]);
    $legacy = app(FuelEfficiencyService::class)->analyze(FuelLog::query()->get());

    $response = $this->actingAs($user)->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonCount(3, 'fuelLogs')
        ->assertJsonPath('efficiencySummary', json_decode(json_encode($legacy['summary'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))
        ->assertJsonStructure(['settings' => ['high_cost_alert']])
        ->assertJsonPath('periodComparison.scope', 'fleet')
        ->assertJsonPath('periodComparison.previous.estimated_efficiency_km_per_liter', 10)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', 20)
        ->assertJsonPath('periodComparison.changes.fuel_cost_percent', 50)
        ->assertJsonPath('periodComparison.changes.estimated_efficiency_percent', 100);

    expect(array_keys($response->json('periodComparison')))->toBe([
        'timezone', 'as_of_date', 'scope', 'previous', 'current', 'changes', 'advisory',
    ]);
})->with(['fleet_manager', 'dispatcher', 'finance', 'maintenance', 'it_admin']);

it('denies department heads and unauthenticated clients', function () {
    $this->getJson(route('fuel.index'))->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => 'department_head']))
        ->getJson(route('fuel.index'))->assertForbidden();
});

it('fails closed for drivers without profiles despite supplied scope parameters', function () {
    $vehicle = comparisonVehicle();
    $driver = comparisonDriver();
    comparisonLog($vehicle, $driver, '2026-10-15', 100);
    $this->actingAs(User::factory()->create(['role' => 'driver']))
        ->getJson(route('fuel.index', ['driver_id' => $driver->id, 'scope' => 'fleet']))
        ->assertSuccessful()
        ->assertJsonCount(0, 'fuelLogs')
        ->assertJsonPath('periodComparison.scope', 'own_records')
        ->assertJsonPath('periodComparison.current.record_count', 0)
        ->assertJsonPath('periodComparison.current.fuel_cost', 0)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', null);
});

it('compares only owned transactions with authorized pre-window anchors', function () {
    $user = User::factory()->create(['role' => 'driver']);
    $driver = comparisonDriver($user);
    $vehicle = comparisonVehicle();
    comparisonLog($vehicle, $driver, '2026-08-01', 0);
    comparisonLog($vehicle, $driver, '2026-09-15', 100);
    comparisonLog($vehicle, $driver, '2026-10-15', 300);
    comparisonLog(comparisonVehicle(), comparisonDriver(), '2026-10-16', 9000, ['cost' => 9999]);

    $this->actingAs($user)->getJson(route('fuel.index', ['driver_id' => 999, 'vehicle_id' => 999, 'scope' => 'fleet']))
        ->assertSuccessful()
        ->assertJsonCount(3, 'fuelLogs')
        ->assertJsonPath('periodComparison.scope', 'own_records')
        ->assertJsonPath('periodComparison.previous.fuel_cost', 100)
        ->assertJsonPath('periodComparison.current.fuel_cost', 100)
        ->assertJsonPath('periodComparison.current.valid_distance_km', 200)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', 20);
});

it('rejects a hidden anchor for the comparison while preserving the legacy derived response', function () {
    $user = User::factory()->create(['role' => 'driver']);
    $own = comparisonDriver($user);
    $vehicle = comparisonVehicle();
    $foreign = comparisonLog($vehicle, comparisonDriver(), '2026-09-01', 100, ['cost' => 9999]);
    comparisonLog($vehicle, $own, '2026-10-15', 200);

    $response = $this->actingAs($user)->getJson(route('fuel.index'))->assertSuccessful()
        ->assertJsonCount(1, 'fuelLogs')
        ->assertJsonPath('fuelLogs.0.estimated_fuel_efficiency_km_per_liter', 10)
        ->assertJsonPath('periodComparison.current.fuel_cost', 100)
        ->assertJsonPath('periodComparison.current.valid_interval_count', 0)
        ->assertJsonPath('periodComparison.current.valid_distance_km', 0)
        ->assertJsonPath('periodComparison.current.valid_liters', 0)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', null);

    $before = $response->json('periodComparison');
    $foreign->update(['odometer' => 150, 'cost' => 5000, 'fuel_amount' => 50]);
    expect($this->getJson(route('fuel.index'))->json('periodComparison'))->toBe($before);
    expect(json_encode($before))->not->toContain('driver_id', 'vehicle_id', '9999', 'provenance');
});

it('does not bridge intervening foreign transactions even when their readings are invalid', function (?float $foreignOdometer, float $foreignLiters) {
    $user = User::factory()->create(['role' => 'driver']);
    $own = comparisonDriver($user);
    $vehicle = comparisonVehicle();
    comparisonLog($vehicle, $own, '2026-08-01', 100);
    comparisonLog($vehicle, comparisonDriver(), '2026-09-15', $foreignOdometer, ['fuel_amount' => $foreignLiters]);
    comparisonLog($vehicle, $own, '2026-10-15', 300);

    $this->actingAs($user)->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('periodComparison.current.valid_interval_count', 0)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', null);

    comparisonLog($vehicle, $own, '2026-10-16', 400);
    $this->getJson(route('fuel.index'))->assertSuccessful()
        ->assertJsonPath('periodComparison.current.record_count', 2)
        ->assertJsonPath('periodComparison.current.valid_interval_count', 1)
        ->assertJsonPath('periodComparison.current.valid_distance_km', 100)
        ->assertJsonPath('periodComparison.current.valid_liters', 10);
})->with([[200, 10], [null, 10], [50, 10], [200, 0]]);

it('preserves accepted old anchors across invalid readings and later rollback recovery', function () {
    $vehicle = comparisonVehicle(['archived_at' => now()]);
    $driver = comparisonDriver();
    comparisonLog($vehicle, $driver, '2026-08-01', 100);
    comparisonLog($vehicle, $driver, '2026-09-01', 50);
    comparisonLog($vehicle, $driver, '2026-09-02', null);
    comparisonLog($vehicle, $driver, '2026-09-03', 500, ['fuel_amount' => 0]);
    comparisonLog($vehicle, $driver, '2026-09-15', 200);
    comparisonLog($vehicle, $driver, '2026-10-15', 150);
    comparisonLog($vehicle, $driver, '2026-10-16', 200);
    comparisonLog($vehicle, $driver, '2026-10-17', 300);

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('periodComparison.previous.valid_distance_km', 100)
        ->assertJsonPath('periodComparison.current.valid_distance_km', 100)
        ->assertJsonPath('periodComparison.current.valid_interval_count', 1)
        ->assertJsonPath('periodComparison.current.fuel_cost', 300);
});

it('preserves actual-time ordering and whole-day legacy missing-time fallback', function (bool $missingTime) {
    $driver = comparisonDriver();
    $vehicle = comparisonVehicle();
    comparisonLog($vehicle, $driver, '2026-10-15', $missingTime ? 100 : 200, [
        'refuel_time' => '16:00:00', 'created_at' => '2026-10-15 18:00:00',
    ]);
    comparisonLog($vehicle, $driver, '2026-10-15', $missingTime ? 200 : 100, [
        'refuel_time' => $missingTime ? null : '14:00:00', 'created_at' => '2026-10-15 19:00:00',
    ]);
    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('periodComparison.current.valid_interval_count', 1)
        ->assertJsonPath('periodComparison.current.valid_distance_km', 100)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', 10);
})->with([false, true]);

it('keeps electric charging out of liquid-fuel comparisons', function (string $powertrain, ?int $expected) {
    $vehicle = comparisonVehicle(['powertrain_type' => $powertrain, 'fuel_type' => $powertrain === 'electric' ? 'Electric' : 'Gasoline']);
    $driver = comparisonDriver();
    comparisonLog($vehicle, $driver, '2026-09-01', 100);
    comparisonLog($vehicle, $driver, '2026-10-15', 200);
    DB::table('charging_logs')->insert([
        'vehicle_id' => $vehicle->id, 'charging_date' => '2026-10-15',
        'battery_before_percent' => 20, 'battery_after_percent' => 80,
        'energy_added_kwh' => 100, 'charging_cost' => 9999, 'charging_location' => 'Synthetic',
    ]);
    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('periodComparison.current.fuel_cost', 100)
        ->assertJsonPath('periodComparison.current.estimated_efficiency_km_per_liter', $expected);
})->with([['electric', null], ['hybrid', 10], ['plug_in_hybrid', 10]]);

it('uses the application calendar date even when the UTC date differs', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 18:00:00', 'UTC'));
    $vehicle = comparisonVehicle();
    $driver = comparisonDriver();
    foreach (['2026-09-08', '2026-09-09', '2026-10-08', '2026-10-09', '2026-11-07', '2026-11-08', '2026-11-09'] as $day) {
        comparisonLog($vehicle, $driver, $day, null);
    }
    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))->getJson(route('fuel.index'))
        ->assertSuccessful()
        ->assertJsonPath('periodComparison.as_of_date', '2026-11-08')
        ->assertJsonPath('periodComparison.timezone', 'Asia/Manila')
        ->assertJsonPath('periodComparison.previous.record_count', 2)
        ->assertJsonPath('periodComparison.current.record_count', 2);
});

it('performs no persistent writes and uses one batched history query for both periods', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = comparisonVehicle();
    $driver = comparisonDriver();
    comparisonLog($vehicle, $driver, '2026-08-01', 100);
    comparisonLog($vehicle, $driver, '2026-09-15', 200);
    comparisonLog($vehicle, $driver, '2026-10-15', 300);
    $before = FuelLog::query()->get()->map->getRawOriginal()->all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($user)->getJson(route('fuel.index'))->assertSuccessful();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn ($sql) => preg_match('/^\s*(insert|update|delete|alter|drop|create|replace)\b/i', $sql)))->toHaveCount(0)
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'from "fuel_logs"') && str_contains($sql, '"vehicle_id" in')))->toHaveCount(1)
        ->and(FuelLog::query()->get()->map->getRawOriginal()->all())->toBe($before);
});

it('renders a comparison section with explicit loading and methodology text', function () {
    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))->get(route('fuel'))
        ->assertSuccessful()
        ->assertSee('Fuel Increase Monitoring')
        ->assertSee('Loading comparison')
        ->assertSee('ending fuel transaction');
});
