<?php

use App\Models\ChargingLog;
use App\Models\AuditLog;
use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\FuelLog;
use App\Models\Maintenance;
use App\Models\PreTripChecklist;
use App\Models\Reservation;
use App\Models\TripLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\EnergyAnalyticsService;
use App\Services\VehicleOperationalHealthService;

function priority4Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P4-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Logistics',
        'brand' => 'Test',
        'model' => 'Vehicle',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 25,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function priority4Driver(array $attributes = []): Driver
{
    return Driver::query()->create(array_merge([
        'first_name' => 'Priority',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P4-LIC-####'),
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'status' => 'Available',
    ], $attributes));
}

function priority4Trip(Vehicle $vehicle, float $distance): TripLog
{
    $reservation = Reservation::query()->create([
        'reservation_number' => fake()->unique()->bothify('P4-RSV-####'),
        'patient_name' => 'Analytics Record',
        'request_type' => 'Administrative Trip',
        'vehicle_id' => $vehicle->id,
        'pickup_location' => 'Hospital',
        'destination' => 'Clinic',
        'schedule_date' => now()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Completed',
    ]);
    $dispatch = Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('P4-DSP-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => 'Completed',
    ]);

    return TripLog::query()->create([
        'dispatch_id' => $dispatch->id,
        'start_odometer' => 100,
        'end_odometer' => 100 + $distance,
        'distance' => $distance,
        'fuel_used' => 0,
        'submitted_at' => now(),
    ]);
}

function priority4Fuel(Vehicle $vehicle, float $liters, float $cost): FuelLog
{
    $driver = priority4Driver();

    return FuelLog::query()->create([
        'fuel_number' => fake()->unique()->bothify('P4-FUEL-####'),
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'fuel_amount' => $liters,
        'cost_per_liter' => $cost / $liters,
        'cost' => $cost,
        'odometer' => $vehicle->current_odometer,
        'date' => now()->toDateString(),
        'fuel_type' => 'Gasoline',
    ]);
}

function priority4Charge(Vehicle $vehicle, float $kilowattHours, float $cost): ChargingLog
{
    return ChargingLog::query()->create([
        'vehicle_id' => $vehicle->id,
        'charging_date' => now()->toDateString(),
        'battery_before_percent' => 20,
        'battery_after_percent' => 80,
        'energy_added_kwh' => $kilowattHours,
        'charging_location' => 'Hospital Motor Pool',
        'charging_cost' => $cost,
    ]);
}

function priority4Analytics(): EnergyAnalyticsService
{
    return app(EnergyAnalyticsService::class);
}

function priority4Filters(): array
{
    return [
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => now()->addDay()->toDateString(),
    ];
}

it('calculates liquid-fuel distance, liters, efficiency, cost, and cost per kilometer', function () {
    $vehicle = priority4Vehicle();
    priority4Trip($vehicle, 100);
    priority4Fuel($vehicle, 20, 200);

    $result = priority4Analytics()->analyze(priority4Filters());
    $row = $result['vehicles'][0];

    expect($row['distance_km'])->toBe(100.0)
        ->and($row['fuel_liters'])->toBe(20.0)
        ->and($row['fuel_efficiency_km_per_liter'])->toBe(5.0)
        ->and($row['fuel_cost'])->toBe(200.0)
        ->and($row['cost_per_km'])->toBe(2.0)
        ->and($result['sources']['fuel'])->toBe('Fuel Transaction');
});

it('calculates electric distance, kWh, efficiency, charging cost, and cost per kilometer', function () {
    $vehicle = priority4Vehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'battery_capacity_kwh' => 60,
        'battery_level_percent' => 70,
    ]);
    priority4Trip($vehicle, 120);
    priority4Charge($vehicle, 30, 300);

    $row = priority4Analytics()->analyze(priority4Filters())['vehicles'][0];

    expect($row['distance_km'])->toBe(120.0)
        ->and($row['electricity_kwh'])->toBe(30.0)
        ->and($row['electric_efficiency_km_per_kwh'])->toBe(4.0)
        ->and($row['charging_cost'])->toBe(300.0)
        ->and($row['cost_per_km'])->toBe(2.5)
        ->and($row['fuel_liters'])->toBe(0.0);
});

