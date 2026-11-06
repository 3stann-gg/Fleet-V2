<?php

use App\Models\User;
use App\Models\Vehicle;
use Symfony\Component\Process\Process;

function acquisitionVehiclePayload(array $overrides = []): array
{
    return array_merge([
        'plate_number' => fake()->unique()->bothify('ACQ-####'),
        'vehicle_type' => 'Service Vehicle',
        'vehicle_condition' => 'brand_new',
        'acquisition_date' => '2026-11-01',
        'department' => 'Logistics',
        'brand' => 'Toyota',
        'model' => 'HiAce',
        'purchase_date' => '2026-10-30',
        'capacity' => 12,
        'powertrain_type' => 'diesel',
        'fuel_type' => 'Diesel',
        'tank_capacity' => 70,
        'current_fuel' => 40,
        'current_odometer' => 25,
        'status' => 'Available',
        'readiness_status' => 'ready',
        'notes' => 'Acquisition classification test vehicle.',
    ], $overrides);
}

function acquisitionVehicleUpdatePayload(Vehicle $vehicle, array $overrides = []): array
{
    return array_merge([
        'plate_number' => $vehicle->plate_number,
        'vehicle_type' => $vehicle->vehicle_type,
        'vehicle_condition' => $vehicle->vehicle_condition,
        'acquisition_date' => $vehicle->acquisition_date?->format('Y-m-d'),
        'department' => $vehicle->department,
        'capacity' => $vehicle->capacity,
        'powertrain_type' => $vehicle->powertrain_type,
        'fuel_type' => $vehicle->fuel_type,
        'tank_capacity' => $vehicle->tank_capacity,
        'status' => $vehicle->status,
        'readiness_status' => $vehicle->readiness_status,
        'notes' => $vehicle->notes,
    ], $overrides);
}

it('creates vehicles with each approved acquisition classification', function (string $condition, string $label) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $response = $this->actingAs($manager)->postJson(
        route('vehicles.store'),
        acquisitionVehiclePayload(['vehicle_condition' => $condition])
    );

    $response->assertSuccessful()
        ->assertJsonPath('vehicle.vehicle_condition', $condition)
        ->assertJsonPath('vehicle.vehicle_condition_label', $label)
        ->assertJsonPath('vehicle.acquisition_date', '2026-11-01');

    $this->assertDatabaseHas('vehicles', [
        'vehicle_condition' => $condition,
        'acquisition_date' => '2026-11-01',
    ]);
})->with([
    'brand-new' => ['brand_new', 'Brand-new'],
    'second-hand' => ['second_hand', 'Second-hand'],
]);

it('requires acquisition classification and date for new registrations', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $payload = acquisitionVehiclePayload();
    unset($payload['vehicle_condition'], $payload['acquisition_date']);

    $this->actingAs($manager)
        ->postJson(route('vehicles.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_condition', 'acquisition_date']);
});

it('rejects invalid acquisition values', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('vehicles.store'), acquisitionVehiclePayload([
            'vehicle_condition' => 'refurbished',
            'acquisition_date' => 'not-a-date',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_condition', 'acquisition_date']);
});

it('edits and returns persisted acquisition details without changing lifecycle state', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload());

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), acquisitionVehicleUpdatePayload($vehicle, [
            'vehicle_condition' => 'second_hand',
            'acquisition_date' => '2026-10-15',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('vehicle.vehicle_condition', 'second_hand')
        ->assertJsonPath('vehicle.vehicle_condition_label', 'Second-hand');

    $this->getJson(route('vehicles.show', $vehicle))
        ->assertSuccessful()
        ->assertJsonPath('vehicle.vehicle_condition', 'second_hand')
        ->assertJsonPath('vehicle.vehicle_condition_label', 'Second-hand')
        ->assertJsonPath('vehicle.acquisition_date', '2026-10-15')
        ->assertJsonPath('vehicle.status', 'Available')
        ->assertJsonPath('vehicle.readiness_status', 'ready');
});

it('keeps legacy null acquisition details compatible with unrelated edits', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $legacyVehicle = Vehicle::query()->create(acquisitionVehiclePayload([
        'vehicle_condition' => null,
        'acquisition_date' => null,
    ]));

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $legacyVehicle), acquisitionVehicleUpdatePayload($legacyVehicle, [
            'notes' => 'Unrelated note update.',
        ]))
        ->assertSuccessful()
        ->assertJsonPath('vehicle.vehicle_condition', null)
        ->assertJsonPath('vehicle.vehicle_condition_label', 'Not Recorded')
        ->assertJsonPath('vehicle.acquisition_date', null);

    $legacyVehicle->refresh();

    expect($legacyVehicle->vehicle_condition)->toBeNull()
        ->and($legacyVehicle->acquisition_date)->toBeNull()
        ->and($legacyVehicle->purchase_date?->format('Y-m-d'))->toBe('2026-10-30')
        ->and($legacyVehicle->notes)->toBe('Unrelated note update.');
});

