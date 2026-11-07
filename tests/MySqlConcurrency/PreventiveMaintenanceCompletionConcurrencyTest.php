<?php

use App\Http\Controllers\MaintenanceController;
use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\FleetSetting;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\DatabaseBarrier;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;

$r2cCompletionSettingIds = [];

beforeEach(function () use (&$r2cCompletionSettingIds) {
    expect(config('fleet-notifications.occurrences.enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.after_commit_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.scheduled_recovery_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.historical_enabled'))->toBeFalse();

    $setting = FleetSetting::query()->create(['settings' => [
        'maintenance' => [
            'requireCost' => false,
            'overdueWarnDays' => 3,
            'serviceTypes' => [
                ['id' => 'preventive', 'name' => 'Preventive Maintenance', 'active' => true],
            ],
            'providers' => [[
                'id' => 'r2c4_workshop',
                'name' => 'R2C Concurrency Workshop',
                'type' => 'Workshop',
                'contractType' => 'In-house',
                'active' => true,
            ]],
        ],
        'notifications' => ['maintenanceDue' => true],
    ]]);

    $r2cCompletionSettingIds[] = $setting->id;
});

afterEach(function () use (&$r2cCompletionSettingIds) {
    FleetSetting::query()->whereKey($r2cCompletionSettingIds)->delete();
    $r2cCompletionSettingIds = [];
});

function r2cCompletionUser(): User
{
    return User::factory()->create([
        'email' => 'r2c-completion-'.str()->uuid().'@example.test',
        'role' => 'fleet_manager',
        'status' => 'active',
    ]);
}

function r2cCompletionVehicle(): Vehicle
{
    return Vehicle::query()->create([
        'plate_number' => 'R2C-'.str()->upper(str()->random(8)),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'R2C',
        'model' => 'Completion Harness',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Maintenance',
        'readiness_status' => 'maintenance',
    ]);
}

function r2cCompletionMaintenance(
    Vehicle $vehicle,
    array $attributes = []
): Maintenance {
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => 'R2C-MNT-'.str()->upper(str()->random(8)),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Synthetic recurring completion concurrency fixture',
        'maintenance_date' => now()->toDateString(),
        'completion_date' => null,
        'next_schedule' => null,
        'recurrence_frequency' => 'weekly',
        'recurrence_interval' => 1,
        'recurrence_preserve_month_end' => false,
        'technician' => 'R2C Concurrency Workshop',
        'priority' => 'Normal',
        'cost' => 0,
        'status' => 'In Progress',
    ], $attributes));
}

/**
 * Synchronizes workers immediately before their production Vehicle lock query.
 * This proves process overlap and observed query order, not InnoDB lock waiting.
 *
 * @param  array<string, array{action: string, payload: array<string, int|string|null>}>  $workers
 * @return array<string, array<string, int|string|null>>
 */
function runR2cPreLockSynchronizedWorkers(string $barrier, array $workers): array
{
    DatabaseBarrier::reset($barrier.'-vehicle-lock-ready');

    $participantCount = count($workers);
    $commands = [];

    foreach ($workers as $participant => $worker) {
        $commands[$participant] = HarnessEnvironment::workerCommand(
            base_path(),
            $worker['action'],
            array_merge($worker['payload'], [
                'barrier' => $barrier,
                'participant' => $participant,
                'participants' => $participantCount,
            ])
        );
    }

    $results = (new ConcurrentProcessRunner)->run($commands, 30);
    $decodedResults = collect($results)->mapWithKeys(function (
        array $result,
        string $participant
    ): array {
        $decoded = json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException('The maintenance completion worker returned invalid JSON.');
        }

        return [$participant => $decoded];
    });

    if ($decodedResults->pluck('connection_id')->unique()->count() !== $participantCount) {
        throw new RuntimeException('Maintenance completion workers did not use distinct MySQL sessions.');
    }

    return $decodedResults->all();
}

function r2cCompletedAuditCount(Maintenance $maintenance): int
{
    return AuditLog::query()
        ->where('module', 'Maintenance Management')
        ->where('action', 'Completed')
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->count();
}

