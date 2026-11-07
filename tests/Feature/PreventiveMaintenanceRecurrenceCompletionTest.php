<?php

use App\Models\AuditLog;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;

function r2c2Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('R2C2-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Operations',
        'brand' => 'Recurrence',
        'model' => 'Completion',
        'purchase_date' => '2025-01-01',
        'capacity' => 4,
        'powertrain_type' => 'gasoline',
        'fuel_type' => 'Gasoline',
        'tank_capacity' => 50,
        'current_fuel' => 30,
        'current_odometer' => 100,
        'status' => 'Maintenance',
        'readiness_status' => 'maintenance',
    ], $attributes));
}

function r2c2Maintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('R2C2-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Preventive recurrence completion test',
        'maintenance_date' => '2026-10-01',
        'technician' => "R2C2 Technician \u{2014} Technician \u{2014} In-house",
        'priority' => 'Normal',
        'odometer' => 100,
        'cost' => 100,
        'status' => 'In Progress',
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ], $attributes));
}

function r2c2UpdatePayload(Maintenance $maintenance, array $attributes = []): array
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

function r2c2CompletionAudit(Maintenance $maintenance): AuditLog
{
    return AuditLog::query()
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->where('action', 'Completed')
        ->sole();
}

function r2c2AssertRejectedWithoutMutation(
    Maintenance $maintenance,
    Vehicle $vehicle,
    string $errorField,
): void {
    $beforeMaintenance = $maintenance->fresh()->getAttributes();
    $beforeVehicle = $vehicle->fresh()->getAttributes();
    $maintenanceCount = Maintenance::query()->count();
    $auditCount = AuditLog::query()->count();

    test()->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);

    expect($maintenance->fresh()->getAttributes())->toBe($beforeMaintenance)
        ->and($vehicle->fresh()->getAttributes())->toBe($beforeVehicle)
        ->and(Maintenance::query()->count())->toBe($maintenanceCount)
        ->and(AuditLog::query()->count())->toBe($auditCount);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 11, 6, 10, 0, 0, config('app.timezone')));

    FleetSetting::query()->create(['settings' => [
        'maintenance' => [
            'requireCost' => true,
            'overdueWarnDays' => 3,
            'serviceTypes' => [
                ['id' => 'preventive', 'name' => 'Preventive Maintenance', 'active' => true],
                ['id' => 'corrective', 'name' => 'Corrective Maintenance', 'active' => true],
                ['id' => 'inspection', 'name' => 'Inspection', 'active' => true],
            ],
            'providers' => [[
                'id' => 'r2c2_technician',
                'name' => 'R2C2 Technician',
                'type' => 'Technician',
                'contractType' => 'In-house',
                'active' => true,
            ]],
        ],
        'notifications' => ['maintenanceDue' => false],
    ]]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('completes weekly recurrence through the lifecycle route and audits persisted changes', function () {
    $vehicle = r2c2Vehicle();
    $maintenance = r2c2Maintenance($vehicle);
    $maintenanceCount = Maintenance::query()->count();
    $maintenanceAuditCount = AuditLog::query()
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful()
        ->assertJsonPath('maintenance.status', 'Completed')
        ->assertJsonPath('maintenance.completion_date', '2026-11-06')
        ->assertJsonPath('maintenance.next_schedule', '2026-11-13')
        ->assertJsonPath('maintenance.recurrence_frequency', 'weekly')
        ->assertJsonPath('maintenance.recurrence_interval', 1)
        ->assertJsonPath('maintenance.recurrence_preserve_month_end', false);

    $maintenance->refresh();
    $vehicle->refresh();
    $audit = r2c2CompletionAudit($maintenance);

    expect($maintenance->status)->toBe('Completed')
        ->and($maintenance->completion_date->toDateString())->toBe('2026-11-06')
        ->and($maintenance->next_schedule->toDateString())->toBe('2026-11-13')
        ->and($maintenance->recurrence_frequency)->toBe('weekly')
        ->and($maintenance->recurrence_interval)->toBe(1)
        ->and($maintenance->recurrence_preserve_month_end)->toBeFalse()
        ->and($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready')
        ->and(Maintenance::query()->count())->toBe($maintenanceCount)
        ->and(AuditLog::query()
            ->where('auditable_type', Maintenance::class)
            ->where('auditable_id', $maintenance->id)
            ->count())->toBe($maintenanceAuditCount + 1)
        ->and($audit->auditable_id)->toBe($maintenance->id)
        ->and($audit->old_values)->toMatchArray([
            'status' => 'In Progress',
            'completion_date' => null,
            'next_schedule' => null,
        ])
        ->and($audit->old_values)->toHaveCount(3)
        ->and($audit->new_values)->toMatchArray([
            'status' => 'Completed',
            'completion_date' => '2026-11-06',
            'next_schedule' => '2026-11-13',
        ])
        ->and($audit->new_values)->toHaveCount(3)
        ->and($audit->new_values)->not->toHaveKeys([
            'recurrence_frequency',
            'recurrence_interval',
            'recurrence_preserve_month_end',
        ]);
});

it('persists ordinary and no-overflow monthly recurrence dates', function (
    string $maintenanceDate,
    string $completionDate,
    string $expectedNextSchedule,
) {
    Carbon::setTestNow("{$completionDate} 10:00:00");
    $maintenance = r2c2Maintenance(r2c2Vehicle(), [
        'maintenance_date' => $maintenanceDate,
        'recurrence_frequency' => 'monthly',
    ]);

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful();

    expect($maintenance->fresh()->next_schedule->toDateString())
        ->toBe($expectedNextSchedule);
})->with([
    'ordinary month' => ['2026-11-01', '2026-11-06', '2026-12-06'],
    'January overflow clamp' => ['2026-01-15', '2026-01-31', '2026-02-28'],
]);

it('persists approved monthly month-end behavior through completion', function (
    string $maintenanceDate,
    string $completionDate,
    bool $preserveMonthEnd,
    string $expectedNextSchedule,
) {
    Carbon::setTestNow("{$completionDate} 10:00:00");
    $maintenance = r2c2Maintenance(r2c2Vehicle(), [
        'maintenance_date' => $maintenanceDate,
        'recurrence_frequency' => 'monthly',
        'recurrence_preserve_month_end' => $preserveMonthEnd,
    ]);

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful();

    expect($maintenance->fresh())
        ->next_schedule->toDateString()->toBe($expectedNextSchedule)
        ->recurrence_preserve_month_end->toBe($preserveMonthEnd);
})->with([
    'preserves April month end' => ['2026-04-15', '2026-04-30', true, '2026-05-31'],
    'does not force month end' => ['2026-04-15', '2026-04-30', false, '2026-05-30'],
    'leap February month end' => ['2028-02-15', '2028-02-29', true, '2028-03-31'],
    'non-leap February month end' => ['2026-02-15', '2026-02-28', true, '2026-03-31'],
    'year-end rollover' => ['2026-12-15', '2026-12-31', true, '2027-01-31'],
]);

it('uses and preserves a stored completion date instead of maintenance or server dates', function () {
    $maintenance = r2c2Maintenance(r2c2Vehicle(), [
        'maintenance_date' => '2026-09-01',
        'completion_date' => '2026-10-20',
    ]);

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful();

    expect($maintenance->fresh())
        ->completion_date->toDateString()->toBe('2026-10-20')
        ->next_schedule->toDateString()->toBe('2026-10-27');
});

it('uses the configured-timezone server date when completion date is missing', function () {
    $originalApplicationTimezone = config('app.timezone');
    $originalPhpTimezone = date_default_timezone_get();

    try {
        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
        Carbon::setTestNow(
            Carbon::parse('2026-11-06T16:30:00Z')->setTimezone('Asia/Manila')
        );

        $maintenance = r2c2Maintenance(r2c2Vehicle(), [
            'maintenance_date' => '2026-10-01',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
            ->postJson(route('maintenance.transition', $maintenance), [
                'status' => 'Completed',
            ])
            ->assertSuccessful();

        expect($maintenance->fresh())
            ->completion_date->toDateString()->toBe('2026-11-07')
            ->next_schedule->toDateString()->toBe('2026-11-14');
    } finally {
        Carbon::setTestNow();
        config(['app.timezone' => $originalApplicationTimezone]);
        date_default_timezone_set($originalPhpTimezone);
    }
});

it('does not allow the transition request to supply a completion date', function () {
    $maintenance = r2c2Maintenance(r2c2Vehicle());
    $auditCount = AuditLog::query()->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
            'completion_date' => '2027-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Maintenance transition requests may only contain status.');

    expect($maintenance->fresh())
        ->status->toBe('In Progress')
        ->completion_date->toBeNull()
        ->next_schedule->toBeNull()
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('accepts an existing calculated next schedule and preserves it', function () {
    $maintenance = r2c2Maintenance(r2c2Vehicle(), [
        'next_schedule' => '2026-11-13',
    ]);
    $auditCount = AuditLog::query()->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful();

    $maintenance->refresh();
    $audit = r2c2CompletionAudit($maintenance);

    expect($maintenance->status)->toBe('Completed')
        ->and($maintenance->completion_date->toDateString())->toBe('2026-11-06')
        ->and($maintenance->next_schedule->toDateString())->toBe('2026-11-13')
        ->and($audit->new_values)->toMatchArray([
            'status' => 'Completed',
            'completion_date' => '2026-11-06',
        ])
        ->and($audit->new_values)->not->toHaveKey('next_schedule')
        ->and(AuditLog::query()->count())->toBe($auditCount + 1);
});

it('rejects a conflicting next schedule and rolls back completion atomically', function () {
    $vehicle = r2c2Vehicle();
    $maintenance = r2c2Maintenance($vehicle, [
        'next_schedule' => '2026-11-20',
    ]);

    r2c2AssertRejectedWithoutMutation($maintenance, $vehicle, 'next_schedule');
});

it('rejects malformed stored recurrence without changing maintenance vehicle or audit history', function (
    array $recurrence,
    array $maintenanceAttributes,
    string $errorField,
) {
    $vehicle = r2c2Vehicle();
    $maintenance = r2c2Maintenance($vehicle, array_merge(
        $recurrence,
        $maintenanceAttributes,
    ));

    r2c2AssertRejectedWithoutMutation($maintenance, $vehicle, $errorField);
})->with([
    'unsupported frequency' => [['recurrence_frequency' => 'daily'], [], 'recurrence_frequency'],
    'missing interval' => [['recurrence_interval' => null], [], 'recurrence_interval'],
    'interval without frequency' => [['recurrence_frequency' => null], [], 'recurrence_frequency'],
    'unsupported interval' => [['recurrence_interval' => 2], [], 'recurrence_interval'],
    'weekly month-end preservation' => [['recurrence_preserve_month_end' => true], [], 'recurrence_preserve_month_end'],
    'month-end preservation without frequency' => [[
        'recurrence_frequency' => null,
        'recurrence_interval' => null,
        'recurrence_preserve_month_end' => true,
    ], [], 'recurrence_frequency'],
    'emergency recurrence' => [[], [
        'request_type' => 'Emergency',
        'priority' => 'Emergency',
    ], 'recurrence_enabled'],
    'non-preventive recurrence' => [[], [
        'maintenance_type' => 'Inspection',
    ], 'maintenance_type'],
]);

it('preserves manual and legacy completion behavior without calculating a date', function (
    array $maintenanceAttributes,
    ?string $expectedNextSchedule,
) {
    $maintenance = r2c2Maintenance(r2c2Vehicle(), array_merge([
        'recurrence_frequency' => null,
        'recurrence_interval' => null,
        'recurrence_preserve_month_end' => false,
    ], $maintenanceAttributes));
    $maintenanceCount = Maintenance::query()->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful();

    $maintenance->refresh();
    expect($maintenance->status)->toBe('Completed')
        ->and($maintenance->next_schedule?->toDateString())->toBe($expectedNextSchedule)
        ->and(Maintenance::query()->count())->toBe($maintenanceCount);
})->with([
    'legacy null recurrence' => [[], null],
    'manual next schedule' => [['next_schedule' => '2027-02-01'], '2027-02-01'],
    'emergency non-recurring' => [[
        'request_type' => 'Emergency',
        'priority' => 'Emergency',
    ], null],
    'non-preventive non-recurring' => [[
        'maintenance_type' => 'Corrective Maintenance',
    ], null],
]);

it('does not calculate recurrence when in-progress maintenance is cancelled', function () {
    $vehicle = r2c2Vehicle();
    $maintenance = r2c2Maintenance($vehicle);
    $auditCount = AuditLog::query()->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Cancelled',
        ])
        ->assertSuccessful();

    $maintenance->refresh();
    $vehicle->refresh();
    $audit = AuditLog::query()
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->where('action', 'Cancelled')
        ->sole();

    expect($maintenance)
        ->status->toBe('Cancelled')
        ->completion_date->toBeNull()
        ->next_schedule->toBeNull()
        ->recurrence_frequency->toBe('weekly')
        ->recurrence_interval->toBe(1)
        ->recurrence_preserve_month_end->toBeFalse()
        ->and($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready')
        ->and($audit->old_values)->toMatchArray(['status' => 'In Progress'])
        ->and($audit->new_values)->toMatchArray(['status' => 'Cancelled'])
        ->and(AuditLog::query()->count())->toBe($auditCount + 1);
});

it('rejects repeated completion without changing the calculated date or duplicating its audit', function () {
    $maintenance = r2c2Maintenance(r2c2Vehicle());
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    $this->actingAs($manager)
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertSuccessful();

    $completionAuditCount = AuditLog::query()
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->where('action', 'Completed')
        ->count();
    $auditCount = AuditLog::query()->count();
    $completedMaintenance = $maintenance->fresh()->getAttributes();
    $releasedVehicle = $maintenance->vehicle->fresh()->getAttributes();

    $this->postJson(route('maintenance.transition', $maintenance), [
        'status' => 'Completed',
    ])->assertUnprocessable();

    expect($maintenance->fresh()->getAttributes())->toBe($completedMaintenance)
        ->and($maintenance->vehicle->fresh()->getAttributes())->toBe($releasedVehicle)
        ->and($maintenance->fresh()->completion_date->toDateString())->toBe('2026-11-06')
        ->and($maintenance->fresh()->next_schedule->toDateString())->toBe('2026-11-13')
        ->and(AuditLog::query()
            ->where('auditable_type', Maintenance::class)
            ->where('auditable_id', $maintenance->id)
            ->where('action', 'Completed')
            ->count())->toBe($completionAuditCount)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('keeps completion behind the dedicated authorized lifecycle transition', function () {
    $vehicle = r2c2Vehicle();
    $maintenance = r2c2Maintenance($vehicle);
    $protectedMaintenance = $maintenance->fresh()->getAttributes();
    $protectedVehicle = $vehicle->fresh()->getAttributes();
    $auditCount = AuditLog::query()->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->putJson(route('maintenance.update', $maintenance), r2c2UpdatePayload(
            $maintenance,
            ['status' => 'Completed'],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');

    expect($maintenance->fresh()->getAttributes())->toBe($protectedMaintenance)
        ->and($vehicle->fresh()->getAttributes())->toBe($protectedVehicle)
        ->and(AuditLog::query()->count())->toBe($auditCount);

    $this->actingAs(User::factory()->create(['role' => 'driver']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertForbidden();

    expect($maintenance->fresh()->getAttributes())->toBe($protectedMaintenance)
        ->and($vehicle->fresh()->getAttributes())->toBe($protectedVehicle)
        ->and(AuditLog::query()->count())->toBe($auditCount);
});

it('rejects invalid scheduled-to-completed lifecycle transitions without calculating recurrence', function () {
    $vehicle = r2c2Vehicle([
        'status' => 'Available',
        'readiness_status' => 'ready',
    ]);
    $maintenance = r2c2Maintenance($vehicle, ['status' => 'Scheduled']);
    $auditCount = AuditLog::query()->count();

    $this->actingAs(User::factory()->create(['role' => 'fleet_manager']))
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ])
        ->assertUnprocessable();

    expect($maintenance->fresh())
        ->status->toBe('Scheduled')
        ->completion_date->toBeNull()
        ->next_schedule->toBeNull()
        ->and($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready')
        ->and(AuditLog::query()->count())->toBe($auditCount);
});
