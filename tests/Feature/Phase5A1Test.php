<?php

use App\Http\Controllers\ReservationController;
use App\Models\Dispatch;
use App\Models\DispatchReassignment;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

function p51DispatchValidation(array $scenario): array
{
    $script = <<<'JS'
const fs = require('node:fs'), vm = require('node:vm');
const scenario = JSON.parse(process.argv[2]);
const values = {
    dispatchNumber: 'DSP-SYNTHETIC-001', dispatchReservation: '42', dispatchPatient: scenario.patient ?? '',
    dispatchRequestType: scenario.label ?? 'Administrative Trip', dispatchVehicle: 'Synthetic Vehicle',
    dispatchDriver: 'Synthetic Driver', dispatchPickup: 'Synthetic Origin', dispatchDestination: 'Synthetic Destination',
    dispatchDate: '2099-01-01', dispatchTime: '09:00', dispatchPriority: 'Routine', dispatchStatus: 'Pending',
    dispatchContact: '', dispatchSelectedVehicleId: '7', dispatchSelectedDriverId: '8', dispatchNotes: 'Synthetic test only',
    ...scenario.fields,
};
const nodes = {}, handlers = {}, writes = [];
for (const [id, value] of Object.entries(values)) {
    const classes = new Set();
    nodes[id] = { value, readOnly: id === 'dispatchPatient', disabled: false,
        classList: { add: value => classes.add(value), remove: value => classes.delete(value), contains: value => classes.has(value) },
        focus() {}, addEventListener() {} };
}
const form = { dataset: {}, querySelector: selector => selector === '[type="submit"]' ? nodes.submit : nodes[selector.slice(1)],
    querySelectorAll: () => [], addEventListener: (name, fn) => handlers[name] = fn, reset() {} };
nodes.submit = { disabled: false };
nodes.dispatchForm = form;
nodes.addDispatchModal = { classList: { remove() {} } };
const context = vm.createContext({ console, document: { getElementById: id => nodes[id] ?? null,
    addEventListener() {}, body: { style: {} } }, window: {
        FleetRBAC: { hasPermission: (module, permission) => module === 'dispatch' && permission === 'canCreate' },
    } });
for (const file of ['dispatch-modal.js', 'dispatch-add.js']) {
    vm.runInContext(fs.readFileSync(process.argv[1] + '/' + file, 'utf8'), context);
}
context.__reservations = scenario.missing ? [] : [{ id: scenario.recordId ?? 42, request_type: scenario.type,
    patient_name: scenario.storedPatient ?? scenario.patient ?? null, status: scenario.status ?? 'Approved' }];
vm.runInContext('availableReservations = __reservations', context);
(async () => {
    const valid = context.validateDispatchForm(form);
    const invalid = Object.keys(values).filter(id => nodes[id].classList.contains('is-invalid'));
    if (scenario.switchToAdministrative) {
        context.__reservations = [{ id: 42, request_type: 'administrative_trip', patient_name: null, status: 'Approved' }];
        vm.runInContext('availableReservations = __reservations', context);
    }
    const afterSwitch = scenario.switchToAdministrative ? context.validateDispatchForm(form) : null;
    if (scenario.submit) {
        context.dispatchAddApiRequest = async (url, options) => { writes.push({ url, body: JSON.parse(options.body) }); return { success: true }; };
        context.clearReservationDetails = () => {};
        context.clearDispatchAiRecommendation = () => {};
        context.populateReservationSelect = () => {};
        context.initDispatchAdd();
        nodes.dispatchStatus.value = values.dispatchStatus;
        await handlers.submit({ preventDefault() {} });
    }
    process.stdout.write(JSON.stringify({ valid, invalid, afterSwitch,
        patientInvalidAfterSwitch: nodes.dispatchPatient.classList.contains('is-invalid'),
        patientValue: nodes.dispatchPatient.value, patientReadOnly: nodes.dispatchPatient.readOnly, writes }));
})().catch(error => { console.error(error.stack); process.exitCode = 1; });
JS;
    $process = new Process([
        'node', '-e', 'eval(require("node:fs").readFileSync(0, "utf8"))',
        public_path('assets/js/dispatch'), json_encode($scenario, JSON_THROW_ON_ERROR),
    ]);
    $process->setInput($script)->setTimeout(15)->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('validates dispatch patient names using the selected server reservation rather than the display label', function (array $scenario, bool $valid) {
    $result = p51DispatchValidation($scenario);

    expect($result['valid'])->toBe($valid)
        ->and($result['patientValue'])->toBe($scenario['patient'] ?? '')
        ->and($result['patientReadOnly'])->toBeTrue();
})->with([
    'administrative null patient' => [['type' => 'administrative_trip'], true],
    'administrative named patient' => [['type' => 'administrative_trip', 'patient' => 'Synthetic Person'], true],
    'administrative whitespace absent patient' => [['type' => 'administrative_trip', 'patient' => '   '], true],
    'canonical type overrides conflicting display' => [['type' => 'administrative_trip', 'label' => 'Patient Transfer'], true],
    'string reservation ID' => [['type' => 'administrative_trip', 'recordId' => '42'], true],
    'patient transfer null' => [['type' => 'patient_transfer'], false],
    'patient transfer whitespace' => [['type' => 'patient_transfer', 'patient' => '   '], false],
    'patient transfer named' => [['type' => 'patient_transfer', 'patient' => 'Synthetic Patient'], true],
    'emergency response null' => [['type' => 'emergency_response'], false],
    'specimen transport null' => [['type' => 'laboratory_specimen_transport'], false],
    'legacy patient transport null' => [['type' => 'Patient Transport'], false],
    'legacy emergency null' => [['type' => 'Emergency Transfer'], false],
    'legacy appointment null' => [['type' => 'Medical Appointment'], false],
    'legacy laboratory null' => [['type' => 'Laboratory Transport'], false],
    'unknown type cannot use administrative display' => [['type' => 'unknown', 'label' => 'Administrative Trip'], false],
    'missing type' => [[], false],
    'missing selected reservation' => [['type' => 'administrative_trip', 'missing' => true], false],
    'mismatched selected ID' => [['type' => 'administrative_trip', 'recordId' => 43], false],
    'pending reservation has no exemption' => [['type' => 'administrative_trip', 'status' => 'Pending'], false],
    'stored name cannot be silently cleared' => [['type' => 'administrative_trip', 'storedPatient' => 'Synthetic Person'], false],
]);

it('preserves all other dispatch form requirements for a null-patient administrative trip', function (string $field, string $value) {
    $result = p51DispatchValidation(['type' => 'administrative_trip', 'fields' => [$field => $value], 'submit' => true]);

    expect($result['valid'])->toBeFalse()
        ->and($result['invalid'])->toContain($field)
        ->and($result['writes'])->toBe([]);
})->with([
    ['dispatchNumber', 'Generating...'], ['dispatchReservation', ''], ['dispatchRequestType', ''],
    ['dispatchVehicle', ''], ['dispatchDriver', ''], ['dispatchPickup', ''], ['dispatchDestination', ''],
    ['dispatchDate', '2000-01-01'], ['dispatchTime', ''], ['dispatchPriority', ''],
    ['dispatchStatus', 'Completed'], ['dispatchContact', 'invalid-contact'],
]);

it('clears a patient validation error when switching to a valid administrative reservation', function () {
    $result = p51DispatchValidation(['type' => 'patient_transfer', 'switchToAdministrative' => true]);

    expect($result['valid'])->toBeFalse()
        ->and($result['afterSwitch'])->toBeTrue()
        ->and($result['patientInvalidAfterSwitch'])->toBeFalse()
        ->and($result['patientValue'])->toBe('');
});

it('submits the original reservation identity without inventing patient data', function () {
    $result = p51DispatchValidation(['type' => 'administrative_trip', 'submit' => true]);

    expect($result['valid'])->toBeTrue()
        ->and($result['patientValue'])->toBe('')
        ->and($result['writes'])->toBe([[
            'url' => '/dispatch',
            'body' => [
                'dispatch_number' => 'DSP-SYNTHETIC-001', 'reservation_id' => 42,
                'vehicle_id' => 7, 'driver_id' => 8, 'arrival_time' => null, 'remarks' => 'Synthetic test only',
            ],
        ]]);
    $view = file_get_contents(resource_path('views/components/dispatch/add-dispatch-modal.blade.php'));
    preg_match('/<input[^>]*id="dispatchPatient"[^>]*>/', $view, $patient);
    expect($patient[0])->toContain('readonly')->not->toContain('required');
});

it('creates a pending administrative dispatch without changing its null-patient reservation', function () {
    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);
    $reservation = p51Reservation($vehicle, $driver);
    $reservation->update(['request_type' => 'administrative_trip', 'patient_name' => null]);
    $before = $reservation->fresh()->getAttributes();

    $this->actingAs(User::factory()->create(['role' => 'dispatcher']))
        ->postJson(route('dispatch.store'), ['reservation_id' => $reservation->id])
        ->assertCreated()->assertJsonPath('dispatch.trip_status', 'Pending');

    expect($reservation->fresh()->getAttributes())->toBe($before)
        ->and(Dispatch::where('reservation_id', $reservation->id)->count())->toBe(1)
        ->and($vehicle->fresh()->status)->toBe('Available')
        ->and($driver->fresh()->status)->toBe('Available');
});

