<?php

use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\MaintenanceNotificationReconciliationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\DatabaseBarrier;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\NotificationOccurrenceSchemaTransition;

function occurrenceUser(): User
{
    return User::factory()->create([
        'email' => 'occurrence-'.str()->uuid().'@example.test',
        'role' => 'fleet_manager',
        'status' => 'active',
    ]);
}

function occurrenceVehicle(): Vehicle
{
    return Vehicle::query()->create([
        'plate_number' => 'OC-'.str()->upper(str()->random(8)),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Occurrence',
        'model' => 'Harness',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ]);
}

function occurrenceMaintenance(Vehicle $vehicle, string $date = '2026-12-01'): Maintenance
{
    return Maintenance::query()->create([
        'maintenance_number' => 'OC-MNT-'.str()->upper(str()->random(8)),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Occurrence concurrency source',
        'maintenance_date' => '2026-10-01',
        'next_schedule' => $date,
        'cost' => 0,
        'status' => 'Scheduled',
    ]);
}

function occurrenceKey(Maintenance $maintenance, ?string $date = null): string
{
    return 'maintenance_due:'.$maintenance->id.':'.($date ?? $maintenance->next_schedule?->toDateString());
}

function createOccurrence(User $user, string $eventKey, string $title = 'Maintenance Due Soon'): FleetNotification
{
    return app(FleetNotificationKeyedCreationService::class)->createOrUpdateActive(
        $user,
        $title,
        'Occurrence concurrency reminder.',
        $eventKey,
        '/maintenance'
    );
}

/**
 * @param  array<string, array{action: string, payload: array<string, int|string|null>}>  $workers
 * @return array<string, array<string, int|string|null>>
 */
function runOccurrenceWorkers(string $barrier, array $workers): array
{
    foreach (['start', 'lookup', 'collision', 'mutated', 'reconciled', 'first-locked'] as $phase) {
        DatabaseBarrier::reset($barrier.'-'.$phase);
    }

    $commands = [];
    $participants = count($workers);

    foreach ($workers as $participant => $worker) {
        $commands[$participant] = HarnessEnvironment::occurrenceWorkerCommand(
            base_path(),
            $worker['action'],
            array_merge($worker['payload'], [
                'barrier' => $barrier,
                'participant' => $participant,
                'participants' => $participants,
            ])
        );
    }

    $results = (new ConcurrentProcessRunner(
        environmentFactory: fn (): array => HarnessEnvironment::occurrenceWorkerEnvironment()
    ))->run($commands, 25);
    $decoded = collect($results)->mapWithKeys(fn (array $result, string $participant): array => [
        $participant => json_decode(trim($result['output']), true, 512, JSON_THROW_ON_ERROR),
    ]);

    if ($decoded->pluck('connection_id')->unique()->count() !== $participants) {
        throw new RuntimeException('Occurrence workers did not use distinct MySQL sessions.');
    }

    return $decoded->all();
}

beforeEach(function () {
    (new NotificationOccurrenceSchemaTransition)->assertActiveOnly();
    DatabaseBarrier::createTable();

    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (['audit_logs', 'fleet_notifications', 'maintenances', 'vehicles', 'users', 'concurrency_test_barriers'] as $table) {
            DB::table($table)->delete();
        }
    } finally {
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
});

