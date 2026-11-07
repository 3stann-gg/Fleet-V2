<?php

use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetNotificationCheckService;
use App\Services\MaintenanceScheduleResolver;
use Carbon\Carbon;

function r2c3Vehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('R2C3-####'),
        'vehicle_type' => 'Service Vehicle',
        'department' => 'Operations',
        'brand' => 'Recurrence',
        'model' => 'Reminder',
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

function r2c3Maintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('R2C3-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Preventive recurrence notification test',
        'maintenance_date' => '2026-10-01',
        'technician' => "R2C3 Technician \u{2014} Technician \u{2014} In-house",
        'priority' => 'Normal',
        'odometer' => 100,
        'cost' => 100,
        'status' => 'In Progress',
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
    ], $attributes));
}

function r2c3ConfigureNotifications(int $warningDays = 7, bool $enabled = true): void
{
    FleetSetting::query()->create(['settings' => [
        'maintenance' => [
            'requireCost' => true,
            'overdueWarnDays' => $warningDays,
            'serviceTypes' => [
                ['id' => 'preventive', 'name' => 'Preventive Maintenance', 'active' => true],
                ['id' => 'corrective', 'name' => 'Corrective Maintenance', 'active' => true],
            ],
            'providers' => [[
                'id' => 'r2c3_technician',
                'name' => 'R2C3 Technician',
                'type' => 'Technician',
                'contractType' => 'In-house',
                'active' => true,
            ]],
        ],
        'notifications' => ['maintenanceDue' => $enabled],
    ]]);
}

function r2c3EventKey(Maintenance $maintenance): string
{
    return 'maintenance_due:'.$maintenance->id.':'.$maintenance->next_schedule->toDateString();
}

function r2c3Complete(Maintenance $maintenance, User $user)
{
    return test()->actingAs($user)
        ->postJson(route('maintenance.transition', $maintenance), [
            'status' => 'Completed',
        ]);
}

function r2c3RunReminderCheck(User $user): void
{
    auth()->login($user);
    app(FleetNotificationCheckService::class)->check();
}

function r2c3CompletionAuditCount(Maintenance $maintenance): int
{
    return AuditLog::query()
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->where('action', 'Completed')
        ->count();
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 11, 6, 10, 0, 0, config('app.timezone')));
    config([
        'fleet-notifications.occurrences.enabled' => false,
        'fleet-notifications.reconciliation.after_commit_enabled' => false,
        'fleet-notifications.reconciliation.scheduled_recovery_enabled' => false,
        'fleet-notifications.reconciliation.historical_enabled' => false,
    ]);
    r2c3ConfigureNotifications();
});

afterEach(function () {
    Carbon::setTestNow();
    config([
        'fleet-notifications.occurrences.enabled' => false,
        'fleet-notifications.reconciliation.after_commit_enabled' => false,
        'fleet-notifications.reconciliation.scheduled_recovery_enabled' => false,
        'fleet-notifications.reconciliation.historical_enabled' => false,
    ]);
});

it('hands a persisted weekly recurrence schedule to the existing reminder flow', function () {
    $vehicle = r2c3Vehicle();
    $maintenance = r2c3Maintenance($vehicle);
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceCount = Maintenance::query()->count();
    $recurrence = $maintenance->only([
        'recurrence_frequency', 'recurrence_interval', 'recurrence_preserve_month_end',
    ]);

    r2c3Complete($maintenance, $manager)->assertSuccessful();

    $maintenance->refresh();
    expect($maintenance)
        ->status->toBe('Completed')
        ->completion_date->toDateString()->toBe('2026-11-06')
        ->next_schedule->toDateString()->toBe('2026-11-13')
        ->and($maintenance->only(array_keys($recurrence)))->toBe($recurrence)
        ->and(Maintenance::query()->count())->toBe($maintenanceCount)
        ->and(FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->count())->toBe(1);

    r2c3RunReminderCheck($manager);

    $notification = FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->sole();
    expect($notification)
        ->user_id->toBe($manager->id)
        ->title->toBe('Maintenance Due Soon')
        ->status->toBe('Unread')
        ->and($notification->message)->toContain('next service after maintenance')
        ->and($maintenance->fresh()->next_schedule->toDateString())->toBe('2026-11-13')
        ->and($vehicle->fresh()->status)->toBe('Available')
        ->and($vehicle->fresh()->readiness_status)->toBe('ready')
        ->and(r2c3CompletionAuditCount($maintenance))->toBe(1);
});

