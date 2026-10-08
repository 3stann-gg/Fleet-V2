<?php

use App\Models\ChargingLog;
use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;

it('reports fuel and electricity as separate driver performance values', function () {
    $user = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::create([
        'plate_number' => 'PERF-EV-1',
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Test',
        'model' => 'EV',
        'capacity' => 4,
        'fuel_type' => 'Electric',
        'powertrain_type' => 'electric',
        'current_fuel' => 0,
        'current_odometer' => 100,
        'battery_capacity_kwh' => 60,
        'battery_level_percent' => 80,
        'status' => 'Available',
    ]);
    $driver = Driver::create([
        'first_name' => 'Taylor',
        'last_name' => 'Driver',
        'license_number' => 'PERF-LICENSE-1',
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'status' => 'Available',
        'photo' => 'drivers/formal-photo.jpg',
        'assigned_vehicle_id' => $vehicle->id,
    ]);
    $driver->forceFill(['driver_number' => 'DRV-PERF-1'])->save();

    ChargingLog::create([
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'charging_date' => now()->toDateString(),
        'battery_before_percent' => 40,
        'battery_after_percent' => 80,
        'energy_added_kwh' => 18.4,
        'charging_location' => 'Hospital Motor Pool',
        'charging_cost' => 250,
    ]);

    $this->actingAs($user)
        ->getJson(route('driver-performance.data', ['date_range' => 'all']))
        ->assertOk()
        ->assertJsonPath('records.0.fuel_used', 0)
        ->assertJsonPath('records.0.electricity_used', 18.4)
        ->assertJsonPath(
            'records.0.photo_url',
            asset('storage/drivers/formal-photo.jpg')
        );
});