it('keeps administrative dispatch approval readiness resource and duplicate guards', function (string $blocker) {
    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);
    $reservation = p51Reservation($vehicle, $driver);
    $reservation->update(['request_type' => 'administrative_trip', 'patient_name' => null]);

    if ($blocker === 'approval') {
        $reservation->update(['status' => 'Pending']);
    } elseif ($blocker === 'readiness') {
        $reservation->routePlan->update(['status' => 'Draft']);
    } elseif ($blocker === 'missing route') {
        $reservation->routePlan->delete();
    } elseif ($blocker === 'missing vehicle') {
        $reservation->update(['vehicle_id' => null]);
    } elseif ($blocker === 'missing driver') {
        $reservation->update(['driver_id' => null]);
    } elseif ($blocker === 'duplicate') {
        p51Dispatch($reservation);
    }
    $before = $reservation->fresh()->getAttributes();
    $dispatchCount = Dispatch::count();

    $this->actingAs(User::factory()->create(['role' => 'dispatcher']))
        ->postJson(route('dispatch.store'), ['reservation_id' => $reservation->id])
        ->assertUnprocessable();

    expect($reservation->fresh()->getAttributes())->toBe($before)
        ->and(Dispatch::count())->toBe($dispatchCount);
})->with(['approval', 'readiness', 'missing route', 'missing vehicle', 'missing driver', 'duplicate']);

