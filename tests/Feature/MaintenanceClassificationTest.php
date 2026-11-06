<?php

use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MaintenanceClassificationService;
use App\Services\ReservationAssignmentEligibilityService;

function maintenanceClassificationVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('MC-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Operations',
        'brand' => 'Panel',
        'model' => 'Review',
        'purchase_date' => now()->subMonth()->toDateString(),
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'status' => 'Available',
        'readiness_status' => 'ready',
    ], $attributes));
}

function maintenanceClassificationRecord(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('MC-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Scheduled fleet service',
        'maintenance_date' => now()->addWeek()->toDateString(),
        'technician' => 'Panel Tech — Technician — In-house',
        'priority' => 'Normal',
        'odometer' => (int) $vehicle->current_odometer,
        'cost' => 100,
        'status' => 'Scheduled',
    ], $attributes));
}

function maintenanceClassificationPayload(Vehicle $vehicle, array $attributes = []): array
{
    return array_merge([
        'request_type' => 'Normal',
        'vehicle_id' => $vehicle->id,
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Scheduled fleet service',
        'maintenance_date' => now()->addWeek()->toDateString(),
        'technician' => 'Panel Tech — Technician — In-house',
        'priority' => 'Normal',
        'odometer' => (int) $vehicle->current_odometer,
        'cost' => 100,
    ], $attributes);
}

beforeEach(function () {
    FleetSetting::query()->create(['settings' => ['maintenance' => [
        'requireCost' => true,
        'serviceTypes' => [
            ['id' => 'preventive', 'name' => 'Preventive Maintenance', 'active' => true],
            ['id' => 'corrective', 'name' => 'Corrective Repair', 'active' => true],
            ['id' => 'inspection', 'name' => 'Inspection', 'active' => true],
            ['id' => 'oil_change', 'name' => 'Oil Change', 'active' => true],
        ],
        'providers' => [[
            'id' => 'panel_tech',
            'name' => 'Panel Tech',
            'type' => 'Technician',
            'contractType' => 'In-house',
            'active' => true,
        ]],
    ]]]);
});

it('creates and classifies scheduled Preventive Maintenance', function () {
    $vehicle = maintenanceClassificationVehicle();
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), maintenanceClassificationPayload($vehicle))
        ->assertCreated()
        ->assertJsonPath('maintenance.maintenance_category', 'Preventive Maintenance')
        ->assertJsonPath('maintenance.status', 'Scheduled');
});

it('classifies Corrective Repair as Corrective Maintenance', function () {
    $maintenance = maintenanceClassificationRecord(
        maintenanceClassificationVehicle(),
        ['maintenance_type' => 'Corrective Repair']
    );

    expect(app(MaintenanceClassificationService::class)->category($maintenance))
        ->toBe('Corrective Maintenance');
});

it('classifies emergency requests as Emergency Repair', function () {
    $maintenance = maintenanceClassificationRecord(
        maintenanceClassificationVehicle(),
        ['request_type' => 'Emergency', 'priority' => 'Emergency', 'status' => 'In Progress']
    );

    expect(app(MaintenanceClassificationService::class)->category($maintenance))
        ->toBe('Emergency Repair');
});

it('classifies Inspection records', function () {
    $maintenance = maintenanceClassificationRecord(
        maintenanceClassificationVehicle(),
        ['maintenance_type' => 'Inspection']
    );

    expect(app(MaintenanceClassificationService::class)->category($maintenance))
        ->toBe('Inspection');
});

it('consistently represents services without a defined category mapping', function (string $maintenanceType) {
    $maintenance = maintenanceClassificationRecord(
        maintenanceClassificationVehicle(),
        ['maintenance_type' => $maintenanceType]
    );

    expect(MaintenanceClassificationService::CATEGORIES)
        ->toContain(MaintenanceClassificationService::OTHER_CATEGORY)
        ->and(app(MaintenanceClassificationService::class)->category($maintenance))
        ->toBe(MaintenanceClassificationService::OTHER_CATEGORY);
})->with([
    'tire service' => 'Tire Service',
    'brake service' => 'Brake Service',
    'engine service' => 'Engine Service',
    'other service' => 'Other',
    'custom configured service' => 'Fleet Calibration',
]);

