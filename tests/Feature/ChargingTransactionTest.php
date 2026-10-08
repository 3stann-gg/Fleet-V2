<?php

use App\Models\ChargingLog;
use App\Models\User;
use App\Models\Vehicle;

function makeChargingTestVehicle(string $powertrainType): Vehicle
{
    return Vehicle::create([
        'plate_number' => fake()->unique()->bothify('EV-###'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Test Brand',
        'model' => 'Test Model',
        'capacity' => 4,
        'fuel_type' => $powertrainType === 'electric' ? 'Electric' : 'Gasoline',
        'powertrain_type' => $powertrainType,
        'tank_capacity' => $powertrainType === 'electric' ? null : 50,
        'current_fuel' => 0,
        'current_odometer' => 100,
        'battery_capacity_kwh' => 60,
        'battery_level_percent' => 20,
        'status' => 'Available',
    ]);
}

function validChargingPayload(Vehicle $vehicle): array
{
    return [
        'vehicle_id' => $vehicle->id,
        'charging_date' => now()->toDateString(),
        'charging_time' => '10:30',
        'battery_before_percent' => 20,
        'battery_after_percent' => 80,
        'energy_added_kwh' => 36,
        'charging_location' => 'Hospital Motor Pool',
        'charging_cost' => 540,
        'odometer' => 120,
        'notes' => 'Routine charging session',
    ];
}

it('records charging for externally chargeable vehicles and updates the latest battery level', function (string $powertrainType) {
    $user = User::factory()->create(['role' => 'maintenance']);
    $vehicle = makeChargingTestVehicle($powertrainType);

    $this->actingAs($user)
        ->postJson(route('charging.store'), validChargingPayload($vehicle))
        ->assertCreated()
        ->assertJsonPath('chargingLog.energy_added_kwh', '36.00');

    expect($vehicle->refresh()->battery_level_percent)->toBe('80.00');
    $this->assertDatabaseHas('charging_logs', [
        'vehicle_id' => $vehicle->id,
        'battery_after_percent' => 80,
    ]);

    $this->actingAs($user)
        ->getJson(route('charging.index'))
        ->assertOk()
        ->assertJsonPath('chargingLogs.0.vehicle_id', $vehicle->id)
        ->assertJsonPath('chargingLogs.0.vehicle.powertrain_type', $powertrainType);
})->with(['plug_in_hybrid', 'electric']);

it('rejects external charging for non chargeable powertrains', function (string $powertrainType) {
    $user = User::factory()->create(['role' => 'maintenance']);
    $vehicle = makeChargingTestVehicle($powertrainType);

    $this->actingAs($user)
        ->postJson(route('charging.store'), validChargingPayload($vehicle))
        ->assertUnprocessable();

    expect(ChargingLog::query()->count())->toBe(0);
})->with(['gasoline', 'diesel', 'hybrid']);

it('rejects invalid battery percentages', function (array $changes, string $field) {
    $user = User::factory()->create(['role' => 'maintenance']);
    $vehicle = makeChargingTestVehicle('electric');

    $this->actingAs($user)
        ->postJson(route('charging.store'), [
            ...validChargingPayload($vehicle),
            ...$changes,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'above one hundred' => [['battery_after_percent' => 101], 'battery_after_percent'],
    'after below before' => [[
        'battery_before_percent' => 80,
        'battery_after_percent' => 20,
    ], 'battery_after_percent'],
]);
