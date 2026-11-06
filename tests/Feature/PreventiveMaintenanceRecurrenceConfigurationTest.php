<?php

use App\Http\Controllers\MaintenanceController;
use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

function r2bVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('R2B-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Operations',
        'brand' => 'Recurrence',
        'model' => 'Planner',
        'purchase_date' => now()->subYear()->toDateString(),
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

function r2bMaintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('R2B-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Preventive recurrence configuration test',
        'maintenance_date' => now()->addWeek()->toDateString(),
        'technician' => "R2B Technician \u{2014} Technician \u{2014} In-house",
        'priority' => 'Normal',
        'odometer' => 100,
        'cost' => 100,
        'status' => 'Scheduled',
    ], $attributes));
}

function r2bCreatePayload(Vehicle $vehicle, array $attributes = []): array
{
    return array_merge([
        'request_type' => 'Normal',
        'vehicle_id' => $vehicle->id,
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Preventive recurrence configuration test',
        'maintenance_date' => now()->addWeek()->toDateString(),
        'technician' => "R2B Technician \u{2014} Technician \u{2014} In-house",
        'priority' => 'Normal',
        'odometer' => 100,
        'cost' => 100,
    ], $attributes);
}

function r2bUpdatePayload(Maintenance $maintenance, array $attributes = []): array
{
    return array_merge([
        'vehicle_id' => $maintenance->vehicle_id,
        'maintenance_type' => $maintenance->maintenance_type,
        'description' => $maintenance->description,
        'maintenance_date' => $maintenance->maintenance_date->toDateString(),
        'completion_date' => $maintenance->completion_date?->toDateString(),
        'next_schedule' => $maintenance->next_schedule?->toDateString(),
        'technician' => $maintenance->technician,
        'priority' => $maintenance->priority,
        'odometer' => $maintenance->odometer,
        'parts_used' => $maintenance->parts_used,
        'cost' => $maintenance->cost,
        'status' => $maintenance->status,
        'notes' => $maintenance->notes,
    ], $attributes);
}

function r2bWeeklyConfiguration(array $attributes = []): array
{
    return array_merge([
        'recurrence_enabled' => true,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
        'next_schedule' => null,
    ], $attributes);
}

function r2bMonthlyConfiguration(bool $preserveMonthEnd): array
{
    return [
        'recurrence_enabled' => true,
        'recurrence_frequency' => 'monthly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => $preserveMonthEnd,
        'next_schedule' => null,
    ];
}

function r2bRequest(User $user, array $payload): Request
{
    $request = Request::create('/r2b-maintenance', 'PUT', $payload);
    $request->setUserResolver(fn (): User => $user);

    return $request;
}

beforeEach(function () {
    FleetSetting::query()->create(['settings' => ['maintenance' => [
        'requireCost' => true,
        'serviceTypes' => [
            ['id' => 'preventive', 'name' => 'Preventive Maintenance', 'active' => true],
            ['id' => 'corrective', 'name' => 'Corrective Maintenance', 'active' => true],
            ['id' => 'inspection', 'name' => 'Inspection', 'active' => true],
            ['id' => 'oil_change', 'name' => 'Oil Change', 'active' => true],
        ],
        'providers' => [[
            'id' => 'r2b_technician',
            'name' => 'R2B Technician',
            'type' => 'Technician',
            'contractType' => 'In-house',
            'active' => true,
        ]],
    ]]]);
});

it('adds nullable recurrence columns with the disabled default state', function () {
    expect(Schema::hasColumns('maintenances', [
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_preserve_month_end',
    ]))->toBeTrue();

    $columns = collect(Schema::getColumns('maintenances'))->keyBy('name');

    expect($columns['recurrence_frequency']['nullable'])->toBeTrue()
        ->and($columns['recurrence_interval']['nullable'])->toBeTrue()
        ->and((bool) $columns['recurrence_preserve_month_end']['nullable'])
        ->toBeFalse();

    $maintenance = r2bMaintenance(r2bVehicle())->fresh();

    expect($maintenance->fresh())
        ->recurrence_frequency->toBeNull()
        ->recurrence_interval->toBeNull()
        ->recurrence_preserve_month_end->toBeFalse();
});

