<?php

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;

function dashboardRatioDriver(array $attributes = []): Driver
{
    return Driver::query()->create(array_merge([
        'first_name' => 'Ratio',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('RATIO-DL-####'),
        'license_class' => 'Professional',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => fake()->unique()->numerify('0917#######'),
        'status' => 'Available',
        'assigned_vehicle_id' => null,
    ], $attributes));
}

function dashboardRatioVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('RATIO-###'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Ratio Test',
        'model' => 'Vehicle',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

it('counts only approved active driver and vehicle inventory records', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    dashboardRatioDriver(['status' => 'Available']);
    dashboardRatioDriver(['status' => 'On Duty']);
    dashboardRatioDriver(['status' => 'On Leave']);
    dashboardRatioDriver(['status' => 'Inactive']);
    dashboardRatioDriver(['status' => 'Unknown']);
    dashboardRatioDriver([
        'status' => 'Available',
        'archived_at' => now(),
        'archived_by' => $manager->id,
    ]);

    dashboardRatioVehicle([
        'status' => 'Available',
        'readiness_status' => 'unavailable',
    ]);
    dashboardRatioVehicle([
        'status' => 'On Trip',
        'readiness_status' => 'in_use',
    ]);
    dashboardRatioVehicle([
        'status' => 'Maintenance',
        'readiness_status' => 'maintenance',
    ]);
    dashboardRatioVehicle(['status' => 'Out of Service']);
    dashboardRatioVehicle(['status' => 'Unknown']);
    dashboardRatioVehicle([
        'status' => 'Available',
        'readiness_status' => 'unavailable',
        'archived_at' => now(),
        'archived_by' => $manager->id,
    ]);

    $page = $this->actingAs($manager)->get(route('dashboard'));

    $page->assertSuccessful()
        ->assertSee('Driver / Vehicle Ratio')
        ->assertSee('0.67 : 1')
        ->assertSee('id="dashboardActiveDrivers">2</span>', false)
        ->assertSee('id="dashboardActiveVehicles">3</span>', false);

    $this->actingAs($manager)
        ->getJson(route('dashboard.data'))
        ->assertSuccessful()
        ->assertJsonPath('active_drivers', 2)
        ->assertJsonPath('active_vehicles', 3)
        ->assertJsonPath('drivers_per_vehicle', 0.67)
        ->assertJsonPath('ready_vehicles', 0)
        ->assertJsonPath('drivers_on_duty', 1);
});

it('returns an unavailable ratio when no active vehicles exist', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    dashboardRatioDriver();
    dashboardRatioVehicle(['status' => 'Out of Service']);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Driver / Vehicle Ratio')
        ->assertSee('N/A');

    $this->actingAs($manager)
        ->getJson(route('dashboard.data'))
        ->assertSuccessful()
        ->assertJsonPath('active_drivers', 1)
        ->assertJsonPath('active_vehicles', 0)
        ->assertJsonPath('drivers_per_vehicle', null);
});

it('shows a zero ratio when active vehicles exist without active drivers', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    dashboardRatioDriver(['status' => 'Inactive']);
    dashboardRatioVehicle(['status' => 'Maintenance']);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('0.00 : 1');

    $this->actingAs($manager)
        ->getJson(route('dashboard.data'))
        ->assertSuccessful()
        ->assertJsonPath('active_drivers', 0)
        ->assertJsonPath('active_vehicles', 1)
        ->assertJsonPath('drivers_per_vehicle', 0);
});

it('shows equivalent ratio data on the page and live endpoint for authorized roles', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    dashboardRatioDriver();
    dashboardRatioDriver(['status' => 'On Duty']);
    dashboardRatioVehicle();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('Driver / Vehicle Ratio')
        ->assertSee('2.00 : 1')
        ->assertSee('id="dashboardActiveDrivers">2</span>', false)
        ->assertSee('id="dashboardActiveVehicles">1</span>', false);

    $this->actingAs($user)
        ->getJson(route('dashboard.data'))
        ->assertSuccessful()
        ->assertJsonPath('active_drivers', 2)
        ->assertJsonPath('active_vehicles', 1)
        ->assertJsonPath('drivers_per_vehicle', 2);
})->with([
    'fleet manager' => 'fleet_manager',
    'dispatcher' => 'dispatcher',
    'IT administrator' => 'it_admin',
]);

it('does not expose fleet-wide ratio data to restricted roles', function (string $role) {
    $user = User::factory()->create([
        'role' => $role,
        'department' => 'Operations',
    ]);
    dashboardRatioDriver();
    dashboardRatioVehicle();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertDontSee('Driver / Vehicle Ratio')
        ->assertDontSee('dashboardDriverVehicleRatio');

    $this->actingAs($user)
        ->getJson(route('dashboard.data'))
        ->assertSuccessful()
        ->assertJsonMissingPath('active_drivers')
        ->assertJsonMissingPath('active_vehicles')
        ->assertJsonMissingPath('drivers_per_vehicle');
})->with([
    'driver' => 'driver',
    'department head' => 'department_head',
    'finance' => 'finance',
    'maintenance' => 'maintenance',
]);