it('preserves vehicle authorization for acquisition classification changes', function () {
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload());
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);

    $this->actingAs($dispatcher)
        ->putJson(route('vehicles.update', $vehicle), acquisitionVehicleUpdatePayload($vehicle, [
            'vehicle_condition' => 'second_hand',
        ]))
        ->assertForbidden();

    expect($vehicle->fresh()->vehicle_condition)->toBe('brand_new');
});

it('rejects either half of a first acquisition classification for a legacy vehicle', function (array $changes, string $omittedField, string $errorField) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload([
        'vehicle_condition' => null,
        'acquisition_date' => null,
    ]));
    $payload = acquisitionVehicleUpdatePayload($vehicle, $changes);
    unset($payload[$omittedField]);

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);

    $vehicle->refresh();

    expect($vehicle->vehicle_condition)->toBeNull()
        ->and($vehicle->acquisition_date)->toBeNull();
})->with([
    'condition without date' => [
        ['vehicle_condition' => 'second_hand'],
        'acquisition_date',
        'acquisition_date',
    ],
    'date without condition' => [
        ['acquisition_date' => '2026-10-15'],
        'vehicle_condition',
        'vehicle_condition',
    ],
]);

it('rejects clearing one field from an existing complete acquisition pair', function (string $field) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload());
    $payload = acquisitionVehicleUpdatePayload($vehicle, [$field => null]);

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $vehicle->refresh();

    expect($vehicle->vehicle_condition)->toBe('brand_new')
        ->and($vehicle->acquisition_date?->format('Y-m-d'))->toBe('2026-11-01');
})->with(['vehicle_condition', 'acquisition_date']);

it('rejects clearing a complete acquisition pair', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload());

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), acquisitionVehicleUpdatePayload($vehicle, [
            'vehicle_condition' => null,
            'acquisition_date' => null,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vehicle_condition', 'acquisition_date']);

    $vehicle->refresh();

    expect($vehicle->vehicle_condition)->toBe('brand_new')
        ->and($vehicle->acquisition_date?->format('Y-m-d'))->toBe('2026-11-01');
});

it('updates one acquisition field while preserving an omitted existing counterpart', function (string $updatedField, mixed $updatedValue, string $omittedField) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload());
    $payload = acquisitionVehicleUpdatePayload($vehicle, [$updatedField => $updatedValue]);
    unset($payload[$omittedField]);

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), $payload)
        ->assertSuccessful();

    $vehicle->refresh();

    expect($vehicle->vehicle_condition)->toBe(
        $updatedField === 'vehicle_condition' ? 'second_hand' : 'brand_new'
    )->and($vehicle->acquisition_date?->format('Y-m-d'))->toBe(
        $updatedField === 'acquisition_date' ? '2026-10-15' : '2026-11-01'
    );
})->with([
    'condition only' => ['vehicle_condition', 'second_hand', 'acquisition_date'],
    'date only' => ['acquisition_date', '2026-10-15', 'vehicle_condition'],
]);

it('preserves a complete acquisition pair when both fields are omitted', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = Vehicle::query()->create(acquisitionVehiclePayload());
    $payload = acquisitionVehicleUpdatePayload($vehicle, ['notes' => 'No acquisition changes.']);
    unset($payload['vehicle_condition'], $payload['acquisition_date']);

    $this->actingAs($manager)
        ->putJson(route('vehicles.update', $vehicle), $payload)
        ->assertSuccessful();

    $vehicle->refresh();

    expect($vehicle->vehicle_condition)->toBe('brand_new')
        ->and($vehicle->acquisition_date?->format('Y-m-d'))->toBe('2026-11-01')
        ->and($vehicle->purchase_date?->format('Y-m-d'))->toBe('2026-10-30')
        ->and($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready');
});

it('normalizes recorded and missing acquisition dates in the vehicle index response', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    Vehicle::query()->create(acquisitionVehiclePayload(['plate_number' => 'ACQ-DATED']));
    Vehicle::query()->create(acquisitionVehiclePayload([
        'plate_number' => 'ACQ-LEGACY',
        'vehicle_condition' => null,
        'acquisition_date' => null,
    ]));

    $this->actingAs($manager)
        ->getJson(route('fleet'))
        ->assertSuccessful()
        ->assertJsonPath('vehicles.0.acquisition_date', '2026-11-01')
        ->assertJsonPath('vehicles.1.acquisition_date', null);
});

it('formats a missing acquisition date as Not Recorded in the view script', function () {
    $scriptPath = public_path('assets/js/vehicle/vehicle-view.js');
    $nodeScript = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync(process.argv[1], 'utf8');
const context = {
    console,
    Date,
    Intl,
    document: { addEventListener() {} },
};
vm.createContext(context);
vm.runInContext(source, context);
process.stdout.write(JSON.stringify([
    context.formatVehicleAcquisitionDate(null),
    context.formatVehicleAcquisitionDate('2026-11-01'),
]));
JS;
    $process = new Process(['node', '-e', $nodeScript, $scriptPath]);
    $process->mustRun();
    $formattedDates = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($formattedDates[0])->toBe('Not Recorded')
        ->and($formattedDates[1])->not->toBe('Not Recorded')
        ->and($formattedDates[1])->toContain('2026');
});