it('reverses only the recurrence columns in an isolated database', function () {
    $migration = require database_path(
        'migrations/2026_11_06_153518_add_recurrence_configuration_to_maintenances_table.php'
    );

    $migration->down();

    expect(Schema::hasColumn('maintenances', 'recurrence_frequency'))->toBeFalse()
        ->and(Schema::hasColumn('maintenances', 'recurrence_interval'))->toBeFalse()
        ->and(Schema::hasColumn('maintenances', 'recurrence_preserve_month_end'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumns('maintenances', [
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_preserve_month_end',
    ]))->toBeTrue();

    $columns = collect(Schema::getColumns('maintenances'))->keyBy('name');
    $maintenance = r2bMaintenance(r2bVehicle())->fresh();

    expect($columns['recurrence_frequency']['nullable'])->toBeTrue()
        ->and($columns['recurrence_interval']['nullable'])->toBeTrue()
        ->and((bool) $columns['recurrence_preserve_month_end']['nullable'])
        ->toBeFalse()
        ->and($maintenance->recurrence_frequency)->toBeNull()
        ->and($maintenance->recurrence_interval)->toBeNull()
        ->and($maintenance->recurrence_preserve_month_end)->toBeFalse();
});

it('preserves pre-R2B manual creation and manual next scheduling', function () {
    $vehicle = r2bVehicle();
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $nextSchedule = now()->addMonth()->toDateString();

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload($vehicle, [
            'next_schedule' => $nextSchedule,
        ]))
        ->assertCreated()
        ->assertJsonPath('maintenance.next_schedule', $nextSchedule)
        ->assertJsonPath('maintenance.recurrence_frequency', null);
});

it('stores a complete weekly recurrence without calculating a date', function () {
    $vehicle = r2bVehicle();
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $notificationCount = FleetNotification::query()->count();

    $response = $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            $vehicle,
            r2bWeeklyConfiguration()
        ))
        ->assertCreated()
        ->assertJsonPath('maintenance.recurrence_frequency', 'weekly')
        ->assertJsonPath('maintenance.recurrence_interval', 1)
        ->assertJsonPath('maintenance.recurrence_preserve_month_end', false)
        ->assertJsonPath('maintenance.next_schedule', null);

    expect(Maintenance::query()->count())->toBe(1)
        ->and(FleetNotification::query()->count())->toBe($notificationCount)
        ->and($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready')
        ->and($response->json('maintenance.status'))->toBe('Scheduled');
});

it('stores monthly recurrence with either explicit month-end choice', function (bool $preserveMonthEnd) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            r2bVehicle(),
            r2bMonthlyConfiguration($preserveMonthEnd)
        ))
        ->assertCreated()
        ->assertJsonPath('maintenance.recurrence_frequency', 'monthly')
        ->assertJsonPath('maintenance.recurrence_interval', 1)
        ->assertJsonPath(
            'maintenance.recurrence_preserve_month_end',
            $preserveMonthEnd
        )
        ->assertJsonPath('maintenance.next_schedule', null);
})->with([
    'preserve month end' => true,
    'do not preserve month end' => false,
]);

it('rejects malformed enabled recurrence configurations', function (array $configuration, string $field) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            r2bVehicle(),
            $configuration
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'unsupported interval' => [r2bWeeklyConfiguration(['recurrence_interval' => 2]), 'recurrence_interval'],
    'unsupported frequency' => [r2bWeeklyConfiguration(['recurrence_frequency' => 'yearly']), 'recurrence_frequency'],
    'weekly month end' => [r2bWeeklyConfiguration(['recurrence_preserve_month_end' => true]), 'recurrence_preserve_month_end'],
    'missing frequency' => [r2bWeeklyConfiguration(['recurrence_frequency' => null]), 'recurrence_frequency'],
    'missing interval' => [r2bWeeklyConfiguration(['recurrence_interval' => null]), 'recurrence_interval'],
    'fields without enabled state' => [[
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ], 'recurrence_enabled'],
    'contradictory disabled frequency' => [[
        'recurrence_enabled' => false,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => null,
        'recurrence_preserve_month_end' => false,
    ], 'recurrence_frequency'],
]);

