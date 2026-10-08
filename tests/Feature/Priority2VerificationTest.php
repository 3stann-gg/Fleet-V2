<?php

use App\Models\Reservation;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A. Reservation Classification Tests
|--------------------------------------------------------------------------
*/

it('supports new hospital request types and returns correct human-readable labels', function () {
    $reservation = new Reservation([
        'request_type' => 'emergency_response',
        'priority' => 'emergency',
    ]);

    expect($reservation->request_type_label)->toBe('Emergency Response');
    expect($reservation->priority_label)->toBe('Emergency');

    $supply = new Reservation([
        'request_type' => 'medical_supply_delivery',
        'priority' => 'urgent',
    ]);

    expect($supply->request_type_label)->toBe('Medical Supply Delivery');
    expect($supply->priority_label)->toBe('Urgent');
    expect($supply->isSupplyDelivery())->toBeTrue();
});

it('preserves legacy request types and legacy priorities without breaking', function () {
    $legacyPatient = new Reservation([
        'request_type' => 'Patient Transport',
        'priority' => 'High',
    ]);

    expect($legacyPatient->request_type_label)->toBe('Patient Transport');
    expect($legacyPatient->priority_label)->toBe('Urgent');

    $legacySupply = new Reservation([
        'request_type' => 'Supply Delivery',
        'priority' => 'Normal',
    ]);

    expect($legacySupply->request_type_label)->toBe('Supply Delivery');
    expect($legacySupply->priority_label)->toBe('Routine');
    expect($legacySupply->isSupplyDelivery())->toBeTrue();

    $legacyLow = new Reservation([
        'request_type' => 'Emergency Transfer',
        'priority' => 'Low',
    ]);

    expect($legacyLow->request_type_label)->toBe('Emergency Transfer');
    expect($legacyLow->priority_label)->toBe('Scheduled');
});

it('safely handles null request_type and priority', function () {
    $nullReservation = new Reservation([]);

    expect($nullReservation->request_type_label)->toBe('Not Specified');
    expect($nullReservation->priority_label)->toBe('Not Specified');
    expect($nullReservation->priorityRank())->toBe(5);
});

/*
|--------------------------------------------------------------------------
| B. Vehicle Readiness Status Tests
|--------------------------------------------------------------------------
*/

it('handles vehicle readiness_status coexisting with status', function () {
    $readyVehicle = new Vehicle([
        'status' => 'Available',
        'readiness_status' => 'ready',
    ]);

    expect($readyVehicle->isOperationallyReady())->toBeTrue();
    expect($readyVehicle->readiness_label)->toBe('Ready for Dispatch');

    $refuelingVehicle = new Vehicle([
        'status' => 'Available',
        'readiness_status' => 'refueling',
    ]);

    expect($refuelingVehicle->isOperationallyReady())->toBeFalse();
    expect($refuelingVehicle->readiness_label)->toBe('Refueling');
});

it('remains backward compatible with old vehicles where readiness_status is null', function () {
    $legacyAvailable = new Vehicle([
        'status' => 'Available',
        'readiness_status' => null,
    ]);

    expect($legacyAvailable->isOperationallyReady())->toBeTrue();
    expect($legacyAvailable->readiness_label)->toBe('Ready for Dispatch');

    $legacyMaintenance = new Vehicle([
        'status' => 'Maintenance',
        'readiness_status' => null,
    ]);

    expect($legacyMaintenance->isOperationallyReady())->toBeFalse();
    expect($legacyMaintenance->readiness_label)->toBe('Maintenance');
});

/*
|--------------------------------------------------------------------------
| C. Priority Ranking Tests
|--------------------------------------------------------------------------
*/

it('correctly ranks both legacy and hospital priority values', function () {
    $emergency = new Reservation(['priority' => 'emergency']);
    $legacyEmergency = new Reservation(['priority' => 'Emergency']);
    $urgent = new Reservation(['priority' => 'urgent']);
    $legacyHigh = new Reservation(['priority' => 'High']);
    $routine = new Reservation(['priority' => 'routine']);
    $legacyNormal = new Reservation(['priority' => 'Normal']);
    $scheduled = new Reservation(['priority' => 'scheduled']);
    $legacyLow = new Reservation(['priority' => 'Low']);

    expect($emergency->priorityRank())->toBe(1);
    expect($legacyEmergency->priorityRank())->toBe(1);
    expect($urgent->priorityRank())->toBe(2);
    expect($legacyHigh->priorityRank())->toBe(2);
    expect($routine->priorityRank())->toBe(3);
    expect($legacyNormal->priorityRank())->toBe(3);
    expect($scheduled->priorityRank())->toBe(4);
    expect($legacyLow->priorityRank())->toBe(4);
});

/*
|--------------------------------------------------------------------------
| D. Energy Advisory Tests
|--------------------------------------------------------------------------
*/

it('evaluates BEV energy advisory accurately based on estimated range', function () {
    $bev = new Vehicle([
        'status' => 'Available',
        'readiness_status' => 'ready',
        'powertrain_type' => 'electric',
        'estimated_range_km' => 100,
    ]);

    $warningAdvisory = $bev->energyAdvisory(150);
    expect($warningAdvisory['state'])->toBe('warning');
    expect($warningAdvisory['message'])->toContain('below the planned trip distance');

    $suitableAdvisory = $bev->energyAdvisory(80);
    expect($suitableAdvisory['state'])->toBe('suitable');

    $missingAdvisory = $bev->energyAdvisory(null);
    expect($missingAdvisory['state'])->toBe('unavailable');
});

it('evaluates PHEV energy advisory without marking vehicle unusable', function () {
    $phev = new Vehicle([
        'status' => 'Available',
        'readiness_status' => 'ready',
        'powertrain_type' => 'plug_in_hybrid',
        'estimated_range_km' => 30,
        'current_fuel' => 40,
        'tank_capacity' => 50,
    ]);

    $advisory = $phev->energyAdvisory(60);
    expect($advisory['state'])->toBe('warning');
    expect($advisory['message'])->toContain('Electric range is limited');
    expect($phev->isOperationallyReady())->toBeTrue();
});

it('verifies HEV charging capabilities and liquid fuel support', function () {
    $hev = new Vehicle([
        'powertrain_type' => 'hybrid',
    ]);

    expect($hev->supportsExternalCharging())->toBeFalse();
    expect($hev->supportsLiquidFuel())->toBeTrue();
});

it('verifies Gasoline and Diesel vehicles support liquid fuel without fake threshold', function () {
    $gasoline = new Vehicle([
        'status' => 'Available',
        'readiness_status' => 'ready',
        'powertrain_type' => 'gasoline',
    ]);

    expect($gasoline->supportsLiquidFuel())->toBeTrue();
    expect($gasoline->supportsExternalCharging())->toBeFalse();
    expect($gasoline->energyAdvisory(100)['state'])->toBe('suitable');
});