it('keeps PHEV liters and kWh separate while combining only monetary cost', function () {
    $vehicle = priority4Vehicle([
        'powertrain_type' => 'plug_in_hybrid',
        'battery_capacity_kwh' => 20,
        'battery_level_percent' => 70,
    ]);
    priority4Trip($vehicle, 100);
    priority4Fuel($vehicle, 10, 100);
    priority4Charge($vehicle, 20, 200);

    $row = priority4Analytics()->analyze(priority4Filters())['vehicles'][0];

    expect($row['fuel_liters'])->toBe(10.0)
        ->and($row['electricity_kwh'])->toBe(20.0)
        ->and($row['fuel_efficiency_km_per_liter'])->toBe(10.0)
        ->and($row['electric_efficiency_km_per_kwh'])->toBe(5.0)
        ->and($row['total_energy_cost'])->toBe(300.0)
        ->and($row['cost_per_km'])->toBe(3.0);
});

it('returns insufficient data instead of fabricated divide-by-zero metrics', function () {
    priority4Vehicle();

    $row = priority4Analytics()->analyze(priority4Filters())['vehicles'][0];

    expect($row['fuel_efficiency_km_per_liter'])->toBeNull()
        ->and($row['electric_efficiency_km_per_kwh'])->toBeNull()
        ->and($row['cost_per_km'])->toBeNull()
        ->and($row['data_status'])->toBe('Insufficient Data');
});

it('uses the correct analytics path for gasoline and electric motorcycles', function () {
    $gasoline = priority4Vehicle(['vehicle_type' => 'Motorcycle']);
    $electric = priority4Vehicle([
        'plate_number' => 'P4-E-MOTO',
        'vehicle_type' => 'Motorcycle',
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
    ]);
    priority4Trip($gasoline, 50);
    priority4Fuel($gasoline, 5, 50);
    priority4Trip($electric, 60);
    priority4Charge($electric, 10, 80);

    $rows = collect(priority4Analytics()->analyze(priority4Filters())['vehicles'])->keyBy('vehicle_id');

    expect($rows[$gasoline->id]['fuel_efficiency_km_per_liter'])->toBe(10.0)
        ->and($rows[$gasoline->id]['electric_efficiency_km_per_kwh'])->toBeNull()
        ->and($rows[$electric->id]['electric_efficiency_km_per_kwh'])->toBe(6.0)
        ->and($rows[$electric->id]['fuel_efficiency_km_per_liter'])->toBeNull();
});

it('keeps battery health distinct from battery level with transparent statuses', function () {
    $service = app(VehicleOperationalHealthService::class);
    $electric = priority4Vehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'battery_level_percent' => 25,
        'battery_health_percent' => 91,
        'battery_health_updated_at' => now(),
    ]);
    $missing = priority4Vehicle([
        'plate_number' => 'P4-E-MISSING',
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'battery_level_percent' => 90,
        'battery_health_percent' => null,
    ]);
    $gasoline = priority4Vehicle(['plate_number' => 'P4-GAS']);

    expect($service->batteryHealth($electric)['label'])->toBe('Good')
        ->and($service->batteryHealth($electric)['value'])->toBe(91.0)
        ->and((float) $electric->battery_level_percent)->toBe(25.0)
        ->and($service->batteryHealth($missing)['label'])->toBe('Not Recorded')
        ->and($service->batteryHealth($gasoline)['label'])->toBe('Not Applicable');
});

it('accepts valid manual battery health and rejects invalid values', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $payload = [
        'plate_number' => 'P4-BATTERY',
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Logistics',
        'brand' => 'Test',
        'model' => 'EV',
        'capacity' => 4,
        'powertrain_type' => 'electric',
        'current_odometer' => 0,
        'status' => 'Available',
        'battery_health_percent' => 90,
        'battery_last_inspection_date' => now()->toDateString(),
    ];

    $this->actingAs($user)->postJson(route('vehicles.store'), $payload)
        ->assertSuccessful()
        ->assertJsonPath('vehicle.battery_health_percent', '90.00');
    expect(Vehicle::query()->where('plate_number', 'P4-BATTERY')->value('battery_health_updated_at'))
        ->not->toBeNull();

    $this->postJson(route('vehicles.store'), [...$payload, 'plate_number' => 'P4-BAD', 'battery_health_percent' => 101])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('battery_health_percent');
});