it('rejects recurrence for ineligible requests and service types', function (array $attributes, string $field) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            r2bVehicle(),
            array_merge(r2bWeeklyConfiguration(), $attributes)
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'emergency request' => [['request_type' => 'Emergency'], 'recurrence_enabled'],
    'oil change' => [['maintenance_type' => 'Oil Change'], 'maintenance_type'],
    'corrective maintenance' => [['maintenance_type' => 'Corrective Maintenance'], 'maintenance_type'],
    'inspection' => [['maintenance_type' => 'Inspection'], 'maintenance_type'],
]);

it('supports all approved acquisition classifications without automatic enrollment', function (array $vehicleAttributes) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = r2bVehicle($vehicleAttributes);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            $vehicle,
            r2bWeeklyConfiguration()
        ))
        ->assertCreated()
        ->assertJsonPath('maintenance.recurrence_frequency', 'weekly');
})->with([
    'brand-new' => [[
        'vehicle_condition' => 'brand_new',
        'acquisition_date' => now()->subMonth()->toDateString(),
    ]],
    'second-hand' => [[
        'vehicle_condition' => 'second_hand',
        'acquisition_date' => now()->subMonth()->toDateString(),
    ]],
    'legacy null' => [[
        'vehicle_condition' => null,
        'acquisition_date' => null,
    ]],
]);

it('rejects recurrence with a manual next date', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            r2bVehicle(),
            r2bWeeklyConfiguration([
                'next_schedule' => now()->addMonth()->toDateString(),
            ])
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');
});

it('does not let an omitted next date bypass an existing manual date conflict', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'next_schedule' => now()->addMonth()->toDateString(),
    ]);
    $originalDate = $maintenance->next_schedule->toDateString();
    $payload = r2bUpdatePayload($maintenance, r2bWeeklyConfiguration());
    unset($payload['next_schedule']);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');

    expect($maintenance->fresh()->next_schedule->toDateString())
        ->toBe($originalDate);
});

it('allows an explicit manual-date clear when enabling recurrence', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'next_schedule' => now()->addMonth()->toDateString(),
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            r2bWeeklyConfiguration(['next_schedule' => null])
        ))
        ->assertSuccessful()
        ->assertJsonPath('maintenance.recurrence_frequency', 'weekly')
        ->assertJsonPath('maintenance.next_schedule', null);
});

it('preserves recurrence on unrelated edits and requires explicit disable before changing type', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            ['description' => 'Unrelated description edit']
        ))
        ->assertSuccessful();

    expect($maintenance->fresh()->recurrence_frequency)->toBe('weekly');

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            ['maintenance_type' => 'Inspection']
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('maintenance_type');
});

it('rejects a manual next date when saved recurrence is implicitly preserved', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            ['next_schedule' => now()->addMonth()->toDateString()]
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');

    expect($maintenance->fresh()->next_schedule)->toBeNull()
        ->and($maintenance->fresh()->recurrence_frequency)->toBe('weekly');
});

it('normalizes an explicit disable and supports a manual next date', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'monthly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => true,
    ]);
    $manualDate = now()->addMonth()->toDateString();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            [
                'recurrence_enabled' => false,
                'recurrence_frequency' => null,
                'recurrence_interval' => null,
                'recurrence_preserve_month_end' => false,
                'next_schedule' => $manualDate,
            ]
        ))
        ->assertSuccessful()
        ->assertJsonPath('maintenance.recurrence_frequency', null)
        ->assertJsonPath('maintenance.recurrence_interval', null)
        ->assertJsonPath('maintenance.recurrence_preserve_month_end', false)
        ->assertJsonPath('maintenance.next_schedule', $manualDate);
});

it('allows recurrence changes while Scheduled or In Progress', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $vehicle = r2bVehicle([
        'status' => $status === 'In Progress' ? 'Maintenance' : 'Available',
        'readiness_status' => $status === 'In Progress' ? 'maintenance' : 'ready',
    ]);
    $maintenance = r2bMaintenance($vehicle, ['status' => $status]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            r2bMonthlyConfiguration(true)
        ))
        ->assertSuccessful()
        ->assertJsonPath('maintenance.recurrence_frequency', 'monthly');
})->with(['Scheduled', 'In Progress']);