it('creates exactly one active occurrence across simultaneous creators', function () {
    $user = occurrenceUser();
    $eventKey = 'occurrence_generic:create:'.str()->uuid();
    $barrier = 'occurrence-create-'.str()->uuid();
    $results = runOccurrenceWorkers($barrier, [
        'first' => ['action' => 'create_with_collision_barrier', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey]],
        'second' => ['action' => 'create_with_collision_barrier', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey]],
    ]);
    HarnessSafetyPolicy::assertSuccessfulOccurrenceCreationRaceResults($results, $eventKey);

    $notification = FleetNotification::query()
        ->where('user_id', $user->id)
        ->where('active_event_key', $eventKey)
        ->sole();

    expect($notification->event_key)->toBe($eventKey)
        ->and($notification->active_event_key)->toBe($eventKey)
        ->and($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull()
        ->and($notification->status)->toBe('Unread')
        ->and(collect($results)->pluck('connection_id')->unique())->toHaveCount(2)
        ->and(collect($results)->where('outcome', 'created'))->toHaveCount(1)
        ->and(collect($results)->where('outcome', 'recovered'))->toHaveCount(1)
        ->and(collect($results)->pluck('notification_id')->unique())->toHaveCount(1)
        ->and(collect($results)->pluck('notification_id')->first())->toBe($notification->id)
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('preserves A1 and B1 while creating a new A2 occurrence', function () {
    $user = occurrenceUser();
    $maintenance = occurrenceMaintenance(occurrenceVehicle());
    $keyA = occurrenceKey($maintenance);
    $a1 = createOccurrence($user, $keyA);
    $a1->update(['status' => 'Read']);
    $maintenance->update(['next_schedule' => '2026-12-08']);
    app(MaintenanceNotificationReconciliationService::class)->reconcileEventKey($keyA);
    $keyB = occurrenceKey($maintenance->fresh());
    $b1 = createOccurrence($user, $keyB);
    $maintenance->update(['next_schedule' => '2026-12-01']);
    app(MaintenanceNotificationReconciliationService::class)->reconcileEventKey($keyB);
    $a2 = createOccurrence($user, $keyA);

    $a1 = $a1->fresh();
    $b1 = $b1->fresh();
    $a2 = $a2->fresh();

    expect([$a1->id, $b1->id, $a2->id])->each->toBeInt()
        ->and(collect([$a1->id, $b1->id, $a2->id])->unique())->toHaveCount(3)
        ->and($a1->status)->toBe('Read')
        ->and($a1->event_key)->toBe($keyA)
        ->and($a1->resolved_at)->not->toBeNull()
        ->and($a1->resolution_reason)->toBe('schedule_changed')
        ->and($a1->active_event_key)->toBeNull()
        ->and($b1->status)->toBe('Unread')
        ->and($b1->event_key)->toBe($keyB)
        ->and($b1->resolved_at)->not->toBeNull()
        ->and($b1->resolution_reason)->toBe('schedule_changed')
        ->and($b1->active_event_key)->toBeNull()
        ->and($a2->event_key)->toBe($keyA)
        ->and($a2->active_event_key)->toBe($keyA)
        ->and($a2->resolved_at)->toBeNull()
        ->and($a2->resolution_reason)->toBeNull()
        ->and($a2->status)->toBe('Unread')
        ->and(FleetNotification::query()->where('event_key', $keyA)->count())->toBe(2)
        ->and(FleetNotification::query()->where('user_id', $user->id)->where('active_event_key', $keyA)->count())->toBe(1)
        ->and(FleetNotification::query()->where('user_id', $user->id)->whereNotNull('active_event_key')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'Notification Resolved')->count())->toBe(2);
});

it('reuses one active row during repeated polling without resetting acknowledgement', function () {
    $user = occurrenceUser();
    $eventKey = occurrenceKey(occurrenceMaintenance(occurrenceVehicle()));
    $first = createOccurrence($user, $eventKey);
    $first->update(['status' => 'Read']);
    $second = createOccurrence($user, $eventKey, 'Maintenance Overdue');

    expect($second->id)->toBe($first->id)
        ->and($second->status)->toBe('Read')
        ->and($second->title)->toBe('Maintenance Overdue')
        ->and(FleetNotification::query()->where('event_key', $eventKey)->count())->toBe(1);
});

it('fails closed when the collision winner is resolved before recovery locking', function () {
    $user = occurrenceUser();
    $eventKey = 'occurrence_generic:winner-resolved:'.str()->uuid();
    $winner = createOccurrence($user, $eventKey);
    $winner->update(['status' => 'Read']);
    $results = runOccurrenceWorkers('winner-resolved-'.str()->uuid(), [
        'creator' => ['action' => 'create_with_collision_barrier', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey, 'mutate_winner' => '1']],
        'mutator' => ['action' => 'mutate_collision_winner', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey, 'mutation' => 'resolve']],
    ]);

    HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($results['creator']);
    $winner = $winner->fresh();

    expect($results['mutator']['outcome'])->toBe('mutated')
        ->and($winner->id)->toBeInt()
        ->and($winner->event_key)->toBe($eventKey)
        ->and($winner->status)->toBe('Read')
        ->and($winner->resolved_at)->not->toBeNull()
        ->and($winner->resolution_reason)->toBe('test_collision_resolution')
        ->and($winner->active_event_key)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1)
        ->and(FleetNotification::query()->whereNotNull('active_event_key')->count())->toBe(0);
});