function r2cLifecycleAuditCount(Maintenance $maintenance): int
{
    return AuditLog::query()
        ->where('module', 'Maintenance Management')
        ->where('auditable_type', Maintenance::class)
        ->where('auditable_id', $maintenance->id)
        ->whereIn('action', ['Started', 'Completed', 'Cancelled', 'Scheduled'])
        ->count();
}

function r2cNotificationCount(Maintenance $maintenance): int
{
    return FleetNotification::query()
        ->where('event_key', 'like', "maintenance_due:{$maintenance->id}:%")
        ->count();
}

it('preserves final state when recurring completion requests overlap at the pre-lock boundary', function () {
    $vehicle = r2cCompletionVehicle();
    $maintenance = r2cCompletionMaintenance($vehicle);
    $firstUser = r2cCompletionUser();
    $secondUser = r2cCompletionUser();
    $maintenanceRowsBefore = Maintenance::query()->count();
    $notificationRowsBefore = r2cNotificationCount($maintenance);
    $barrier = 'r2c-simultaneous-completion-'.str()->uuid();

    $results = runR2cPreLockSynchronizedWorkers($barrier, [
        'first' => [
            'action' => 'complete_maintenance',
            'payload' => [
                'maintenance_id' => $maintenance->id,
                'user_id' => $firstUser->id,
                'test_now' => '2026-11-07',
            ],
        ],
        'second' => [
            'action' => 'complete_maintenance',
            'payload' => [
                'maintenance_id' => $maintenance->id,
                'user_id' => $secondUser->id,
                'test_now' => '2026-11-07',
            ],
        ],
    ]);

    $maintenance->refresh();
    $vehicle->refresh();
    $expectedNextSchedule = CarbonImmutable::parse('2026-11-07')
        ->addWeek()
        ->toDateString();
    $outcomes = collect($results)->pluck('outcome')->sort()->values()->all();

    expect($outcomes)->toBe(['completed', 'lifecycle_rejected'])
        ->and(collect($results)->pluck('http_status')->sort()->values()->all())->toBe([200, 422])
        ->and(collect($results)->pluck('route_action')->unique()->all())
        ->toBe([MaintenanceController::class.'@transition'])
        ->and(collect($results)->pluck('lock_order')->every(
            fn (string $order): bool => str_starts_with($order, 'vehicles>maintenances')
        ))->toBeTrue()
        ->and($maintenance->status)->toBe('Completed')
        ->and($maintenance->completion_date?->toDateString())->toBe('2026-11-07')
        ->and($maintenance->next_schedule?->toDateString())->toBe($expectedNextSchedule)
        ->and($maintenance->recurrence_frequency)->toBe('weekly')
        ->and($maintenance->recurrence_interval)->toBe(1)
        ->and($maintenance->recurrence_preserve_month_end)->toBeFalse()
        ->and($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready')
        ->and(r2cCompletedAuditCount($maintenance))->toBe(1)
        ->and(r2cLifecycleAuditCount($maintenance))->toBe(1)
        ->and(r2cNotificationCount($maintenance))->toBe($notificationRowsBefore)
        ->and(Maintenance::query()->count())->toBe($maintenanceRowsBefore);
});

it('preserves completed recurrence as final-state safety coverage for a generic edit and completion overlap', function () {
    $vehicle = r2cCompletionVehicle();
    $maintenance = r2cCompletionMaintenance($vehicle);
    $originalDescription = $maintenance->description;
    $updatedDescription = 'Synthetic edit racing recurring completion';
    $maintenanceRowsBefore = Maintenance::query()->count();
    $notificationRowsBefore = r2cNotificationCount($maintenance);
    $barrier = 'r2c-stale-edit-completion-'.str()->uuid();

    $results = runR2cPreLockSynchronizedWorkers($barrier, [
        'completer' => [
            'action' => 'complete_maintenance',
            'payload' => [
                'maintenance_id' => $maintenance->id,
                'user_id' => r2cCompletionUser()->id,
                'test_now' => '2026-11-07',
            ],
        ],
        'editor' => [
            'action' => 'update_maintenance',
            'payload' => [
                'maintenance_id' => $maintenance->id,
                'user_id' => r2cCompletionUser()->id,
                'description' => $updatedDescription,
                'test_now' => '2026-11-07',
            ],
        ],
    ]);

    $maintenance->refresh();
    $vehicle->refresh();
    $expectedNextSchedule = CarbonImmutable::parse('2026-11-07')
        ->addWeek()
        ->toDateString();
    $outcomes = collect($results)->pluck('outcome');
    $editorOutcome = $results['editor']['outcome'];

    expect($results['completer']['outcome'])->toBe('completed')
        ->and($results['completer']['http_status'])->toBe(200)
        ->and($editorOutcome)->toBeIn(['updated', 'stale_edit_rejected'])
        ->and($results['editor']['http_status'])->toBe(
            $editorOutcome === 'updated' ? 200 : 422
        )
        ->and($outcomes)->toHaveCount(2)
        ->and(collect($results)->pluck('lock_order')->every(
            fn (string $order): bool => str_starts_with($order, 'vehicles>maintenances')
        ))->toBeTrue()
        ->and($maintenance->status)->toBe('Completed')
        ->and($maintenance->completion_date?->toDateString())->toBe('2026-11-07')
        ->and($maintenance->next_schedule?->toDateString())->toBe($expectedNextSchedule)
        ->and($maintenance->recurrence_frequency)->toBe('weekly')
        ->and($maintenance->recurrence_interval)->toBe(1)
        ->and($maintenance->recurrence_preserve_month_end)->toBeFalse()
        ->and($maintenance->description)->toBeIn([$originalDescription, $updatedDescription])
        ->and($vehicle->status)->toBe('Available')
        ->and($vehicle->readiness_status)->toBe('ready')
        ->and(r2cCompletedAuditCount($maintenance))->toBe(1)
        ->and(r2cLifecycleAuditCount($maintenance))->toBe(1)
        ->and(r2cNotificationCount($maintenance))->toBe($notificationRowsBefore)
        ->and(Maintenance::query()->count())->toBe($maintenanceRowsBefore);
});

it('rolls back simultaneous conflicting recurrence completions through the real route', function () {
    $vehicle = r2cCompletionVehicle();
    $conflictingNextSchedule = '2026-11-21';
    $maintenance = r2cCompletionMaintenance($vehicle, [
        'next_schedule' => $conflictingNextSchedule,
    ]);
    $maintenanceRowsBefore = Maintenance::query()->count();
    $notificationRowsBefore = r2cNotificationCount($maintenance);
    $barrier = 'r2c-conflict-rollback-'.str()->uuid();

    $results = runR2cPreLockSynchronizedWorkers($barrier, [
        'first' => [
            'action' => 'complete_maintenance',
            'payload' => [
                'maintenance_id' => $maintenance->id,
                'user_id' => r2cCompletionUser()->id,
                'test_now' => '2026-11-07',
            ],
        ],
        'second' => [
            'action' => 'complete_maintenance',
            'payload' => [
                'maintenance_id' => $maintenance->id,
                'user_id' => r2cCompletionUser()->id,
                'test_now' => '2026-11-07',
            ],
        ],
    ]);

    $maintenance->refresh();
    $vehicle->refresh();

    expect(collect($results)->pluck('outcome')->all())
        ->toBe(['recurrence_conflict_rejected', 'recurrence_conflict_rejected'])
        ->and(collect($results)->pluck('http_status')->unique()->all())->toBe([422])
        ->and(collect($results)->pluck('lock_order')->every(
            fn (string $order): bool => str_starts_with($order, 'vehicles>maintenances')
        ))->toBeTrue()
        ->and($maintenance->status)->toBe('In Progress')
        ->and($maintenance->completion_date)->toBeNull()
        ->and($maintenance->next_schedule?->toDateString())->toBe($conflictingNextSchedule)
        ->and($maintenance->recurrence_frequency)->toBe('weekly')
        ->and($maintenance->recurrence_interval)->toBe(1)
        ->and($maintenance->recurrence_preserve_month_end)->toBeFalse()
        ->and($vehicle->status)->toBe('Maintenance')
        ->and($vehicle->readiness_status)->toBe('maintenance')
        ->and(r2cCompletedAuditCount($maintenance))->toBe(0)
        ->and(r2cLifecycleAuditCount($maintenance))->toBe(0)
        ->and(r2cNotificationCount($maintenance))->toBe($notificationRowsBefore)
        ->and(Maintenance::query()->count())->toBe($maintenanceRowsBefore);
});