function p51Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('P51-####'),
        'vehicle_type' => 'Service Vehicle',
        'lto_vehicle_category' => 'M1',
        'department' => 'Logistics',
        'brand' => 'Phase',
        'model' => 'Five',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 35,
        'current_odometer' => 100,
        'transmission_type' => 'automatic',
        'insurance_expiry' => now()->addYear()->toDateString(),
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function p51Driver(Vehicle $vehicle, array $attributes = [], array $codes = ['B'], bool $qualified = true): Driver
{
    $driver = Driver::query()->create(array_merge([
        'first_name' => 'Phase',
        'last_name' => fake()->unique()->lastName(),
        'license_number' => fake()->unique()->bothify('P51-LIC-####'),
        'license_class' => 'Professional',
        'clutch_authorization' => 'MT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000000',
        'email' => fake()->unique()->safeEmail(),
        'assigned_vehicle_id' => $vehicle->id,
        'status' => 'Available',
    ], $attributes));

    foreach ($codes as $code) {
        $driver->licenseCodes()->create(['code' => $code]);
    }

    if ($qualified) {
        DriverVehicleQualification::query()->create([
            'driver_id' => $driver->id,
            'vehicle_type' => $vehicle->vehicle_type,
        ]);
    }

    return $driver;
}

function p51Reservation(Vehicle $vehicle, Driver $driver): Reservation
{
    $reservation = Reservation::query()->create([
        'reservation_number' => fake()->unique()->bothify('P51-RSV-####'),
        'patient_name' => 'Phase 5A1',
        'request_type' => 'Administrative Trip',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'pickup_location' => 'Hospital',
        'destination' => 'Clinic',
        'schedule_date' => now()->addDay()->toDateString(),
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Approved',
    ]);

    RoutePlan::query()->create([
        'route_number' => fake()->unique()->bothify('P51-ROUTE-####'),
        'reservation_id' => $reservation->id,
        'origin' => 'Hospital',
        'destination' => 'Clinic',
        'priority' => 'Normal',
        'department' => 'Logistics',
        'status' => 'Ready For Dispatch',
        'departure_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'estimated_distance' => 10,
    ]);

    return $reservation->fresh();
}