it('rejects an unconfigured maintenance service type', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), maintenanceClassificationPayload(
            maintenanceClassificationVehicle(),
            ['maintenance_type' => 'Invented Category']
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('maintenance_type');
});

it('rejects a new next service due date that does not follow the current maintenance date', function (int $dayOffset) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceDate = now()->addDays(7)->startOfDay();

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), maintenanceClassificationPayload(
            maintenanceClassificationVehicle(),
            [
                'maintenance_date' => $maintenanceDate->toDateString(),
                'next_schedule' => $maintenanceDate->copy()->addDays($dayOffset)->toDateString(),
            ]
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');
})->with([
    'same date' => 0,
    'earlier date' => -1,
]);

it('accepts an optional next service due date after the current maintenance date', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceDate = now()->addDays(7)->startOfDay();
    $nextSchedule = $maintenanceDate->copy()->addMonth();

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), maintenanceClassificationPayload(
            maintenanceClassificationVehicle(),
            [
                'maintenance_date' => $maintenanceDate->toDateString(),
                'next_schedule' => $nextSchedule->toDateString(),
            ]
        ))
        ->assertCreated()
        ->assertJsonPath('maintenance.next_schedule', $nextSchedule->toDateString());
});

it('requires a newly supplied next service due date to follow the recorded completion date', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceDate = now()->addDays(3)->startOfDay();
    $completionDate = $maintenanceDate->copy()->addDay();

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), maintenanceClassificationPayload(
            maintenanceClassificationVehicle(),
            [
                'maintenance_date' => $maintenanceDate->toDateString(),
                'completion_date' => $completionDate->toDateString(),
                'next_schedule' => $completionDate->toDateString(),
            ]
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');
});

it('rejects an invalid changed next service date without modifying the record', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'maintenance_date' => now()->addDays(5)->toDateString(),
        'next_schedule' => now()->addDays(20)->toDateString(),
    ]);
    $originalNextSchedule = $maintenance->next_schedule->toDateString();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), [
            ...maintenanceClassificationPayload($maintenance->vehicle, [
                'maintenance_date' => $maintenance->maintenance_date->toDateString(),
                'next_schedule' => $maintenance->maintenance_date->toDateString(),
            ]),
            'status' => $maintenance->status,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');

    expect($maintenance->fresh()->next_schedule->toDateString())
        ->toBe($originalNextSchedule);
});

it('allows an unchanged legacy next service date during an unrelated edit', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'maintenance_date' => now()->addDays(5)->toDateString(),
        'next_schedule' => now()->addDays(4)->toDateString(),
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), [
            ...maintenanceClassificationPayload($maintenance->vehicle, [
                'maintenance_date' => $maintenance->maintenance_date->toDateString(),
                'next_schedule' => $maintenance->next_schedule->toDateString(),
                'notes' => 'Unrelated historical-record edit',
            ]),
            'status' => $maintenance->status,
        ])
        ->assertSuccessful();

    expect($maintenance->fresh()->next_schedule->toDateString())
        ->toBe(now()->addDays(4)->toDateString())
        ->and($maintenance->fresh()->notes)->toBe('Unrelated historical-record edit');
});

it('keeps next service scheduling optional when an existing date is cleared', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'maintenance_date' => now()->addDays(5)->toDateString(),
        'next_schedule' => now()->addDays(20)->toDateString(),
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), [
            ...maintenanceClassificationPayload($maintenance->vehicle, [
                'maintenance_date' => $maintenance->maintenance_date->toDateString(),
                'next_schedule' => null,
            ]),
            'status' => $maintenance->status,
        ])
        ->assertSuccessful();

    expect($maintenance->fresh()->next_schedule)->toBeNull();
});

it('does not apply new chronology validation to a status-only lifecycle transition', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = maintenanceClassificationVehicle();
    $maintenance = maintenanceClassificationRecord($vehicle, [
        'maintenance_date' => now()->toDateString(),
        'next_schedule' => now()->subDay()->toDateString(),
    ]);

    $this->actingAs($manager)
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'In Progress',
        ])
        ->assertSuccessful();

    expect($maintenance->fresh()->status)->toBe('In Progress')
        ->and($vehicle->fresh()->status)->toBe('Maintenance')
        ->and($vehicle->fresh()->readiness_status)->toBe('maintenance');
});

