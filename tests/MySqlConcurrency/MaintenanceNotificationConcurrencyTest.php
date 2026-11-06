<?php

use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use Tests\MySqlConcurrency\Support\AuditFailureConstraint;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\DatabaseBarrier;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;

function concurrencyUser(): User
{
    return User::factory()->create([
        'email' => 'concurrency-'.str()->uuid().'@example.test',
        'role' => 'fleet_manager',
        'status' => 'active',
    ]);
}

function concurrencyVehicle(): Vehicle
{
    return Vehicle::query()->create([
        'plate_number' => 'CC-'.str()->upper(str()->random(8)),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Concurrency',
        'model' => 'Harness',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ]);
}

function concurrencyMaintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => 'CC-MNT-'.str()->upper(str()->random(8)),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'MySQL concurrency test maintenance',
        'maintenance_date' => '2026-10-01',
        'completion_date' => null,
        'next_schedule' => '2026-11-01',
        'cost' => 0,
        'status' => 'Scheduled',
    ], $attributes));
}

function concurrencyEventKey(Maintenance $maintenance, ?string $date = null): string
{
    return 'maintenance_due:'.$maintenance->id.':'.($date ?? $maintenance->next_schedule?->toDateString());
}

function concurrencyNotification(User $user, string $eventKey, string $status = 'Unread'): FleetNotification
{
    return FleetNotification::query()->create([
        'user_id' => $user->id,
        'title' => 'Maintenance Due Soon',
        'message' => 'Concurrent maintenance reminder.',
        'status' => $status,
        'event_key' => $eventKey,
        'link' => '/maintenance',
    ]);
}

/**
 * @param  array<string, array{action: string, payload: array<string, int|string|null>}>  $workers
 * @return array<string, array<string, int|string|null>>
 */
function runConcurrencyWorkers(string $barrier, array $workers): array
{
    foreach (['start', 'evaluated', 'mutated', 'current-vehicle-locked'] as $phase) {
        DatabaseBarrier::reset($barrier.'-'.$phase);
    }

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

    $results = (new ConcurrentProcessRunner)->run($commands, 20);

    $decodedResults = collect($results)->mapWithKeys(function (array $result, string $participant): array {
        $decoded = json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR);

        return [$participant => $decoded];
    });

    if ($decodedResults->pluck('connection_id')->unique()->count() !== $participantCount) {
        throw new RuntimeException('Concurrency workers did not use distinct MySQL sessions.');
    }

    return $decodedResults->all();
}

it('resolves one stale maintenance event exactly once across concurrent reconciliation workers', function () {
    $maintenance = concurrencyMaintenance(concurrencyVehicle());
    $eventKey = concurrencyEventKey($maintenance);
    $notification = concurrencyNotification(concurrencyUser(), $eventKey);
    $maintenance->update(['next_schedule' => '2026-11-08']);
    $barrier = 'reconcile-once-'.str()->uuid();

    $results = runConcurrencyWorkers($barrier, [
        'first' => ['action' => 'reconcile', 'payload' => ['event_key' => $eventKey]],
        'second' => ['action' => 'reconcile', 'payload' => ['event_key' => $eventKey]],
    ]);

    expect(collect($results)->sum('resolved_count'))->toBe(1)
        ->and($notification->fresh()->resolved_at)->not->toBeNull()
        ->and($notification->fresh()->status)->toBe('Unread')
        ->and(AuditLog::query()
            ->where('action', 'Notification Resolved')
            ->where('auditable_id', $notification->id)
            ->count())->toBe(1);
});

it('preserves an event restored during authority evaluation', function () {
    $maintenance = concurrencyMaintenance(concurrencyVehicle());
    $originalDate = $maintenance->next_schedule->toDateString();
    $eventKey = concurrencyEventKey($maintenance);
    $notification = concurrencyNotification(concurrencyUser(), $eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $maintenance->update(['next_schedule' => '2026-11-08']);
    $barrier = 'restore-authority-'.str()->uuid();

    runConcurrencyWorkers($barrier, [
        'reconciler' => [
            'action' => 'reconcile_after_authority_barrier',
            'payload' => ['event_key' => $eventKey],
        ],
        'restorer' => [
            'action' => 'mutate_after_authority_barrier',
            'payload' => [
                'mutation' => 'update_schedule',
                'maintenance_id' => $maintenance->id,
                'next_schedule' => $originalDate,
            ],
        ],
    ]);

    expect($maintenance->fresh()->next_schedule?->toDateString())->toBe($originalDate)
        ->and($notification->fresh()->resolved_at)->toBeNull()
        ->and($notification->fresh()->active_event_key)->toBe($eventKey);
})->group('known-production-race');

it('selects a newer completed maintenance created during reconciliation', function () {
    $vehicle = concurrencyVehicle();
    $older = concurrencyMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => '2026-10-01',
    ]);
    $eventKey = concurrencyEventKey($older);
    $notification = concurrencyNotification(concurrencyUser(), $eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $barrier = 'new-completion-'.str()->uuid();

    runConcurrencyWorkers($barrier, [
        'reconciler' => [
            'action' => 'reconcile_after_authority_barrier',
            'payload' => ['event_key' => $eventKey],
        ],
        'completer' => [
            'action' => 'mutate_after_authority_barrier',
            'payload' => [
                'mutation' => 'insert_completed',
                'maintenance_number' => 'CC-MNT-'.str()->upper(str()->random(8)),
                'vehicle_id' => $vehicle->id,
                'maintenance_date' => '2026-10-15',
                'completion_date' => '2026-10-20',
                'next_schedule' => '2026-12-01',
            ],
        ],
    ]);

    expect($notification->fresh()->resolution_reason)->toBe('superseded_by_newer_completion')
        ->and($notification->fresh()->active_event_key)->toBeNull();
})->group('known-production-race');