it('persists a monthly recurrence schedule for later reminder checks without creating it prematurely', function () {
    $vehicle = r2c3Vehicle();
    $maintenance = r2c3Maintenance($vehicle, [
        'recurrence_frequency' => 'monthly',
        'recurrence_preserve_month_end' => true,
    ]);
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    r2c3Complete($maintenance, $manager)->assertSuccessful();

    $maintenance->refresh();
    $context = app(MaintenanceScheduleResolver::class)->resolve($vehicle->fresh());
    r2c3RunReminderCheck($manager);

    expect($maintenance)
        ->status->toBe('Completed')
        ->next_schedule->toDateString()->toBe('2026-12-31')
        ->recurrence_frequency->toBe('monthly')
        ->recurrence_preserve_month_end->toBeTrue()
        ->and($context['maintenance_id'])->toBe($maintenance->id)
        ->and($context['next_schedule'])->toBe('2026-12-31')
        ->and(FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->exists())->toBeFalse();
});

it('applies the existing warning-window classification to the calculated schedule', function (
    string $completionDate,
    int $warningDays,
    string $expectedNextSchedule,
    ?string $expectedTitle,
    ?string $messageFragment,
) {
    r2c3ConfigureNotifications($warningDays);
    $maintenance = r2c3Maintenance(r2c3Vehicle(), ['completion_date' => $completionDate]);
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    r2c3Complete($maintenance, $manager)->assertSuccessful();
    $maintenance->refresh();
    r2c3RunReminderCheck($manager);

    expect($maintenance->next_schedule->toDateString())->toBe($expectedNextSchedule);
    $notification = FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->first();

    if ($expectedTitle === null) {
        expect($notification)->toBeNull();

        return;
    }

    expect($notification)
        ->title->toBe($expectedTitle)
        ->status->toBe('Unread')
        ->and($notification->message)->toContain($messageFragment)
        ->and($notification->message)->toContain('next service after maintenance');
})->with([
    'outside warning window' => ['2026-11-06', 6, '2026-11-13', null, null],
    'inclusive warning boundary' => ['2026-11-06', 7, '2026-11-13', 'Maintenance Due Soon', 'is due in 7 days.'],
    'due today' => ['2026-10-30', 7, '2026-11-06', 'Maintenance Due Soon', 'is due today.'],
    'already overdue' => ['2026-10-29', 7, '2026-11-05', 'Maintenance Overdue', 'is overdue by 1 day.'],
]);

it('formats immediate completion reminders with calendar-day wording', function (
    string $completionDate,
    string $expectedTitle,
    string $messageFragment,
) {
    $maintenance = r2c3Maintenance(r2c3Vehicle(), ['completion_date' => $completionDate]);
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    r2c3Complete($maintenance, $manager)->assertSuccessful();
    $maintenance->refresh();

    $notification = FleetNotification::query()
        ->where('event_key', r2c3EventKey($maintenance))
        ->sole();
    expect($notification)
        ->title->toBe($expectedTitle)
        ->and($notification->message)->toContain($messageFragment)
        ->and($notification->message)->not->toContain('next service after maintenance');
})->with([
    'due today' => ['2026-10-30', 'Maintenance Due Soon', 'is due today.'],
    'overdue by one day' => ['2026-10-29', 'Maintenance Overdue', 'is overdue by 1 day.'],
    'overdue by multiple days' => ['2026-10-28', 'Maintenance Overdue', 'is overdue by 2 days.'],
]);

it('uses the highest id completed maintenance when completion dates tie', function () {
    $vehicle = r2c3Vehicle();
    $older = r2c3Maintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => '2026-10-30',
        'next_schedule' => '2026-11-06',
        'recurrence_frequency' => null,
        'recurrence_interval' => null,
    ]);
    $current = r2c3Maintenance($vehicle, ['completion_date' => '2026-10-30']);
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    r2c3Complete($current, $manager)->assertSuccessful();
    $current->refresh();
    r2c3RunReminderCheck($manager);

    $context = app(MaintenanceScheduleResolver::class)->resolve($vehicle->fresh());
    expect($current->id)->toBeGreaterThan($older->id)
        ->and($context['maintenance_id'])->toBe($current->id)
        ->and($context['next_schedule'])->toBe('2026-11-06')
        ->and(FleetNotification::query()->where('event_key', r2c3EventKey($current))->count())->toBe(1)
        ->and(FleetNotification::query()->where('event_key', r2c3EventKey($older))->exists())->toBeFalse();
});

it('deduplicates repeated reminder checks without reopening read state or rewriting completion', function () {
    $maintenance = r2c3Maintenance(r2c3Vehicle());
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceCount = Maintenance::query()->count();

    r2c3Complete($maintenance, $manager)->assertSuccessful();
    $maintenance->refresh();
    $completedAttributes = $maintenance->getAttributes();
    $auditCount = r2c3CompletionAuditCount($maintenance);
    FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->sole()->update(['status' => 'Read']);

    r2c3RunReminderCheck($manager);
    r2c3RunReminderCheck($manager);

    $notification = FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->sole();
    expect($notification->status)->toBe('Read')
        ->and(FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->count())->toBe(1)
        ->and($maintenance->fresh()->getAttributes())->toBe($completedAttributes)
        ->and(Maintenance::query()->count())->toBe($maintenanceCount)
        ->and(r2c3CompletionAuditCount($maintenance))->toBe($auditCount);
});