function p51Dispatch(Reservation $reservation): Dispatch
{
    return Dispatch::query()->create([
        'dispatch_number' => fake()->unique()->bothify('P51-DSP-####'),
        'reservation_id' => $reservation->id,
        'dispatch_date' => now()->addDay()->toDateString(),
        'departure_time' => '09:00:00',
        'trip_status' => 'Pending',
    ]);
}

function p51VehiclePayload(string $vehicleType): array
{
    $payload = [
        'plate_number' => fake()->unique()->bothify('TYPE-####'),
        'vehicle_type' => $vehicleType,
        'vehicle_condition' => 'brand_new',
        'acquisition_date' => now()->toDateString(),
        'brand' => 'Canonical',
        'model' => 'Type',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 25,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ];

    if ($vehicleType === 'Motorcycle') {
        $payload = array_merge($payload, [
            'capacity' => 2,
            'engine_displacement_cc' => 160,
            'transmission_type' => 'automatic',
            'helmet_available' => true,
            'cargo_box_available' => true,
            'cargo_capacity_kg' => 20,
            'registration_expiry' => now()->addYear()->toDateString(),
        ]);
    }

    return $payload;
}

it('removes Patient Van from canonical vehicle and driver qualification choices without deleting data', function () {
    $vehicleCount = Vehicle::query()->count();
    $qualificationCount = DriverVehicleQualification::query()->count();

    expect(Vehicle::TYPE_OPTIONS)->toBe(['Ambulance', 'Service Vehicle', 'SUV', 'Motorcycle'])
        ->and(Driver::VEHICLE_TYPE_OPTIONS)->toBe(Vehicle::TYPE_OPTIONS)
        ->and(file_get_contents(resource_path('views/components/vehicle/add-vehicle-modal.blade.php')))->not->toContain('Patient Van')
        ->and(file_get_contents(resource_path('views/components/vehicle/edit-vehicle-modal.blade.php')))->not->toContain('Patient Van')
        ->and(file_get_contents(resource_path('views/route-planning/index.blade.php')))->not->toContain('Patient Van 02')
        ->and(Vehicle::query()->count())->toBe($vehicleCount)
        ->and(DriverVehicleQualification::query()->count())->toBe($qualificationCount);
});

it('rejects Patient Van for new vehicles and driver qualifications', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('vehicles.store'), p51VehiclePayload('Patient Van'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('vehicle_type');

    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);

    $this->putJson(route('drivers.update', $driver), [
        'first_name' => $driver->first_name,
        'last_name' => $driver->last_name,
        'license_number' => $driver->license_number,
        'license_class' => $driver->license_class,
        'license_expiry' => $driver->license_expiry?->format('Y-m-d'),
        'dl_codes_present' => true,
        'dl_codes' => $driver->dl_codes,
        'clutch_authorization' => $driver->clutch_authorization,
        'contact_number' => $driver->contact_number,
        'email' => $driver->email,
        'status' => $driver->status,
        'qualifications' => ['Patient Van'],
    ])->assertUnprocessable()->assertJsonValidationErrors('qualifications.0');
});

