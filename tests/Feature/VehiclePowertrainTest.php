<?php

use App\Models\User;

function validVehiclePayload(string $powertrainType): array
{
    $payload = [
        'plate_number' => 'TEST-'.strtoupper(str_replace('_', '-', $powertrainType)),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Test Brand',
        'model' => 'Test Model',
        'capacity' => 4,
        'powertrain_type' => $powertrainType,
        'current_odometer' => 100,
        'status' => 'Available',
    ];

    if (in_array($powertrainType, ['gasoline', 'diesel', 'hybrid', 'plug_in_hybrid'], true)) {
        $payload['fuel_type'] = $powertrainType === 'diesel' ? 'Diesel' : 'Gasoline';
        $payload['tank_capacity'] = 50;
        $payload['current_fuel'] = 25;
    }

    if (in_array($powertrainType, ['hybrid', 'plug_in_hybrid', 'electric'], true)) {
        $payload['battery_capacity_kwh'] = 60;
        $payload['battery_level_percent'] = 75;
    }

    if (in_array($powertrainType, ['plug_in_hybrid', 'electric'], true)) {
        $payload['estimated_range_km'] = 240;
        $payload['charging_port_type'] = 'Type 2';
    }

    return $payload;
}

it('creates each supported vehicle powertrain', function (string $powertrainType) {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $response = $this
        ->actingAs($user)
        ->postJson(route('vehicles.store'), validVehiclePayload($powertrainType));

    $response->assertOk()
        ->assertJsonPath('vehicle.powertrain_type', $powertrainType);

    $this->assertDatabaseHas('vehicles', [
        'plate_number' => validVehiclePayload($powertrainType)['plate_number'],
        'powertrain_type' => $powertrainType,
    ]);
})->with(['gasoline', 'diesel', 'hybrid', 'plug_in_hybrid', 'electric']);

it('rejects a battery level above one hundred percent', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $payload = validVehiclePayload('electric');
    $payload['battery_level_percent'] = 101;

    $this->actingAs($user)
        ->postJson(route('vehicles.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('battery_level_percent');
});

it('preserves existing fuel values when an edited vehicle becomes electric', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);

    $vehicle = $this->actingAs($user)
        ->postJson(route('vehicles.store'), validVehiclePayload('gasoline'))
        ->assertOk()
        ->json('vehicle');

    $this->actingAs($user)
        ->putJson(route('vehicles.update', $vehicle['id']), [
            'plate_number' => $vehicle['plate_number'],
            'vehicle_type' => $vehicle['vehicle_type'],
            'capacity' => $vehicle['capacity'],
            'powertrain_type' => 'electric',
            'battery_capacity_kwh' => 60,
            'battery_level_percent' => 80,
            'estimated_range_km' => 240,
            'charging_port_type' => 'Type 2',
            'status' => $vehicle['status'],
        ])
        ->assertOk();

    $this->assertDatabaseHas('vehicles', [
        'id' => $vehicle['id'],
        'powertrain_type' => 'electric',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 25,
    ]);
});