it('preserves manual and ineligible maintenance notification behavior', function (
    array $attributes,
    string $targetStatus,
    ?string $expectedNextSchedule,
    bool $expectsNotification,
) {
    $maintenance = r2c3Maintenance(r2c3Vehicle(), $attributes);
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceCount = Maintenance::query()->count();
    $recurrence = $maintenance->only([
        'recurrence_frequency', 'recurrence_interval', 'recurrence_preserve_month_end',
    ]);

    $this->actingAs($manager)
        ->postJson(route('maintenance.transition', $maintenance), ['status' => $targetStatus])
        ->assertSuccessful();

    $maintenance->refresh();
    r2c3RunReminderCheck($manager);

    expect($maintenance->status)->toBe($targetStatus)
        ->and($maintenance->next_schedule?->toDateString())->toBe($expectedNextSchedule)
        ->and($maintenance->only(array_keys($recurrence)))->toBe($recurrence)
        ->and(Maintenance::query()->count())->toBe($maintenanceCount)
        ->and(FleetNotification::query()->count())->toBe($expectsNotification ? 1 : 0);
})->with([
    'manual future-service date remains eligible' => [[
        'recurrence_frequency' => null, 'recurrence_interval' => null, 'next_schedule' => '2026-11-06',
    ], 'Completed', '2026-11-06', true],
    'legacy non-recurring maintenance' => [[
        'recurrence_frequency' => null, 'recurrence_interval' => null,
    ], 'Completed', null, false],
    'emergency maintenance' => [[
        'request_type' => 'Emergency', 'priority' => 'Emergency',
        'recurrence_frequency' => null, 'recurrence_interval' => null,
    ], 'Completed', null, false],
    'non-preventive maintenance' => [[
        'maintenance_type' => 'Corrective Maintenance',
        'recurrence_frequency' => null, 'recurrence_interval' => null,
    ], 'Completed', null, false],
    'cancelled recurring maintenance' => [[], 'Cancelled', null, false],
]);

it('keeps lifecycle rollout flags disabled while using legacy keyed notifications', function () {
    $maintenance = r2c3Maintenance(r2c3Vehicle());
    $manager = User::factory()->create(['role' => 'fleet_manager']);

    expect(config('fleet-notifications.occurrences.enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.after_commit_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.scheduled_recovery_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.historical_enabled'))->toBeFalse();

    r2c3Complete($maintenance, $manager)->assertSuccessful();
    $maintenance->refresh();
    r2c3RunReminderCheck($manager);

    $notification = FleetNotification::query()->where('event_key', r2c3EventKey($maintenance))->sole();
    expect($notification->active_event_key)->toBeNull()
        ->and($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull()
        ->and(config('fleet-notifications.occurrences.enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.after_commit_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.scheduled_recovery_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.historical_enabled'))->toBeFalse();
});

it('documents that notification failure happens after the completion transaction commits', function () {
    $vehicle = r2c3Vehicle();
    $maintenance = r2c3Maintenance($vehicle);
    $manager = User::factory()->create(['role' => 'fleet_manager']);
    $maintenanceCount = Maintenance::query()->count();
    $throwOnNotificationCreate = true;

    FleetNotification::creating(function () use (&$throwOnNotificationCreate): void {
        if ($throwOnNotificationCreate) {
            throw new RuntimeException('Synthetic R2C-3 notification failure.');
        }
    });

    try {
        $response = r2c3Complete($maintenance, $manager);
    } finally {
        $throwOnNotificationCreate = false;
    }

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Synthetic R2C-3 notification failure.');

    $maintenance->refresh();
    $vehicle->refresh();
    expect($maintenance)
        ->status->toBe('Completed')
        ->completion_date->toDateString()->toBe('2026-11-06')
        ->next_schedule->toDateString()->toBe('2026-11-13')
        ->and($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready')
        ->and(Maintenance::query()->count())->toBe($maintenanceCount)
        ->and(FleetNotification::query()->count())->toBe(0)
        ->and(r2c3CompletionAuditCount($maintenance))->toBe(1);

    $completedAttributes = $maintenance->getAttributes();
    $releasedVehicleAttributes = $vehicle->getAttributes();
    $auditCount = r2c3CompletionAuditCount($maintenance);

    $this->actingAs($manager)
        ->postJson(route('maintenance.transition', $maintenance), ['status' => 'Completed'])
        ->assertUnprocessable();

    expect($maintenance->fresh()->getAttributes())->toBe($completedAttributes)
        ->and($vehicle->fresh()->getAttributes())->toBe($releasedVehicleAttributes)
        ->and(r2c3CompletionAuditCount($maintenance))->toBe($auditCount)
        ->and(FleetNotification::query()->count())->toBe(0);
});