it('accepts every remaining canonical vehicle type', function (string $vehicleType) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('vehicles.store'), p51VehiclePayload($vehicleType))
        ->assertOk()
        ->assertJsonPath('vehicle.vehicle_type', $vehicleType);
})->with(['Ambulance', 'Service Vehicle', 'SUV', 'Motorcycle']);

it('returns selectable candidates as explicit vehicle and driver pairs', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $candidateVehicle = p51Vehicle();
    $candidateDriver = p51Driver($originalVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)
        ->getJson(route('dispatch.candidate-vehicles', $reservation))
        ->assertSuccessful()
        ->assertJsonFragment([
            'vehicle_id' => $candidateVehicle->id,
            'driver_id' => $candidateDriver->id,
            'driver_name' => trim("{$candidateDriver->first_name} {$candidateDriver->last_name}"),
        ]);
});

it('creates a dispatch and replaces both reservation assignment IDs atomically', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $newVehicle = p51Vehicle();
    $newDriver = p51Driver($originalVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $newVehicle->id,
        'driver_id' => $newDriver->id,
    ])->assertCreated();

    expect($reservation->fresh())
        ->vehicle_id->toBe($newVehicle->id)
        ->driver_id->toBe($newDriver->id);
});

it('edits a pending dispatch and replaces both reservation assignment IDs atomically', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $newVehicle = p51Vehicle();
    $newDriver = p51Driver($originalVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);
    $dispatch = p51Dispatch($reservation);

    $this->actingAs($dispatcher)->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'Pending',
        'vehicle_id' => $newVehicle->id,
        'driver_id' => $newDriver->id,
    ])->assertSuccessful();

    expect($reservation->fresh())
        ->vehicle_id->toBe($newVehicle->id)
        ->driver_id->toBe($newDriver->id);
});

it('preserves the existing reservation pair when no manual replacement is submitted', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);
    $reservation = p51Reservation($vehicle, $driver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
    ])->assertCreated();

    expect($reservation->fresh())
        ->vehicle_id->toBe($vehicle->id)
        ->driver_id->toBe($driver->id);
});

it('rejects a crafted mismatched pair without partially changing the reservation', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $otherVehicle = p51Vehicle(['vehicle_type' => 'Ambulance']);
    $otherDriver = p51Driver($otherVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $otherVehicle->id,
        'driver_id' => $originalDriver->id,
    ])->assertUnprocessable();

    expect($reservation->fresh())
        ->vehicle_id->toBe($originalVehicle->id)
        ->driver_id->toBe($originalDriver->id)
        ->and(Dispatch::query()->where('reservation_id', $reservation->id)->exists())->toBeFalse()
        ->and($otherDriver->fresh())->not->toBeNull();
});

it('leaves both assignment IDs unchanged when an edit pair fails validation', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $ineligibleVehicle = p51Vehicle(['vehicle_type' => 'Ambulance']);
    $reservation = p51Reservation($originalVehicle, $originalDriver);
    $dispatch = p51Dispatch($reservation);

    $this->actingAs($dispatcher)->putJson(route('dispatch.update', $dispatch), [
        'dispatch_number' => $dispatch->dispatch_number,
        'trip_status' => 'Pending',
        'vehicle_id' => $ineligibleVehicle->id,
        'driver_id' => $originalDriver->id,
    ])->assertUnprocessable();

    expect($reservation->fresh())
        ->vehicle_id->toBe($originalVehicle->id)
        ->driver_id->toBe($originalDriver->id);
});