it('fails closed when the collision winner loses its active key before recovery locking', function () {
    $user = occurrenceUser();
    $eventKey = 'occurrence_generic:winner-cleared:'.str()->uuid();
    $winner = createOccurrence($user, $eventKey);
    $winner->update(['status' => 'Read']);
    $results = runOccurrenceWorkers('winner-cleared-'.str()->uuid(), [
        'creator' => ['action' => 'create_with_collision_barrier', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey, 'mutate_winner' => '1']],
        'mutator' => ['action' => 'mutate_collision_winner', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey, 'mutation' => 'clear']],
    ]);

    HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($results['creator']);
    $winner = $winner->fresh();

    expect($results['mutator']['outcome'])->toBe('mutated')
        ->and($winner->event_key)->toBe($eventKey)
        ->and($winner->status)->toBe('Read')
        ->and($winner->resolved_at)->toBeNull()
        ->and($winner->resolution_reason)->toBeNull()
        ->and($winner->active_event_key)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1)
        ->and(FleetNotification::query()->whereNotNull('active_event_key')->count())->toBe(0);
});

it('propagates unrelated MySQL unique violations', function () {
    $transition = new NotificationOccurrenceSchemaTransition;
    $user = occurrenceUser();
    createOccurrence($user, 'occurrence_unique:first', 'Unique title');
    $transition->installTestTitleUniqueIndex();

    try {
        expect(fn () => createOccurrence($user, 'occurrence_unique:second', 'Unique title'))
            ->toThrow(UniqueConstraintViolationException::class);
    } finally {
        $transition->removeTestTitleUniqueIndex();
    }
});

it('keeps identical active keys independent across recipients', function () {
    $first = occurrenceUser();
    $second = occurrenceUser();
    $eventKey = occurrenceKey(occurrenceMaintenance(occurrenceVehicle()));
    $results = runOccurrenceWorkers('cross-user-'.str()->uuid(), [
        'first' => ['action' => 'create', 'payload' => ['user_id' => $first->id, 'event_key' => $eventKey]],
        'second' => ['action' => 'create', 'payload' => ['user_id' => $second->id, 'event_key' => $eventKey]],
    ]);

    expect(collect($results)->pluck('notification_id')->unique())->toHaveCount(2)
        ->and(FleetNotification::query()->where('active_event_key', $eventKey)->count())->toBe(2);
});

it('keeps historical acknowledgement independent from a restored occurrence', function () {
    $user = occurrenceUser();
    $maintenance = occurrenceMaintenance(occurrenceVehicle());
    $eventKey = occurrenceKey($maintenance);
    $old = createOccurrence($user, $eventKey);
    $old->update(['status' => 'Read']);
    $updated = createOccurrence($user, $eventKey, 'Maintenance Overdue');
    expect($updated->id)->toBe($old->id)
        ->and($updated->fresh()->status)->toBe('Read');
    $maintenance->update(['next_schedule' => '2026-12-08']);
    app(MaintenanceNotificationReconciliationService::class)->reconcileEventKey($eventKey);
    $maintenance->update(['next_schedule' => '2026-12-01']);
    $restored = createOccurrence($user, $eventKey);

    $old = $old->fresh();
    $restored = $restored->fresh();

    expect($old->status)->toBe('Read')
        ->and($old->event_key)->toBe($eventKey)
        ->and($old->resolved_at)->not->toBeNull()
        ->and($old->resolution_reason)->toBe('schedule_changed')
        ->and($old->active_event_key)->toBeNull()
        ->and($restored->id)->not->toBe($old->id)
        ->and($restored->status)->toBe('Unread')
        ->and($restored->event_key)->toBe($eventKey)
        ->and($restored->active_event_key)->toBe($eventKey)
        ->and($restored->resolved_at)->toBeNull()
        ->and($restored->resolution_reason)->toBeNull()
        ->and(FleetNotification::query()->where('user_id', $user->id)->where('event_key', $eventKey)->count())->toBe(2)
        ->and(FleetNotification::query()->where('user_id', $user->id)->where('active_event_key', $eventKey)->count())->toBe(1);
});

it('does not recreate an obsolete event after reconciliation wins the race', function () {
    $user = occurrenceUser();
    $maintenance = occurrenceMaintenance(occurrenceVehicle());
    $eventKey = occurrenceKey($maintenance);
    createOccurrence($user, $eventKey);
    $maintenance->update(['next_schedule' => '2026-12-08']);
    $results = runOccurrenceWorkers('reconcile-create-'.str()->uuid(), [
        'reconciler' => ['action' => 'reconcile_then_signal', 'payload' => ['event_key' => $eventKey]],
        'creator' => ['action' => 'create_after_reconciliation', 'payload' => ['user_id' => $user->id, 'event_key' => $eventKey]],
    ]);

    expect($results['creator']['outcome'])->toBe('source_rejected')
        ->and($results['creator']['reason'])->toBe('schedule_changed')
        ->and(FleetNotification::query()->where('event_key', $eventKey)->whereNull('resolved_at')->count())->toBe(0);
})->group('known-production-gap');