it('requires a changed completed-record next service date to follow its completion date', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceDate = now()->addDay()->startOfDay();
    $completionDate = $maintenanceDate->copy()->addDay();
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'status' => 'Completed',
        'maintenance_date' => $maintenanceDate->toDateString(),
        'completion_date' => $completionDate->toDateString(),
        'next_schedule' => $completionDate->copy()->addMonth()->toDateString(),
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), [
            ...maintenanceClassificationPayload($maintenance->vehicle, [
                'maintenance_date' => $maintenanceDate->toDateString(),
                'completion_date' => $completionDate->toDateString(),
                'next_schedule' => $completionDate->toDateString(),
            ]),
            'status' => 'Completed',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');
});

it('does not allow classification data to bypass lifecycle transitions', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle());

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), [
            ...maintenanceClassificationPayload($maintenance->vehicle),
            'status' => 'Completed',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($maintenance->fresh()->status)->toBe('Scheduled');
});

it('allows a new low-odometer vehicle to receive Preventive Maintenance', function () {
    $vehicle = maintenanceClassificationVehicle([
        'purchase_date' => now()->subWeek()->toDateString(),
        'current_odometer' => 20,
    ]);
    $maintenance = maintenanceClassificationRecord($vehicle);

    expect(app(MaintenanceClassificationService::class)->category($maintenance))
        ->toBe('Preventive Maintenance')
        ->and((float) $vehicle->current_odometer)->toBe(20.0);
});

it('does not treat low odometer as maintenance-free', function () {
    $vehicle = maintenanceClassificationVehicle(['current_odometer' => 5]);

    maintenanceClassificationRecord($vehicle);

    expect($vehicle->maintenances()->count())->toBe(1);
});

it('does not mark a vehicle unsafe merely because Preventive Maintenance is scheduled', function () {
    $vehicle = maintenanceClassificationVehicle();

    maintenanceClassificationRecord($vehicle);

    expect($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready');
});

it('keeps a new vehicle usable when no hard maintenance restriction exists', function () {
    $vehicle = maintenanceClassificationVehicle(['purchase_date' => now()->toDateString()]);

    expect($vehicle->isOperationallyReady())->toBeTrue();
});

it('does not mark an older vehicle Out of Service based on purchase date', function () {
    $vehicle = maintenanceClassificationVehicle(['purchase_date' => now()->subYears(15)->toDateString()]);

    expect($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready');
});

it('does not automatically create Maintenance from vehicle age', function () {
    $vehicle = maintenanceClassificationVehicle(['purchase_date' => now()->subYears(15)->toDateString()]);

    expect($vehicle->maintenances()->count())->toBe(0);
});

it('does not make vehicle age an operational-readiness blocker', function () {
    $vehicle = maintenanceClassificationVehicle(['purchase_date' => now()->subYears(20)->toDateString()]);

    expect($vehicle->isOperationallyReady())->toBeTrue();
});

it('does not exclude a vehicle from Reservation eligibility because of age alone', function () {
    $vehicle = maintenanceClassificationVehicle([
        'purchase_date' => now()->subYears(20)->toDateString(),
    ]);

    $eligibleVehicleIds = app(ReservationAssignmentEligibilityService::class)
        ->eligibleVehicles(
            now()->addDay()->toDateString(),
            '09:00',
            2,
        )
        ->pluck('id');

    expect($eligibleVehicleIds)->toContain($vehicle->id);
});

it('keeps maintenance history accessible for an older vehicle', function () {
    $vehicle = maintenanceClassificationVehicle(['purchase_date' => now()->subYears(12)->toDateString()]);
    maintenanceClassificationRecord($vehicle, ['status' => 'Completed', 'completion_date' => now()]);
    maintenanceClassificationRecord($vehicle, ['maintenance_type' => 'Corrective Repair']);

    expect($vehicle->maintenances()->count())->toBe(2)
        ->and($vehicle->maintenances()->pluck('maintenance_type')->all())
        ->toContain('Preventive Maintenance', 'Corrective Repair');
});

it('reports a future scheduled service as Upcoming', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'maintenance_date' => now()->addDays(10),
    ]);

    expect(app(MaintenanceClassificationService::class)->dueContext($maintenance))
        ->toMatchArray([
            'maintenance_due_state' => 'upcoming',
            'maintenance_due_label' => 'Upcoming',
            'maintenance_due_basis' => 'Scheduled maintenance date',
        ]);
});