it('deduplicates concurrent exact-key notification creation', function () {
    $user = concurrencyUser();
    $maintenance = concurrencyMaintenance(concurrencyVehicle());
    $eventKey = concurrencyEventKey($maintenance);
    $barrier = 'create-once-'.str()->uuid();

    $results = runConcurrencyWorkers($barrier, [
        'first' => [
            'action' => 'create_unique_notification',
            'payload' => ['user_id' => $user->id, 'event_key' => $eventKey],
        ],
        'second' => [
            'action' => 'create_unique_notification',
            'payload' => ['user_id' => $user->id, 'event_key' => $eventKey],
        ],
    ]);

    expect(FleetNotification::query()
        ->where('user_id', $user->id)
        ->where('event_key', $eventKey)
        ->count())->toBe(1)
        ->and(collect($results)->pluck('notification_id')->unique())->toHaveCount(1)
        ->and(FleetNotification::query()
            ->where('user_id', $user->id)
            ->where('event_key', $eventKey)
            ->value('status'))->toBe('Unread');
});

it('allows only one same-user active event key assignment', function () {
    $user = concurrencyUser();
    $first = concurrencyNotification($user, 'maintenance_due:900001:2026-12-01');
    $second = concurrencyNotification($user, 'maintenance_due:900002:2026-12-01');
    $activeIdentity = 'maintenance_due:900000:2026-12-01';
    $barrier = 'active-collision-'.str()->uuid();

    $results = runConcurrencyWorkers($barrier, [
        'first' => [
            'action' => 'assign_active_identity_directly',
            'payload' => ['notification_id' => $first->id, 'event_key' => $activeIdentity],
        ],
        'second' => [
            'action' => 'assign_active_identity_directly',
            'payload' => ['notification_id' => $second->id, 'event_key' => $activeIdentity],
        ],
    ]);

    expect(collect($results)->where('outcome', 'assigned'))->toHaveCount(1)
        ->and(collect($results)->where('outcome', 'collision'))->toHaveCount(1)
        ->and(FleetNotification::query()
            ->where('user_id', $user->id)
            ->where('active_event_key', $activeIdentity)
            ->count())->toBe(1);
});

it('allows different users to hold the same active event identity', function () {
    $eventKey = 'maintenance_due:900003:2026-12-02';
    $first = concurrencyNotification(concurrencyUser(), $eventKey);
    $second = concurrencyNotification(concurrencyUser(), $eventKey);
    $barrier = 'active-cross-user-'.str()->uuid();

    $results = runConcurrencyWorkers($barrier, [
        'first' => [
            'action' => 'assign_active_event',
            'payload' => ['notification_id' => $first->id, 'event_key' => $eventKey],
        ],
        'second' => [
            'action' => 'assign_active_event',
            'payload' => ['notification_id' => $second->id, 'event_key' => $eventKey],
        ],
    ]);

    expect(collect($results)->where('outcome', 'assigned'))->toHaveCount(2)
        ->and($first->fresh()->active_event_key)->toBe($eventKey)
        ->and($second->fresh()->active_event_key)->toBe($eventKey);
});

it('handles opposite vehicle maintenance reassignments without an unbounded deadlock', function () {
    $firstVehicle = concurrencyVehicle();
    $secondVehicle = concurrencyVehicle();
    $firstMaintenance = concurrencyMaintenance($firstVehicle);
    $secondMaintenance = concurrencyMaintenance($secondVehicle);
    $barrier = 'opposite-reassignment-'.str()->uuid();

    $results = runConcurrencyWorkers($barrier, [
        'first' => [
            'action' => 'opposite_vehicle_reassignment',
            'payload' => [
                'maintenance_id' => $firstMaintenance->id,
                'current_vehicle_id' => $firstVehicle->id,
                'new_vehicle_id' => $secondVehicle->id,
            ],
        ],
        'second' => [
            'action' => 'opposite_vehicle_reassignment',
            'payload' => [
                'maintenance_id' => $secondMaintenance->id,
                'current_vehicle_id' => $secondVehicle->id,
                'new_vehicle_id' => $firstVehicle->id,
            ],
        ],
    ]);

    expect(collect($results)->where('outcome', 'committed'))->toHaveCount(2)
        ->and($firstMaintenance->fresh()->vehicle_id)->toBe($secondVehicle->id)
        ->and($secondMaintenance->fresh()->vehicle_id)->toBe($firstVehicle->id);
})->group('known-production-race');