it('rejects recurrence changes for terminal maintenance without changing history', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            r2bMonthlyConfiguration(true)
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recurrence_enabled');

    expect($maintenance->fresh()->recurrence_frequency)->toBe('weekly')
        ->and($maintenance->fresh()->recurrence_preserve_month_end)->toBeFalse();
})->with(['Completed', 'Cancelled']);

it('rejects a changed terminal next date when unchanged recurrence is submitted', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $storedDate = now()->addMonth()->toDateString();
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'next_schedule' => $storedDate,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);
    $auditCount = AuditLog::query()->count();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            r2bWeeklyConfiguration([
                'next_schedule' => now()->addMonths(2)->toDateString(),
            ])
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('next_schedule');

    expect($maintenance->fresh()->next_schedule->toDateString())->toBe($storedDate)
        ->and($maintenance->fresh()->recurrence_frequency)->toBe('weekly')
        ->and(AuditLog::query()->count())->toBe($auditCount);
})->with(['Completed', 'Cancelled']);

it('rejects an ineligible terminal type when unchanged recurrence is submitted', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);
    $auditCount = AuditLog::query()->count();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            array_merge(r2bWeeklyConfiguration(), [
                'maintenance_type' => 'Inspection',
            ])
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('maintenance_type');

    expect($maintenance->fresh()->maintenance_type)->toBe('Preventive Maintenance')
        ->and($maintenance->fresh()->recurrence_frequency)->toBe('weekly')
        ->and(AuditLog::query()->count())->toBe($auditCount);
})->with(['Completed', 'Cancelled']);

it('does not allow omitted recurrence fields to bypass terminal invariants', function (
    string $status,
    string $changedField,
) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $storedDate = now()->addMonth()->toDateString();
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'next_schedule' => $storedDate,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);
    $auditCount = AuditLog::query()->count();
    $changes = $changedField === 'next_schedule'
        ? ['next_schedule' => now()->addMonths(2)->toDateString()]
        : ['maintenance_type' => 'Inspection'];

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            $changes
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($changedField);

    expect($maintenance->fresh()->maintenance_type)->toBe('Preventive Maintenance')
        ->and($maintenance->fresh()->next_schedule->toDateString())->toBe($storedDate)
        ->and($maintenance->fresh()->recurrence_frequency)->toBe('weekly')
        ->and(AuditLog::query()->count())->toBe($auditCount);
})->with([
    'completed changed date' => ['Completed', 'next_schedule'],
    'cancelled changed date' => ['Cancelled', 'next_schedule'],
    'completed changed type' => ['Completed', 'maintenance_type'],
    'cancelled changed type' => ['Cancelled', 'maintenance_type'],
]);

it('preserves an unchanged terminal recurrence date during an unrelated correction', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $storedDate = now()->addMonth()->toDateString();
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'next_schedule' => $storedDate,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            ['description' => 'Permitted historical note correction']
        ))
        ->assertSuccessful();

    expect($maintenance->fresh()->description)
        ->toBe('Permitted historical note correction')
        ->and($maintenance->fresh()->next_schedule->toDateString())->toBe($storedDate)
        ->and($maintenance->fresh()->recurrence_frequency)->toBe('weekly');
})->with(['Completed', 'Cancelled']);

it('preserves terminal manual-mode date editing behavior', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'next_schedule' => now()->addMonth()->toDateString(),
    ]);
    $changedDate = now()->addMonths(2)->toDateString();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            ['next_schedule' => $changedDate]
        ))
        ->assertSuccessful();

    expect($maintenance->fresh()->recurrence_frequency)->toBeNull()
        ->and($maintenance->fresh()->next_schedule->toDateString())->toBe($changedDate);
})->with(['Completed', 'Cancelled']);

it('does not restrict unrelated edits on terminal recurring maintenance', function (string $status) {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'status' => $status,
        'completion_date' => $status === 'Completed'
            ? now()->addWeek()->toDateString()
            : null,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            ['description' => 'Permitted historical note correction']
        ))
        ->assertSuccessful();

    expect($maintenance->fresh())
        ->description->toBe('Permitted historical note correction')
        ->recurrence_frequency->toBe('weekly');
})->with(['Completed', 'Cancelled']);

