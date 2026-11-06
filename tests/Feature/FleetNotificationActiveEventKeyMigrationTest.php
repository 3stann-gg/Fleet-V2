<?php

use App\Models\FleetNotification;
use App\Models\User;
use App\Services\FleetNotificationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

const ACTIVE_EVENT_KEY_MIGRATION = '2026_10_30_061626_add_active_event_key_to_fleet_notifications_table.php';
const ACTIVE_EVENT_KEY_UNIQUE_INDEX = 'fleet_notifications_user_active_event_unique';
const LEGACY_EVENT_KEY_UNIQUE_INDEX = 'fleet_notifications_user_id_event_key_unique';

function activeEventKeyUser(): User
{
    return User::factory()->create(['role' => 'fleet_manager']);
}

function activeEventKeyNotification(User $user, array $attributes = []): FleetNotification
{
    return FleetNotification::query()->create(array_merge([
        'user_id' => $user->id,
        'title' => 'Active Event Foundation',
        'message' => 'Dormant occurrence identity test.',
        'status' => 'Unread',
        'event_key' => fake()->unique()->bothify('maintenance_due:####:2026-12-##'),
        'link' => route('maintenance'),
    ], $attributes));
}

function activeEventKeyMigration(): Migration
{
    return require database_path('migrations/'.ACTIVE_EVENT_KEY_MIGRATION);
}

function activeEventKeyIndexes(): Collection
{
    return collect(Schema::getIndexes('fleet_notifications'))->keyBy('name');
}

function assignActiveEventKey(
    FleetNotification $notification,
    string $activeEventKey
): FleetNotification {
    $notification->active_event_key = $activeEventKey;
    $notification->save();

    return $notification->refresh();
}

it('adds a nullable active event key and its explicit unique index', function () {
    $columns = collect(Schema::getColumns('fleet_notifications'))->keyBy('name');
    $indexes = activeEventKeyIndexes();

    expect($columns)->toHaveKey('active_event_key')
        ->and($columns['active_event_key']['nullable'])->toBeTrue()
        ->and($indexes)->toHaveKeys([
            ACTIVE_EVENT_KEY_UNIQUE_INDEX,
            LEGACY_EVENT_KEY_UNIQUE_INDEX,
        ])
        ->and($indexes[ACTIVE_EVENT_KEY_UNIQUE_INDEX]['columns'])
        ->toBe(['user_id', 'active_event_key'])
        ->and($indexes[ACTIVE_EVENT_KEY_UNIQUE_INDEX]['unique'])->toBeTrue();
});

it('keeps existing style notification inserts compatible and inactive by default', function () {
    $notification = activeEventKeyNotification(activeEventKeyUser())->refresh();

    expect($notification->active_event_key)->toBeNull()
        ->and($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull()
        ->and($notification->status)->toBe('Unread');
});

it('allows multiple null active event keys for one user', function () {
    $user = activeEventKeyUser();
    $first = activeEventKeyNotification($user);
    $second = activeEventKeyNotification($user);

    expect($first->active_event_key)->toBeNull()
        ->and($second->active_event_key)->toBeNull()
        ->and(FleetNotification::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('rejects a duplicate non-null active event key for the same user', function () {
    $user = activeEventKeyUser();
    $activeEventKey = 'maintenance_due:100:2026-12-01';
    assignActiveEventKey(activeEventKeyNotification($user), $activeEventKey);
    $duplicate = activeEventKeyNotification($user);

    expect(fn () => assignActiveEventKey($duplicate, $activeEventKey))
        ->toThrow(QueryException::class);
});

it('allows different users to share the same active event key', function () {
    $activeEventKey = 'maintenance_due:101:2026-12-02';
    $first = assignActiveEventKey(
        activeEventKeyNotification(activeEventKeyUser()),
        $activeEventKey
    );
    $second = assignActiveEventKey(
        activeEventKeyNotification(activeEventKeyUser()),
        $activeEventKey
    );

    expect($first->active_event_key)->toBe($activeEventKey)
        ->and($second->active_event_key)->toBe($activeEventKey);
});

it('continues enforcing the legacy user and event key uniqueness constraint', function () {
    $user = activeEventKeyUser();
    $notification = activeEventKeyNotification($user);

    expect(fn () => activeEventKeyNotification($user, [
        'event_key' => $notification->event_key,
    ]))->toThrow(QueryException::class)
        ->and(activeEventKeyIndexes()[LEGACY_EVENT_KEY_UNIQUE_INDEX]['unique'])->toBeTrue();
});

it('keeps active event identity guarded from ordinary mass assignment', function () {
    $notification = new FleetNotification;
    $notification->fill([
        'active_event_key' => 'maintenance_due:102:2026-12-03',
    ]);

    expect($notification->getAttributes())->not->toHaveKey('active_event_key');
});

it('does not backfill or change existing notification state', function () {
    $migration = activeEventKeyMigration();
    $migration->down();
    $notification = activeEventKeyNotification(activeEventKeyUser(), [
        'status' => 'Read',
    ]);

    $migration->up();
    $notification->refresh();

    expect($notification->active_event_key)->toBeNull()
        ->and($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull()
        ->and($notification->status)->toBe('Read');
});

it('rolls back structurally without removing notification history', function () {
    $migration = activeEventKeyMigration();
    $notification = activeEventKeyNotification(activeEventKeyUser());
    $originalAttributes = $notification->only([
        'id',
        'user_id',
        'title',
        'message',
        'status',
        'event_key',
        'link',
    ]);

    $migration->down();

    expect(Schema::hasColumn('fleet_notifications', 'active_event_key'))->toBeFalse()
        ->and(activeEventKeyIndexes())->not->toHaveKey(ACTIVE_EVENT_KEY_UNIQUE_INDEX)
        ->and(FleetNotification::query()->findOrFail($notification->id)->only(array_keys($originalAttributes)))
        ->toBe($originalAttributes);

    $migration->up();

    expect(Schema::hasColumn('fleet_notifications', 'active_event_key'))->toBeTrue()
        ->and(activeEventKeyIndexes())->toHaveKey(ACTIVE_EVENT_KEY_UNIQUE_INDEX)
        ->and($notification->fresh()->active_event_key)->toBeNull();
});

it('preserves existing creation retrieval and acknowledgement behavior', function () {
    $user = activeEventKeyUser();
    $this->actingAs($user);
    $notification = FleetNotificationService::create(
        'Existing Notification',
        'Existing behavior remains unchanged.',
        route('dashboard')
    );

    expect($notification?->active_event_key)->toBeNull();

    $this->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonPath('notifications.0.id', $notification?->id)
        ->assertJsonPath('notifications.0.status', 'Unread')
        ->assertJsonPath('unread_count', 1);

    $this->patchJson(route('notifications.read', $notification))
        ->assertSuccessful();

    expect($notification?->fresh()->status)->toBe('Read')
        ->and($notification?->fresh()->active_event_key)->toBeNull();
});

it('remains compatible with explicit resolution metadata', function () {
    $notification = activeEventKeyNotification(activeEventKeyUser());
    $resolvedAt = now()->startOfSecond();
    $notification->resolved_at = $resolvedAt;
    $notification->resolution_reason = 'schedule_changed';
    $notification->save();
    $notification->refresh();

    expect($notification->active_event_key)->toBeNull()
        ->and($notification->resolved_at?->equalTo($resolvedAt))->toBeTrue()
        ->and($notification->resolution_reason)->toBe('schedule_changed')
        ->and($notification->status)->toBe('Unread');
});