it('preserves acknowledgement when mark-all-read overlaps reconciliation', function () {
    $maintenance = concurrencyMaintenance(concurrencyVehicle());
    $eventKey = concurrencyEventKey($maintenance);
    $notification = concurrencyNotification(concurrencyUser(), $eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $maintenance->update(['next_schedule' => '2026-11-08']);
    $barrier = 'read-resolution-'.str()->uuid();

    runConcurrencyWorkers($barrier, [
        'reconciler' => [
            'action' => 'reconcile_after_authority_barrier',
            'payload' => ['event_key' => $eventKey],
        ],
        'reader' => [
            'action' => 'mutate_after_authority_barrier',
            'payload' => [
                'mutation' => 'mark_read',
                'notification_id' => $notification->id,
            ],
        ],
    ]);

    expect($notification->fresh()->status)->toBe('Read')
        ->and($notification->fresh()->resolved_at)->not->toBeNull()
        ->and($notification->fresh()->active_event_key)->toBeNull();
});

it('rolls back every resolution when a concurrent audit insert fails', function () {
    $maintenance = concurrencyMaintenance(concurrencyVehicle());
    $eventKey = concurrencyEventKey($maintenance);
    $notification = concurrencyNotification(concurrencyUser(), $eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $maintenance->update(['next_schedule' => '2026-11-08']);
    $barrier = 'audit-rollback-'.str()->uuid();
    $failureConstraint = new AuditFailureConstraint;
    $constraintName = $failureConstraint->install($notification->id);

    try {
        $results = runConcurrencyWorkers($barrier, [
            'first' => [
                'action' => 'reconcile',
                'payload' => ['event_key' => $eventKey, 'expect_query_failure' => '1'],
            ],
            'second' => [
                'action' => 'reconcile',
                'payload' => ['event_key' => $eventKey, 'expect_query_failure' => '1'],
            ],
        ]);
    } finally {
        $failureConstraint->remove($constraintName);
    }

    expect(collect($results)->where('outcome', 'query_failure'))->toHaveCount(2)
        ->and($notification->fresh()->resolved_at)->toBeNull()
        ->and($notification->fresh()->resolution_reason)->toBeNull()
        ->and($notification->fresh()->active_event_key)->toBe($eventKey)
        ->and(AuditLog::query()
            ->where('action', 'Notification Resolved')
            ->where('auditable_id', $notification->id)
            ->count())->toBe(0);
});

it('converges on one deterministic authority with no duplicate active occurrence', function () {
    $vehicle = concurrencyVehicle();
    $user = concurrencyUser();
    $completionDate = '2026-10-20';
    $older = concurrencyMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => $completionDate,
    ]);
    $newer = concurrencyMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => $completionDate,
        'next_schedule' => '2026-12-01',
    ]);
    $olderKey = concurrencyEventKey($older);
    $newerKey = concurrencyEventKey($newer);
    $olderNotification = concurrencyNotification($user, $olderKey);
    $olderNotification->active_event_key = $olderKey;
    $olderNotification->save();
    $newerNotification = concurrencyNotification($user, $newerKey);
    $barrier = 'deterministic-authority-'.str()->uuid();

    $results = runConcurrencyWorkers($barrier, [
        'first-reconciler' => [
            'action' => 'reconcile',
            'payload' => ['event_key' => $olderKey],
        ],
        'second-reconciler' => [
            'action' => 'reconcile',
            'payload' => ['event_key' => $olderKey],
        ],
        'active-assigner' => [
            'action' => 'assign_active_event',
            'payload' => ['notification_id' => $newerNotification->id, 'event_key' => $newerKey],
        ],
    ]);

    expect(collect($results)->where('outcome', 'reconciled')->sum('resolved_count'))->toBe(1)
        ->and($olderNotification->fresh()->resolution_reason)->toBe('superseded_by_newer_completion')
        ->and($olderNotification->fresh()->active_event_key)->toBeNull()
        ->and($newerNotification->fresh()->resolved_at)->toBeNull()
        ->and($newerNotification->fresh()->active_event_key)->toBe($newerKey)
        ->and(FleetNotification::query()
            ->where('user_id', $user->id)
            ->whereNotNull('active_event_key')
            ->count())->toBe(1);
});