it('reports a scheduled service due today as Due', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'maintenance_date' => now(),
    ]);

    expect(app(MaintenanceClassificationService::class)->dueContext($maintenance)['maintenance_due_state'])
        ->toBe('due');
});

it('reports a past scheduled service as Overdue', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'maintenance_date' => now()->subDay(),
    ]);

    expect(app(MaintenanceClassificationService::class)->dueContext($maintenance)['maintenance_due_state'])
        ->toBe('overdue');
});

it('keeps an in-progress lifecycle while exposing its passed scheduled date as overdue context', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'status' => 'In Progress',
        'maintenance_date' => now()->subDay(),
    ]);
    $context = app(MaintenanceClassificationService::class)->dueContext($maintenance);

    expect($maintenance->status)->toBe('In Progress')
        ->and($context['maintenance_due_state'])->toBe('overdue')
        ->and($context['maintenance_due_basis'])->toBe('Scheduled maintenance date');
});

it('uses next schedule as the due basis for completed maintenance', function () {
    $nextSchedule = now()->addMonth()->startOfDay();
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'status' => 'Completed',
        'completion_date' => now(),
        'next_schedule' => $nextSchedule,
    ]);
    $context = app(MaintenanceClassificationService::class)->dueContext($maintenance);

    expect($context['maintenance_due_state'])->toBe('upcoming')
        ->and($context['maintenance_due_basis'])->toBe('Next scheduled service date')
        ->and($context['maintenance_due_date'])->toBe($nextSchedule->toDateString());
});

it('marks cancelled maintenance due context as not applicable', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'status' => 'Cancelled',
    ]);

    expect(app(MaintenanceClassificationService::class)->dueContext($maintenance))
        ->toMatchArray([
            'maintenance_due_state' => 'not_applicable',
            'maintenance_due_label' => 'Not Applicable',
            'maintenance_due_date' => null,
            'maintenance_due_basis' => 'Cancelled maintenance record',
            'maintenance_days_until_due' => null,
        ]);
});

it('safely represents an incomplete completed history record', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle(), [
        'status' => 'Completed',
        'completion_date' => now(),
        'next_schedule' => null,
    ]);

    expect(app(MaintenanceClassificationService::class)->dueContext($maintenance))
        ->toMatchArray([
            'maintenance_due_state' => 'not_scheduled',
            'maintenance_due_label' => 'Not Scheduled',
        ]);
});

it('exposes category due basis and neutral age context through the Maintenance API', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle([
        'purchase_date' => now()->subYears(10)->toDateString(),
        'current_odometer' => 150000,
    ]));

    $this->actingAs($manager)
        ->getJson(route('maintenance.show', $maintenance))
        ->assertSuccessful()
        ->assertJsonPath('maintenance.maintenance_category', 'Preventive Maintenance')
        ->assertJsonPath('maintenance.maintenance_due_label', 'Upcoming')
        ->assertJsonPath('maintenance.vehicle_service_context.current_odometer', 150000)
        ->assertJsonPath(
            'maintenance.vehicle_service_context.basis',
            'Category is derived from the recorded request and service types. Due context is derived from maintenance status and recorded dates. Vehicle purchase date and odometer are display context only.'
        );
});

it('keeps classification and due fields response only', function () {
    $maintenance = maintenanceClassificationRecord(maintenanceClassificationVehicle());
    $service = app(MaintenanceClassificationService::class);

    $service->enrichOne($maintenance);

    expect($maintenance->maintenance_category)->toBe('Preventive Maintenance')
        ->and($maintenance->maintenance_due_state)->toBe('upcoming');

    $freshMaintenance = $maintenance->fresh();

    expect($freshMaintenance->getAttributes())
        ->not->toHaveKeys([
            'maintenance_category',
            'maintenance_due_state',
            'maintenance_due_label',
            'maintenance_due_date',
            'maintenance_due_basis',
            'maintenance_days_until_due',
            'vehicle_service_context',
        ]);
});
