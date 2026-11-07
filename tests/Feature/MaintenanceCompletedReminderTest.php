<?php

use App\Models\FleetNotification;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetNotificationCheckService;
use Carbon\Carbon;

function completedReminderVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('MCR-####'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Reminder',
        'model' => 'Vehicle',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ], $attributes));
}

function completedReminderMaintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('MCR-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Future service reminder test',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => now()->addDays(3)->toDateString(),
        'cost' => 0,
        'status' => 'Completed',
    ], $attributes));
}

function completedReminderSettings(int $warningDays = 3, bool $enabled = true): FleetSetting
{
    return FleetSetting::query()->create([
        'settings' => [
            'maintenance' => ['overdueWarnDays' => $warningDays],
            'notifications' => ['maintenanceDue' => $enabled],
        ],
    ]);
}

function runCompletedReminderCheck(User $user): void
{
    auth()->login($user);
    app(FleetNotificationCheckService::class)->check();
}

function completedReminderEventKey(Maintenance $maintenance): string
{
    return 'maintenance_due:'.$maintenance->id.':'.$maintenance->next_schedule->toDateString();
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-30 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('creates no future service reminder without completed maintenance', function () {
    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(0);
});

it('ignores a completed next schedule outside the configured reminder window', function () {
    completedReminderSettings();
    completedReminderMaintenance(completedReminderVehicle(), [
        'next_schedule' => now()->addDays(4)->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(0);
});

it('creates a reminder at the configured lead time boundary', function () {
    completedReminderSettings();
    $maintenance = completedReminderMaintenance(completedReminderVehicle());

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    $notification = FleetNotification::query()->sole();
    expect($notification->event_key)->toBe(completedReminderEventKey($maintenance))
        ->and($notification->title)->toBe('Maintenance Due Soon')
        ->and($notification->message)->toContain('next service after maintenance')
        ->and($notification->message)->toContain('is due in 3 days.');
});

it('creates an overdue reminder for the authoritative completed schedule', function () {
    completedReminderSettings();
    $maintenance = completedReminderMaintenance(completedReminderVehicle(), [
        'next_schedule' => now()->subDays(2)->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    $notification = FleetNotification::query()->sole();
    expect($notification->event_key)->toBe(completedReminderEventKey($maintenance))
        ->and($notification->title)->toBe('Maintenance Overdue')
        ->and($notification->message)->toContain('is overdue by 2 days.');
});

it('reminds only for the latest completed maintenance per vehicle', function () {
    completedReminderSettings();
    $vehicle = completedReminderVehicle();
    $older = completedReminderMaintenance($vehicle, [
        'completion_date' => now()->subMonths(2)->toDateString(),
        'next_schedule' => now()->subDay()->toDateString(),
    ]);
    $latest = completedReminderMaintenance($vehicle, [
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => now()->addDay()->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(1)
        ->and(FleetNotification::query()->value('event_key'))->toBe(completedReminderEventKey($latest))
        ->and(FleetNotification::query()->where('event_key', completedReminderEventKey($older))->exists())
        ->toBeFalse();
});

it('does not revive an older schedule when the latest completed next schedule is null', function () {
    completedReminderSettings();
    $vehicle = completedReminderVehicle();
    completedReminderMaintenance($vehicle, [
        'completion_date' => now()->subMonths(2)->toDateString(),
        'next_schedule' => now()->subDay()->toDateString(),
    ]);
    completedReminderMaintenance($vehicle, [
        'completion_date' => now()->subMonth()->toDateString(),
        'next_schedule' => null,
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(0);
});

it('uses the highest id when completed records share a completion date', function () {
    completedReminderSettings();
    $vehicle = completedReminderVehicle();
    $completionDate = now()->subMonth()->toDateString();
    $lowerId = completedReminderMaintenance($vehicle, [
        'completion_date' => $completionDate,
        'next_schedule' => now()->subDay()->toDateString(),
    ]);
    $higherId = completedReminderMaintenance($vehicle, [
        'completion_date' => $completionDate,
        'next_schedule' => now()->addDay()->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(1)
        ->and(FleetNotification::query()->value('event_key'))->toBe(completedReminderEventKey($higherId))
        ->and(FleetNotification::query()->where('event_key', completedReminderEventKey($lowerId))->exists())
        ->toBeFalse();
});

it('preserves archived completed maintenance eligibility', function () {
    completedReminderSettings();
    $maintenance = completedReminderMaintenance(completedReminderVehicle(), [
        'archived_at' => now(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->value('event_key'))
        ->toBe(completedReminderEventKey($maintenance));
});

it('preserves historical null completion date selection behavior', function () {
    completedReminderSettings();
    $vehicle = completedReminderVehicle();
    completedReminderMaintenance($vehicle, [
        'completion_date' => null,
        'next_schedule' => now()->subDay()->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(0);
});

it('retains existing active maintenance reminder behavior', function (string $status) {
    completedReminderSettings();
    $maintenance = completedReminderMaintenance(completedReminderVehicle(), [
        'status' => $status,
        'completion_date' => null,
        'maintenance_date' => now()->toDateString(),
        'next_schedule' => now()->addDay()->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    $notification = FleetNotification::query()->sole();
    expect($notification->event_key)->toBe(completedReminderEventKey($maintenance))
        ->and($notification->message)->toContain("maintenance {$maintenance->maintenance_number} is due in 1 day.")
        ->and($notification->message)->not->toContain('next service after maintenance');
})->with(['Scheduled', 'In Progress']);

it('deduplicates repeated checker execution for the same user and event', function () {
    completedReminderSettings();
    completedReminderMaintenance(completedReminderVehicle());
    $user = User::factory()->create(['role' => 'fleet_manager']);

    runCompletedReminderCheck($user);
    runCompletedReminderCheck($user);

    expect(FleetNotification::query()->count())->toBe(1);
});

it('does not create reminders when maintenance notifications are disabled', function () {
    completedReminderSettings(enabled: false);
    completedReminderMaintenance(completedReminderVehicle());

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(0);
});

it('does not expose maintenance reminders to a user without module access', function () {
    completedReminderSettings();
    completedReminderMaintenance(completedReminderVehicle());

    runCompletedReminderCheck(User::factory()->create(['role' => 'department_head']));

    expect(FleetNotification::query()->count())->toBe(0);
});

it('reuses an existing one off notification without reopening its read state', function () {
    completedReminderSettings();
    $maintenance = completedReminderMaintenance(completedReminderVehicle());
    $user = User::factory()->create(['role' => 'fleet_manager']);
    FleetNotification::query()->create([
        'user_id' => $user->id,
        'title' => 'Maintenance Due Soon',
        'message' => 'Existing one-off reminder.',
        'status' => 'Read',
        'event_key' => completedReminderEventKey($maintenance),
        'link' => route('maintenance'),
    ]);

    runCompletedReminderCheck($user);

    $notification = FleetNotification::query()->sole();
    expect($notification->status)->toBe('Read')
        ->and($notification->message)->toContain('next service after maintenance')
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('deduplicates the same event independently for each eligible user', function () {
    completedReminderSettings();
    $maintenance = completedReminderMaintenance(completedReminderVehicle());
    $firstUser = User::factory()->create(['role' => 'fleet_manager']);
    $secondUser = User::factory()->create(['role' => 'maintenance']);

    runCompletedReminderCheck($firstUser);
    runCompletedReminderCheck($secondUser);

    expect(FleetNotification::query()
        ->where('event_key', completedReminderEventKey($maintenance))
        ->count())->toBe(2)
        ->and(FleetNotification::query()->distinct()->count('user_id'))->toBe(2);
});

it('preserves the backend warning day clamp and inclusive boundary', function () {
    completedReminderSettings(warningDays: 0);
    $withinClamp = completedReminderMaintenance(completedReminderVehicle(), [
        'next_schedule' => now()->addDay()->toDateString(),
    ]);
    completedReminderMaintenance(completedReminderVehicle(), [
        'next_schedule' => now()->addDays(2)->toDateString(),
    ]);

    runCompletedReminderCheck(User::factory()->create(['role' => 'fleet_manager']));

    expect(FleetNotification::query()->count())->toBe(1)
        ->and(FleetNotification::query()->value('event_key'))->toBe(completedReminderEventKey($withinClamp));
});
