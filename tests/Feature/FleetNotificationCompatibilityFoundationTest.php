<?php

use App\Models\FleetNotification;
use App\Models\User;
use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceNotificationOccurrenceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

const FCF_RESOLUTION_MIGRATION =
    '2026_10_30_021016_add_resolution_metadata_to_fleet_notifications_table.php';
const FCF_ACTIVE_EVENT_MIGRATION =
    '2026_10_30_061626_add_active_event_key_to_fleet_notifications_table.php';
const FCF_LEGACY_UNIQUE_INDEX =
    'fleet_notifications_user_id_event_key_unique';
const FCF_ACTIVE_UNIQUE_INDEX =
    'fleet_notifications_user_active_event_unique';

function fcfUser(string $role = 'fleet_manager'): User
{
    return User::factory()->create(['role' => $role]);
}

function fcfNotification(
    User $user,
    string $eventKey,
    array $attributes = []
): FleetNotification {
    return FleetNotification::query()->create(array_merge([
        'user_id' => $user->id,
        'title' => 'Compatibility Foundation',
        'message' => 'Existing keyed notification behavior.',
        'status' => 'Unread',
        'event_key' => $eventKey,
        'link' => route('maintenance'),
    ], $attributes));
}

function fcfMigration(string $file): Migration
{
    return require database_path('migrations/'.$file);
}

function fcfAllowOccurrenceFoundation(): MockInterface
{
    $readiness = Mockery::mock(FleetNotificationSchemaReadinessService::class);
    $readiness->shouldReceive('assertOccurrenceActivationReady');
    app()->instance(FleetNotificationSchemaReadinessService::class, $readiness);
    config()->set('fleet-notifications.occurrences.enabled', true);

    return $readiness;
}

beforeEach(function () {
    config()->set([
        'fleet-notifications.occurrences.enabled' => false,
        'fleet-notifications.reconciliation.after_commit_enabled' => false,
        'fleet-notifications.reconciliation.scheduled_recovery_enabled' => false,
        'fleet-notifications.reconciliation.historical_enabled' => false,
    ]);
});

it('keeps every notification lifecycle feature flag disabled by default', function () {
    expect(config('fleet-notifications.occurrences.enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.after_commit_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.scheduled_recovery_enabled'))->toBeFalse()
        ->and(config('fleet-notifications.reconciliation.historical_enabled'))->toBeFalse();
});

it('treats non-boolean occurrence configuration as disabled', function () {
    config()->set('fleet-notifications.occurrences.enabled', 'true');
    $user = fcfUser();
    $this->actingAs($user);
    $eventKey = 'maintenance_due:7001:2026-12-01';

    $notification = FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'Legacy path remains active.',
        $eventKey,
        true,
        route('maintenance')
    );

    expect($notification?->active_event_key)->toBeNull();
});

it('preserves existing maintenance and driver license deduplication while disabled', function (
    string $eventKey,
    string $settingKey,
    string $link
) {
    $user = fcfUser();
    $this->actingAs($user);

    $first = FleetNotificationService::createUniqueWhenEnabled(
        $settingKey,
        'Initial title',
        'Initial message',
        $eventKey,
        true,
        $link
    );
    $first?->update(['status' => 'Read']);
    $second = FleetNotificationService::createUniqueWhenEnabled(
        $settingKey,
        'Updated title',
        'Updated message',
        $eventKey,
        true,
        $link
    );

    expect($second?->id)->toBe($first?->id)
        ->and($second?->fresh()->status)->toBe('Read')
        ->and($second?->fresh()->title)->toBe('Updated title')
        ->and($second?->fresh()->active_event_key)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1);
})->with([
    'maintenance' => [
        'maintenance_due:7002:2026-12-02',
        'maintenanceDue',
        '/maintenance',
    ],
    'driver license' => [
        'driver_license_expiring:7003:2026-12-03',
        'licenseExpiring',
        '/driver',
    ],
]);