it('rechecks recurrence changes against a newer locked terminal state', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);
    $auditCount = AuditLog::query()->count();

    Maintenance::query()->whereKey($maintenance->id)->update([
        'status' => 'Completed',
        'completion_date' => $maintenance->maintenance_date->toDateString(),
    ]);

    $this->actingAs($manager);
    $response = app(MaintenanceController::class)->update(
        r2bRequest($manager, r2bUpdatePayload(
            $maintenance,
            r2bMonthlyConfiguration(true)
        )),
        $maintenance,
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($maintenance->fresh()->status)->toBe('Completed')
        ->and($maintenance->fresh()->recurrence_frequency)->toBe('weekly')
        ->and($maintenance->fresh()->recurrence_preserve_month_end)->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('preserves recurrence through a status-only transition', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'monthly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => true,
    ]);

    $this->actingAs($manager)
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'In Progress',
        ])
        ->assertSuccessful();

    expect($maintenance->fresh())
        ->status->toBe('In Progress')
        ->recurrence_frequency->toBe('monthly')
        ->recurrence_interval->toBe(1)
        ->recurrence_preserve_month_end->toBeTrue();
});

it('preserves recurrence through archive and restore', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ]);

    $this->actingAs($manager)
        ->postJson(route('maintenance.archive', $maintenance))
        ->assertSuccessful();
    $this->actingAs($manager)
        ->postJson(route('maintenance.restore', $maintenance))
        ->assertSuccessful();

    expect($maintenance->fresh()->recurrence_frequency)->toBe('weekly');
});

it('enforces existing create and update authorization', function () {
    $driver = User::factory()->create(['role' => 'driver']);
    $vehicle = r2bVehicle();
    $maintenance = r2bMaintenance($vehicle);

    $this->actingAs($driver)
        ->postJson(route('maintenance.store'), r2bCreatePayload(
            $vehicle,
            r2bWeeklyConfiguration()
        ))
        ->assertForbidden();

    $this->actingAs($driver)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            r2bWeeklyConfiguration()
        ))
        ->assertForbidden();
});

it('includes recurrence values in successful maintenance auditing only', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle());
    $auditCount = AuditLog::query()->count();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance,
            r2bMonthlyConfiguration(true)
        ))
        ->assertSuccessful();

    $audit = AuditLog::query()
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->latest('id')
        ->firstOrFail();

    expect($audit->new_values)
        ->recurrence_frequency->toBe('monthly')
        ->recurrence_interval->toBe(1)
        ->recurrence_preserve_month_end->toBeTrue();

    $this->actingAs($manager)
        ->putJson(route('maintenance.update', $maintenance), r2bUpdatePayload(
            $maintenance->fresh(),
            r2bWeeklyConfiguration(['recurrence_interval' => 2])
        ))
        ->assertUnprocessable();

    expect(AuditLog::query()->count())->toBe($auditCount + 1);
});

it('exposes persisted recurrence values in the authorized view response', function () {
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenance = r2bMaintenance(r2bVehicle(), [
        'recurrence_frequency' => 'monthly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => true,
    ]);

    $this->actingAs($manager)
        ->getJson(route('maintenance.show', $maintenance))
        ->assertSuccessful()
        ->assertJsonPath('maintenance.recurrence_frequency', 'monthly')
        ->assertJsonPath('maintenance.recurrence_interval', 1)
        ->assertJsonPath('maintenance.recurrence_preserve_month_end', true);
});

it('contains the approved recurrence controls and explicit conflict confirmation', function () {
    $addView = file_get_contents(resource_path(
        'views/components/maintenance/add-maintenance-modal.blade.php'
    ));
    $editView = file_get_contents(resource_path(
        'views/components/maintenance/edit-maintenance-modal.blade.php'
    ));
    $viewModal = file_get_contents(resource_path(
        'views/components/maintenance/view-maintenance-modal.blade.php'
    ));
    $editScript = file_get_contents(public_path(
        'assets/js/maintenance/maintenance-edit.js'
    ));

    expect($addView)->toContain('Enable Recurring Maintenance')
        ->and($editView)->toContain('Preserve Month End')
        ->and($viewModal)->toContain('Scheduling Mode')
        ->and($editScript)->toContain('Clear Manual Next Service Due?')
        ->and($editScript)->toContain('nextSchedule.value = ""');
});