it('rejects ineligible manual pairs and leaves the original assignment unchanged', function (string $case) {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $candidateVehicle = p51Vehicle();
    $driverAttributes = [];
    $codes = ['B'];
    $qualified = true;

    if ($case === 'lto') {
        $codes = ['A'];
    } elseif ($case === 'clutch') {
        $candidateVehicle->update(['transmission_type' => 'manual']);
        $driverAttributes['clutch_authorization'] = 'AT';
    } elseif ($case === 'qualification') {
        $qualified = false;
    } elseif ($case === 'readiness') {
        $candidateVehicle->update(['readiness_status' => 'maintenance']);
    } elseif ($case === 'driver availability') {
        $driverAttributes['status'] = 'On Leave';
    }

    $candidateDriver = p51Driver($candidateVehicle, $driverAttributes, $codes, $qualified);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
        'vehicle_id' => $candidateVehicle->id,
        'driver_id' => $candidateDriver->id,
    ])->assertUnprocessable();

    expect($reservation->fresh())
        ->vehicle_id->toBe($originalVehicle->id)
        ->driver_id->toBe($originalDriver->id);
})->with(['lto', 'clutch', 'qualification', 'readiness', 'driver availability']);

it('keeps duplicate dispatch protection and reservation ownership unchanged', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $vehicle = p51Vehicle();
    $driver = p51Driver($vehicle);
    $reservation = p51Reservation($vehicle, $driver);
    p51Dispatch($reservation);

    $this->actingAs($dispatcher)->postJson(route('dispatch.store'), [
        'reservation_id' => $reservation->id,
    ])->assertUnprocessable();

    expect(Dispatch::query()->where('reservation_id', $reservation->id)->count())->toBe(1)
        ->and(Schema::hasColumn('dispatch', 'vehicle_id'))->toBeFalse()
        ->and(Schema::hasColumn('dispatch', 'driver_id'))->toBeFalse();
});

it('preserves recommendation pair persistence', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $replacementVehicle = p51Vehicle();
    $replacementDriver = p51Driver($replacementVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);

    $this->actingAs($dispatcher);
    $request = Request::create('/internal/recommendation', 'POST', [
        'vehicle_id' => $replacementVehicle->id,
        'driver_id' => $replacementDriver->id,
    ]);
    $request->setUserResolver(fn (): User => $dispatcher);

    $response = app(ReservationController::class)
        ->applyDispatchRecommendation($request, $reservation);

    expect($response->getStatusCode())->toBe(200)
        ->and($reservation->fresh())
        ->vehicle_id->toBe($replacementVehicle->id)
        ->driver_id->toBe($replacementDriver->id);
});

it('preserves reassignment pair persistence', function () {
    $dispatcher = User::factory()->create(['role' => 'dispatcher']);
    $originalVehicle = p51Vehicle();
    $originalDriver = p51Driver($originalVehicle);
    $replacementVehicle = p51Vehicle();
    $replacementDriver = p51Driver($replacementVehicle);
    $reservation = p51Reservation($originalVehicle, $originalDriver);
    $dispatch = p51Dispatch($reservation);
    $reassignment = DispatchReassignment::query()->create([
        'dispatch_id' => $dispatch->id,
        'requested_by' => $dispatcher->id,
        'reason' => 'Focused Phase 5A1 reassignment regression coverage.',
        'status' => 'Requested',
        'original_vehicle_id' => $originalVehicle->id,
        'original_driver_id' => $originalDriver->id,
    ]);

    $this->actingAs($dispatcher)->postJson(route('dispatch.reassignments.approve', $reassignment), [
        'new_vehicle_id' => $replacementVehicle->id,
        'new_driver_id' => $replacementDriver->id,
    ])->assertSuccessful();

    expect($reservation->fresh())
        ->vehicle_id->toBe($replacementVehicle->id)
        ->driver_id->toBe($replacementDriver->id);
});

it('contains candidate comparison rows inside a responsive scroll region', function () {
    $component = file_get_contents(resource_path('views/components/dispatch/candidate-vehicle-comparison.blade.php'));
    $stylesheet = file_get_contents(public_path('assets/css/pages/dispatch.css'));

    expect($component)
        ->toContain('table-responsive dispatch-candidate-table-scroll')
        ->and($stylesheet)
        ->toContain('.dispatch-candidate-table-scroll')
        ->toContain('max-height: min(55vh, 36rem)')
        ->toContain('overflow: auto')
        ->toContain('position: sticky');
});