it('rolls back caller work and retries only a real deadlock without duplicate rows', function () {
    $user = occurrenceUser();
    $first = createOccurrence($user, 'occurrence_deadlock:first');
    $second = createOccurrence($user, 'occurrence_deadlock:second');

    DB::beginTransaction();
    createOccurrence($user, 'occurrence_deadlock:rolled-back');
    DB::rollBack();

    $results = runOccurrenceWorkers('deadlock-'.str()->uuid(), [
        'first' => ['action' => 'deadlock_update', 'payload' => ['first_id' => $first->id, 'second_id' => $second->id]],
        'second' => ['action' => 'deadlock_update', 'payload' => ['first_id' => $second->id, 'second_id' => $first->id]],
    ]);

    expect(collect($results)->where('outcome', 'committed'))->toHaveCount(2)
        ->and(collect($results)->sum('attempts'))->toBeGreaterThanOrEqual(3)
        ->and(FleetNotification::query()->where('event_key', 'occurrence_deadlock:rolled-back')->count())->toBe(0)
        ->and(FleetNotification::query()->count())->toBe(2);
});

it('reports exact active-only schema readiness and fails closed for transient metadata defects', function () {
    $transition = new NotificationOccurrenceSchemaTransition;
    $readiness = app(FleetNotificationSchemaReadinessService::class);

    expect($readiness->inspect()['activation_ready'])->toBeTrue();

    $user = occurrenceUser();
    FleetNotification::query()->create([
        'user_id' => $user->id,
        'title' => 'Missing active identity',
        'message' => 'Readiness must reject this unresolved keyed row.',
        'status' => 'Unread',
        'event_key' => 'maintenance_due:999001:2027-01-01',
        'link' => '/maintenance',
    ]);
    $missingIdentity = $readiness->inspect();
    expect($missingIdentity['activation_ready'])->toBeFalse()
        ->and($missingIdentity['violation_counts']['unresolved_keyed_rows_missing_active_keys'])->toBe(1);
    FleetNotification::query()->delete();

    $mismatched = FleetNotification::query()->create([
        'user_id' => $user->id,
        'title' => 'Mismatched active identity',
        'message' => 'Readiness must reject mismatched canonical and active keys.',
        'status' => 'Unread',
        'event_key' => 'maintenance_due:999002:2027-01-01',
        'link' => '/maintenance',
    ]);
    $mismatched->forceFill([
        'active_event_key' => 'maintenance_due:999002:2027-01-02',
    ])->save();
    $mismatchedIdentity = $readiness->inspect();
    expect($mismatchedIdentity['activation_ready'])->toBeFalse()
        ->and($mismatchedIdentity['violation_counts']['unresolved_active_keys_mismatching_event_keys'])->toBe(1)
        ->and(implode(' ', $mismatchedIdentity['activation_blockers']))
        ->not->toContain('maintenance_due:999002');
    FleetNotification::query()->delete();

    $resolvedActive = FleetNotification::query()->create([
        'user_id' => $user->id,
        'title' => 'Resolved active identity',
        'message' => 'Readiness must reject active keys retained by resolved rows.',
        'status' => 'Read',
        'event_key' => 'maintenance_due:999003:2027-01-01',
        'link' => '/maintenance',
    ]);
    $resolvedActive->forceFill([
        'active_event_key' => $resolvedActive->event_key,
        'resolved_at' => now(),
        'resolution_reason' => 'test_readiness',
    ])->save();
    $resolvedIdentity = $readiness->inspect();
    expect($resolvedIdentity['activation_ready'])->toBeFalse()
        ->and($resolvedIdentity['violation_counts']['resolved_rows_retaining_active_keys'])->toBe(1);
    FleetNotification::query()->delete();

    $transition->installLegacyIndexForReadinessTest();
    try {
        expect($readiness->inspect()['activation_ready'])->toBeFalse();
    } finally {
        $transition->removeLegacyIndexAfterReadinessTest();
    }

    $transition->replaceActiveIndexForMetadataTest('cc_wrong_active_order');
    try {
        expect($readiness->inspect()['activation_ready'])->toBeFalse();
    } finally {
        $transition->restoreActiveIndexAfterMetadataTest('cc_wrong_active_order');
    }

    $transition->dropResolutionReasonForReadinessTest();
    try {
        expect($readiness->inspect()['foundation_ready'])->toBeFalse();
    } finally {
        $transition->restoreResolutionReasonAfterReadinessTest();
    }
});