it('preserves dispatch requester key updates and acknowledgement while disabled', function () {
    $user = fcfUser('driver');
    $eventKey = 'dispatch_reassignment_request:70:71:requester:'.$user->id;

    $first = FleetNotificationService::createForUserWhenEnabled(
        $user,
        'dispatchUpdates',
        'Request submitted',
        'The reassignment request was submitted.',
        $eventKey,
        true,
        route('dispatch')
    );
    $first?->update(['status' => 'Read']);
    $second = FleetNotificationService::createForUserWhenEnabled(
        $user,
        'dispatchUpdates',
        'Request approved',
        'The reassignment request was approved.',
        $eventKey,
        true,
        route('dispatch')
    );

    expect($second?->id)->toBe($first?->id)
        ->and($second?->fresh()->status)->toBe('Read')
        ->and($second?->fresh()->title)->toBe('Request approved')
        ->and($second?->fresh()->active_event_key)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('does not create a historical occurrence while the foundation is disabled', function () {
    $user = fcfUser();
    $this->actingAs($user);
    $eventKey = 'maintenance_due:7004:2026-12-04';
    $original = FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'Original occurrence.',
        $eventKey,
        true,
        route('maintenance')
    );
    $original?->forceFill([
        'resolved_at' => now(),
        'resolution_reason' => 'schedule_changed',
    ])->save();

    $result = FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'No new occurrence while disabled.',
        $eventKey,
        true,
        route('maintenance')
    );

    expect($result?->id)->toBe($original?->id)
        ->and($result?->fresh()->resolved_at)->not->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('preserves recipient authorization before the dormant creator is considered', function () {
    config()->set('fleet-notifications.occurrences.enabled', true);
    $user = fcfUser('driver');

    $result = FleetNotificationService::createForUserWhenEnabled(
        $user,
        'dispatchUpdates',
        'Restricted event',
        'This destination is not a recognized module.',
        'dispatch:7005:created',
        true,
        '/unrecognized-internal-module'
    );

    expect($result)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(0);
});

it('preserves recipient isolation for identical canonical keys while disabled', function () {
    $firstUser = fcfUser();
    $secondUser = fcfUser();
    $eventKey = 'dispatch:7005:created';

    $first = FleetNotificationService::createForUserWhenEnabled(
        $firstUser,
        'dispatchUpdates',
        'Dispatch Created',
        'Recipient-specific notification.',
        $eventKey,
        true,
        route('dispatch')
    );
    $second = FleetNotificationService::createForUserWhenEnabled(
        $secondUser,
        'dispatchUpdates',
        'Dispatch Created',
        'Recipient-specific notification.',
        $eventKey,
        true,
        route('dispatch')
    );

    expect($first?->user_id)->toBe($firstUser->id)
        ->and($second?->user_id)->toBe($secondUser->id)
        ->and($second?->id)->not->toBe($first?->id)
        ->and(FleetNotification::query()->count())->toBe(2);
});

it('reports additive preparation readiness but refuses occurrence activation', function () {
    $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();

    expect($readiness['foundation_ready'])->toBeTrue()
        ->and($readiness['preparation_ready'])->toBeTrue()
        ->and($readiness['indexes']['active_event_unique'])->toBeTrue()
        ->and($readiness['indexes']['legacy_event_unique'])->toBeTrue()
        ->and($readiness['activation_ready'])->toBeFalse()
        ->and($readiness['activation_blockers'])
        ->toContain('The legacy unique user and event key index is still present.');
});

it('fails readiness closed without querying unavailable lifecycle columns', function () {
    $activeMigration = fcfMigration(FCF_ACTIVE_EVENT_MIGRATION);
    $resolutionMigration = fcfMigration(FCF_RESOLUTION_MIGRATION);

    $activeMigration->down();
    $resolutionMigration->down();

    try {
        $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();

        expect($readiness['foundation_ready'])->toBeFalse()
            ->and($readiness['preparation_ready'])->toBeFalse()
            ->and($readiness['activation_ready'])->toBeFalse()
            ->and($readiness['columns'])->toBe([
                'resolved_at' => false,
                'resolution_reason' => false,
                'active_event_key' => false,
            ])
            ->and($readiness['invariants']['unresolved_keyed_rows_have_active_keys'])->toBeNull()
            ->and($readiness['invariants']['unresolved_active_keys_match_event_keys'])->toBeNull()
            ->and($readiness['invariants']['resolved_rows_have_null_active_keys'])->toBeNull();
    } finally {
        $resolutionMigration->up();
        $activeMigration->up();
    }
});

it('requires the exact active-event unique index name', function () {
    $alternateIndex = 'fleet_notifications_alternate_active_unique';

    Schema::table('fleet_notifications', function ($table) use ($alternateIndex) {
        $table->dropUnique(FCF_ACTIVE_UNIQUE_INDEX);
        $table->unique(['user_id', 'active_event_key'], $alternateIndex);
    });

    try {
        $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();

        expect($readiness['indexes']['active_event_unique'])->toBeFalse()
            ->and($readiness['foundation_ready'])->toBeFalse()
            ->and($readiness['activation_blockers'])
            ->toContain('The expected unique user and active event key index is missing or invalid.');
    } finally {
        Schema::table('fleet_notifications', function ($table) use ($alternateIndex) {
            $table->dropUnique($alternateIndex);
            $table->unique(
                ['user_id', 'active_event_key'],
                FCF_ACTIVE_UNIQUE_INDEX
            );
        });
    }
});

it('requires the active-event unique index columns in canonical order', function () {
    Schema::table('fleet_notifications', function ($table) {
        $table->dropUnique(FCF_ACTIVE_UNIQUE_INDEX);
        $table->unique(
            ['active_event_key', 'user_id'],
            FCF_ACTIVE_UNIQUE_INDEX
        );
    });

    try {
        $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();

        expect($readiness['indexes']['active_event_unique'])->toBeFalse()
            ->and($readiness['foundation_ready'])->toBeFalse();
    } finally {
        Schema::table('fleet_notifications', function ($table) {
            $table->dropUnique(FCF_ACTIVE_UNIQUE_INDEX);
            $table->unique(
                ['user_id', 'active_event_key'],
                FCF_ACTIVE_UNIQUE_INDEX
            );
        });
    }
});

it('reports canonical active-key violations without exposing event contents', function () {
    $user = fcfUser();
    $notification = fcfNotification(
        $user,
        'maintenance_due:7014:2026-12-14'
    );
    $notification->forceFill([
        'active_event_key' => 'maintenance_due:7014:2026-12-15',
    ])->save();

    $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();
    $diagnostics = json_encode($readiness, JSON_THROW_ON_ERROR);

    expect($readiness['invariants']['unresolved_active_keys_match_event_keys'])->toBeFalse()
        ->and($readiness['violation_counts']['unresolved_active_keys_mismatching_event_keys'])->toBe(1)
        ->and($readiness['activation_ready'])->toBeFalse()
        ->and($diagnostics)->not->toContain('maintenance_due:7014');
});

it('reports a missing active-event unique index without mutating data', function () {
    Schema::table('fleet_notifications', function ($table) {
        $table->dropUnique(FCF_ACTIVE_UNIQUE_INDEX);
    });

    try {
        $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();

        expect($readiness['indexes']['active_event_unique'])->toBeFalse()
            ->and($readiness['foundation_ready'])->toBeFalse()
            ->and($readiness['preparation_ready'])->toBeFalse()
            ->and($readiness['activation_ready'])->toBeFalse();
    } finally {
        Schema::table('fleet_notifications', function ($table) {
            $table->unique(
                ['user_id', 'active_event_key'],
                FCF_ACTIVE_UNIQUE_INDEX
            );
        });
    }
});

it('fails closed when occurrence mode is requested before activation readiness', function () {
    config()->set('fleet-notifications.occurrences.enabled', true);
    $user = fcfUser();

    expect(fn () => FleetNotificationService::createForUserWhenEnabled(
        $user,
        'maintenanceDue',
        'Maintenance Due Soon',
        'Activation is not ready.',
        'maintenance_due:7012:2026-12-12',
        true,
        route('maintenance')
    ))->toThrow(
        LogicException::class,
        'Notification occurrence activation is not ready'
    )->and(FleetNotification::query()->count())->toBe(0);
});

it('requires legacy index removal and valid active-key population for activation', function () {
    $user = fcfUser();
    $notification = fcfNotification(
        $user,
        'maintenance_due:7006:2026-12-06'
    );

    Schema::table('fleet_notifications', function ($table) {
        $table->dropUnique(FCF_LEGACY_UNIQUE_INDEX);
    });

    try {
        $beforePopulation = app(FleetNotificationSchemaReadinessService::class)
            ->inspect();
        $notification->forceFill([
            'active_event_key' => $notification->event_key,
        ])->save();
        $afterPopulation = app(FleetNotificationSchemaReadinessService::class)
            ->inspect();
        $notification->forceFill([
            'resolved_at' => now(),
            'resolution_reason' => 'schedule_changed',
        ])->save();
        $resolvedWithActiveKey = app(FleetNotificationSchemaReadinessService::class)
            ->inspect();

        expect($beforePopulation['activation_ready'])->toBeFalse()
            ->and($beforePopulation['invariants']['unresolved_keyed_rows_have_active_keys'])->toBeFalse()
            ->and($afterPopulation['activation_ready'])->toBeTrue()
            ->and($afterPopulation['indexes']['legacy_event_unique'])->toBeFalse()
            ->and($resolvedWithActiveKey['activation_ready'])->toBeFalse()
            ->and($resolvedWithActiveKey['invariants']['resolved_rows_have_null_active_keys'])->toBeFalse();
    } finally {
        Schema::table('fleet_notifications', function ($table) {
            $table->unique(
                ['user_id', 'event_key'],
                FCF_LEGACY_UNIQUE_INDEX
            );
        });
    }
});

it('treats an equivalently renamed legacy unique index as an activation blocker', function () {
    $alternateLegacyIndex = 'fleet_notifications_legacy_compatibility_unique';
    $user = fcfUser();
    $notification = fcfNotification(
        $user,
        'maintenance_due:7019:2026-12-19'
    );
    $notification->forceFill([
        'active_event_key' => $notification->event_key,
    ])->save();

    Schema::table('fleet_notifications', function ($table) use ($alternateLegacyIndex) {
        $table->dropUnique(FCF_LEGACY_UNIQUE_INDEX);
        $table->unique(['user_id', 'event_key'], $alternateLegacyIndex);
    });

    try {
        $readiness = app(FleetNotificationSchemaReadinessService::class)->inspect();

        expect($readiness['indexes']['legacy_event_unique'])->toBeTrue()
            ->and($readiness['activation_ready'])->toBeFalse()
            ->and($readiness['activation_blockers'])
            ->toContain('The legacy unique user and event key index is still present.');
    } finally {
        Schema::table('fleet_notifications', function ($table) use ($alternateLegacyIndex) {
            $table->dropUnique($alternateLegacyIndex);
            $table->unique(
                ['user_id', 'event_key'],
                FCF_LEGACY_UNIQUE_INDEX
            );
        });
    }
});

it('selects the explicit active occurrence and preserves acknowledgement', function () {
    fcfAllowOccurrenceFoundation();
    $user = fcfUser();
    $eventKey = 'compatibility_event:7007';
    $active = fcfNotification($user, $eventKey, ['status' => 'Read']);
    $active->forceFill(['active_event_key' => $eventKey])->save();

    $result = FleetNotificationService::createForUserWhenEnabled(
        $user,
        'maintenanceDue',
        'Maintenance Overdue',
        'The same active occurrence is now overdue.',
        $eventKey,
        true,
        route('maintenance')
    );

    expect($result?->id)->toBe($active->id)
        ->and($result?->fresh()->status)->toBe('Read')
        ->and($result?->fresh()->title)->toBe('Maintenance Overdue')
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('rejects an existing active row with a mismatched canonical key', function () {
    fcfAllowOccurrenceFoundation();
    $user = fcfUser();
    $activeKey = 'compatibility_event:7015';
    $notification = fcfNotification(
        $user,
        'compatibility_event:7015:mismatch',
        ['status' => 'Read']
    );
    $notification->forceFill(['active_event_key' => $activeKey])->save();

    expect(fn () => app(FleetNotificationKeyedCreationService::class)
        ->createOrUpdateActive(
            $user,
            'Must not update',
            'The canonical key is inconsistent.',
            $activeKey,
            route('maintenance')
        ))->toThrow(LogicException::class)
        ->and($notification->fresh()->title)->toBe('Compatibility Foundation')
        ->and($notification->fresh()->status)->toBe('Read');
});

it('rejects an existing resolved row that retains an active key', function () {
    fcfAllowOccurrenceFoundation();
    $user = fcfUser();
    $eventKey = 'compatibility_event:7016';
    $notification = fcfNotification($user, $eventKey, ['status' => 'Read']);
    $notification->forceFill([
        'active_event_key' => $eventKey,
        'resolved_at' => now(),
        'resolution_reason' => 'schedule_changed',
    ])->save();

    expect(fn () => app(FleetNotificationKeyedCreationService::class)
        ->createOrUpdateActive(
            $user,
            'Must not update',
            'Resolved history is immutable.',
            $eventKey,
            route('maintenance')
        ))->toThrow(LogicException::class)
        ->and($notification->fresh()->title)->toBe('Compatibility Foundation')
        ->and($notification->fresh()->status)->toBe('Read');
});

it('fails closed when an insert winner changes before recovery locking', function (
    string $change
) {
    $user = fcfUser();
    $eventKey = 'compatibility_event:7017';
    $winner = fcfNotification($user, $eventKey, ['status' => 'Read']);
    $winner->forceFill(['active_event_key' => $eventKey])->save();

    Schema::table('fleet_notifications', function ($table) {
        $table->dropUnique(FCF_LEGACY_UNIQUE_INDEX);
    });

    try {
        $readiness = Mockery::mock(FleetNotificationSchemaReadinessService::class);
        $readiness->shouldReceive('assertOccurrenceActivationReady')->once();
        $lookup = 0;
        $service = Mockery::mock(
            FleetNotificationKeyedCreationService::class,
            [$readiness, app(MaintenanceNotificationOccurrenceGuard::class)]
        )->makePartial()->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('lockActiveOccurrence')
            ->twice()
            ->andReturnUsing(function () use (&$lookup, $winner, $change) {
                $lookup++;

                if ($lookup === 1) {
                    return null;
                }

                $winner->forceFill($change === 'resolved'
                    ? [
                        'resolved_at' => now(),
                        'resolution_reason' => 'schedule_changed',
                    ]
                    : ['active_event_key' => null]
                )->save();

                return $winner->fresh();
            });

        expect(fn () => $service->createOrUpdateActive(
            $user,
            'Must not update',
            'The winner changed lifecycle state.',
            $eventKey,
            route('maintenance')
        ))->toThrow(LogicException::class)
            ->and($winner->fresh()->title)->toBe('Compatibility Foundation')
            ->and($winner->fresh()->status)->toBe('Read');
    } finally {
        Schema::table('fleet_notifications', function ($table) {
            $table->unique(
                ['user_id', 'event_key'],
                FCF_LEGACY_UNIQUE_INDEX
            );
        });
    }
})->with(['resolved', 'active key cleared']);

it('initializes a future active occurrence as unread in the dormant mechanism', function () {
    fcfAllowOccurrenceFoundation();
    $user = fcfUser();
    $eventKey = 'compatibility_event:7013';

    $notification = FleetNotificationService::createForUserWhenEnabled(
        $user,
        'maintenanceDue',
        'Maintenance Due Soon',
        'Dormant active occurrence foundation.',
        $eventKey,
        true,
        route('maintenance')
    );

    expect($notification?->status)->toBe('Unread')
        ->and($notification?->event_key)->toBe($eventKey)
        ->and($notification?->active_event_key)->toBe($eventKey)
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('leaves final rollback ownership with an ambient transaction', function () {
    fcfAllowOccurrenceFoundation();
    $user = fcfUser();
    $eventKey = 'compatibility_event:7018';

    DB::beginTransaction();

    try {
        $notification = app(FleetNotificationKeyedCreationService::class)
            ->createOrUpdateActive(
                $user,
                'Nested transaction contract',
                'The outer workflow owns the final outcome.',
                $eventKey,
                route('maintenance')
            );

        expect($notification->exists)->toBeTrue();
    } finally {
        DB::rollBack();
    }

    expect(FleetNotification::query()
        ->where('user_id', $user->id)
        ->where('event_key', $eventKey)
        ->exists())->toBeFalse();
});

it('classifies only the active-key unique constraint as an expected race', function () {
    $user = fcfUser();
    $eventKey = 'maintenance_due:7008:2026-12-08';
    $first = fcfNotification($user, $eventKey);
    $first->forceFill(['active_event_key' => $eventKey])->save();
    $second = fcfNotification(
        $user,
        'maintenance_due:7009:2026-12-09'
    );

    try {
        $second->forceFill(['active_event_key' => $eventKey])->save();
        $this->fail('Expected an active-event unique constraint violation.');
    } catch (UniqueConstraintViolationException $exception) {
        expect(app(FleetNotificationKeyedCreationService::class)
            ->isExpectedActiveEventUniqueConflict($exception))->toBeTrue();
    }
});

it('rethrows legacy unique conflicts instead of misclassifying them', function () {
    fcfAllowOccurrenceFoundation();
    $user = fcfUser();
    $eventKey = 'compatibility_event:7010';
    $resolved = fcfNotification($user, $eventKey);
    $resolved->forceFill([
        'resolved_at' => now(),
        'resolution_reason' => 'schedule_changed',
    ])->save();

    expect(fn () => FleetNotificationService::createForUserWhenEnabled(
        $user,
        'maintenanceDue',
        'Maintenance Due Soon',
        'Legacy uniqueness must fail closed.',
        $eventKey,
        true,
        route('maintenance')
    ))->toThrow(UniqueConstraintViolationException::class)
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('does not dispatch reconciliation or scheduled work from keyed creation', function () {
    Queue::fake();
    $user = fcfUser();
    $this->actingAs($user);

    FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'No automatic reconciliation is wired.',
        'maintenance_due:7011:2026-12-11',
        true,
        route('maintenance')
    );

    Queue::assertNothingPushed();
});