it('audit logs a manual battery health update without changing readiness', function () {
    $user = User::factory()->create(['role' => 'maintenance']);
    $vehicle = priority4Vehicle([
        'powertrain_type' => 'electric',
        'fuel_type' => 'Electric',
        'tank_capacity' => null,
        'current_fuel' => 0,
        'battery_health_percent' => null,
    ]);

    $this->actingAs($user)->putJson(route('vehicles.update', $vehicle), [
        'battery_health_percent' => 75,
        'battery_last_inspection_date' => now()->toDateString(),
    ])->assertSuccessful();

    expect($vehicle->fresh()->battery_health_percent)->toBe('75.00')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready')
        ->and(AuditLog::query()->latest('id')->value('action'))->toBe('Battery Health Updated');
});

it('classifies registration, insurance, and license compliance without treating null as expired', function () {
    $service = app(VehicleOperationalHealthService::class);

    expect($service->expiryAdvisory(now()->subDay())['state'])->toBe('expired')
        ->and($service->expiryAdvisory(now()->addDays(5))['state'])->toBe('expiring_7_days')
        ->and($service->expiryAdvisory(now()->addDays(20))['state'])->toBe('expiring_30_days')
        ->and($service->expiryAdvisory(now()->addDays(45))['state'])->toBe('expiring_60_days')
        ->and($service->expiryAdvisory(now()->addDays(90))['state'])->toBe('valid')
        ->and($service->expiryAdvisory(null)['state'])->toBe('not_recorded');

    $driver = priority4Driver(['license_expiry' => now()->subDay()->toDateString()]);
    expect($service->driverLicenseAdvisory($driver)['state'])->toBe('expired');
    $driver->license_expiry = null;
    expect($service->driverLicenseAdvisory($driver)['state'])->toBe('not_recorded');
});

it('classifies maintenance by existing next schedule and never creates a record', function (int $days, string $expected) {
    $vehicle = priority4Vehicle();
    Maintenance::query()->create([
        'maintenance_number' => fake()->unique()->bothify('P4-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Scheduled service',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => now()->addDays($days)->toDateString(),
        'cost' => 0,
        'status' => 'Completed',
    ]);

    $before = Maintenance::query()->count();
    $advisory = app(VehicleOperationalHealthService::class)->maintenanceAdvisory($vehicle->fresh());

    expect($advisory['state'])->toBe($expected)
        ->and(Maintenance::query()->count())->toBe($before);
})->with([
    'overdue' => [-1, 'overdue'],
    'due' => [0, 'due'],
    'due soon' => [10, 'due_soon'],
    'not due' => [90, 'not_due'],
]);

it('returns Not Enough Data when no maintenance schedule exists', function () {
    $vehicle = priority4Vehicle();

    expect(app(VehicleOperationalHealthService::class)->maintenanceAdvisory($vehicle)['label'])
        ->toBe('Not Enough Data');
});

it('builds transparent operational health states without overwriting readiness', function (array $attributes, ?string $preTripResult, string $expectedState) {
    $vehicle = priority4Vehicle($attributes);
    if ($preTripResult !== null) {
        $driver = priority4Driver();
        $trip = priority4Trip($vehicle, 10);
        PreTripChecklist::query()->create([
            'dispatch_id' => $trip->dispatch_id,
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'result' => $preTripResult,
            'checked_at' => now(),
        ]);
    }
    $readinessBefore = $vehicle->readiness_status;

    $summary = app(VehicleOperationalHealthService::class)->summarize($vehicle->fresh());

    expect($summary['state'])->toBe($expectedState)
        ->and($summary)->toHaveKeys(['reasons', 'maintenance', 'registration', 'insurance', 'battery_health'])
        ->and($vehicle->fresh()->readiness_status)->toBe($readinessBefore);
})->with([
    'operationally ready' => [[], null, 'operationally_ready'],
    'ready with advisory' => [['insurance_expiry' => now()->addDays(20)->toDateString()], null, 'ready_with_advisory'],
    'attention required' => [[], 'failed', 'attention_required'],
    'unavailable' => [['status' => 'Out of Service', 'readiness_status' => 'unavailable'], null, 'unavailable'],
]);

it('returns Priority 4A dashboard counts through the existing refresh endpoint', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    priority4Vehicle(['insurance_expiry' => now()->subDay()->toDateString()]);
    priority4Driver(['license_expiry' => now()->addDays(5)->toDateString()]);

    $this->actingAs($user)->getJson(route('dashboard.data'))
        ->assertSuccessful()
        ->assertJsonPath('expired_compliance_items', 1)
        ->assertJsonPath('expiring_within_30_days', 1)
        ->assertJsonStructure([
            'maintenance_due_or_overdue',
            'battery_health_review_needed',
            'vehicles_with_operational_warnings',
        ]);
});
