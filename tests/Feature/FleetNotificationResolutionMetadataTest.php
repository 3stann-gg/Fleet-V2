<?php

use App\Models\FleetNotification;
use App\Models\User;
use App\Services\FleetNotificationService;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

const FLEET_NOTIFICATION_RESOLUTION_MIGRATION = '2026_10_30_021016_add_resolution_metadata_to_fleet_notifications_table.php';
const FLEET_NOTIFICATION_USER_RESOLVED_INDEX = 'fleet_notifications_user_resolved_index';
const FLEET_NOTIFICATION_EVENT_RESOLVED_INDEX = 'fleet_notifications_event_resolved_index';

function resolutionMetadataUser(): User
{
    return User::factory()->create(['role' => 'fleet_manager']);
}

function resolutionMetadataNotification(User $user, array $attributes = []): FleetNotification
{
    return FleetNotification::query()->create(array_merge([
        'user_id' => $user->id,
        'title' => 'Maintenance Due Soon',
        'message' => 'A future maintenance service is due soon.',
        'status' => 'Unread',
        'event_key' => fake()->unique()->bothify('maintenance_due:####:2026-12-##'),
        'link' => route('maintenance'),
    ], $attributes));
}

function resolutionMetadataMigration(): Migration
{
    return require database_path('migrations/'.FLEET_NOTIFICATION_RESOLUTION_MIGRATION);
}

function resolutionMetadataIndexes(): Collection
{
    return collect(Schema::getIndexes('fleet_notifications'))->keyBy('name');
}

it('adds nullable resolution metadata columns', function () {
    $columns = collect(Schema::getColumns('fleet_notifications'))->keyBy('name');

    expect($columns)->toHaveKeys(['resolved_at', 'resolution_reason'])
        ->and($columns['resolved_at']['nullable'])->toBeTrue()
        ->and($columns['resolution_reason']['nullable'])->toBeTrue();
});

it('keeps existing style notification rows compatible and unresolved', function () {
    $notification = resolutionMetadataNotification(resolutionMetadataUser());
    $notification->refresh();

    expect($notification->status)->toBe('Unread')
        ->and($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull();
});

it('stores and retrieves explicit resolution metadata with a datetime cast', function () {
    $notification = resolutionMetadataNotification(resolutionMetadataUser());
    $resolvedAt = now()->startOfSecond();
    $notification->resolved_at = $resolvedAt;
    $notification->resolution_reason = 'schedule_changed';
    $notification->save();
    $notification->refresh();

    expect($notification->resolved_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($notification->resolved_at?->equalTo($resolvedAt))->toBeTrue()
        ->and($notification->resolution_reason)->toBe('schedule_changed');
});

it('keeps read acknowledgement independent from resolution metadata', function (string $status) {
    $notification = resolutionMetadataNotification(resolutionMetadataUser(), [
        'status' => $status,
    ]);
    $notification->resolved_at = now();
    $notification->resolution_reason = 'superseded_by_newer_completion';
    $notification->save();
    $notification->refresh();

    expect($notification->status)->toBe($status)
        ->and($notification->resolved_at)->not->toBeNull()
        ->and($notification->resolution_reason)->toBe('superseded_by_newer_completion');
})->with(['Unread', 'Read']);

it('keeps resolution metadata guarded from ordinary mass assignment', function () {
    $notification = new FleetNotification;
    $notification->fill([
        'resolved_at' => now(),
        'resolution_reason' => 'untrusted_reason',
    ]);

    expect($notification->getAttributes())->not->toHaveKeys([
        'resolved_at',
        'resolution_reason',
    ]);
});

it('preserves the unique user and event key constraint', function () {
    $user = resolutionMetadataUser();
    $notification = resolutionMetadataNotification($user);

    expect(fn () => resolutionMetadataNotification($user, [
        'event_key' => $notification->event_key,
    ]))->toThrow(QueryException::class);
});

it('creates the resolution lookup indexes without changing the existing unique index', function () {
    $indexes = resolutionMetadataIndexes();

    expect($indexes)->toHaveKeys([
        FLEET_NOTIFICATION_USER_RESOLVED_INDEX,
        FLEET_NOTIFICATION_EVENT_RESOLVED_INDEX,
        'fleet_notifications_user_id_event_key_unique',
    ])->and($indexes[FLEET_NOTIFICATION_USER_RESOLVED_INDEX]['columns'])
        ->toBe(['user_id', 'resolved_at'])
        ->and($indexes[FLEET_NOTIFICATION_EVENT_RESOLVED_INDEX]['columns'])
        ->toBe(['event_key', 'resolved_at'])
        ->and($indexes['fleet_notifications_user_id_event_key_unique']['unique'])
        ->toBeTrue();
});

it('has a structurally reversible migration', function () {
    $migration = resolutionMetadataMigration();

    $migration->down();

    expect(Schema::hasColumn('fleet_notifications', 'resolved_at'))->toBeFalse()
        ->and(Schema::hasColumn('fleet_notifications', 'resolution_reason'))->toBeFalse()
        ->and(resolutionMetadataIndexes())->not->toHaveKeys([
            FLEET_NOTIFICATION_USER_RESOLVED_INDEX,
            FLEET_NOTIFICATION_EVENT_RESOLVED_INDEX,
        ]);

    $migration->up();

    expect(Schema::hasColumns('fleet_notifications', [
        'resolved_at',
        'resolution_reason',
    ]))->toBeTrue()
        ->and(resolutionMetadataIndexes())->toHaveKeys([
            FLEET_NOTIFICATION_USER_RESOLVED_INDEX,
            FLEET_NOTIFICATION_EVENT_RESOLVED_INDEX,
        ]);
});

it('preserves existing notification creation and retrieval responses', function () {
    $user = resolutionMetadataUser();
    $this->actingAs($user);
    $notification = FleetNotificationService::create(
        'Existing Notification',
        'Existing notification behavior remains available.',
        route('dashboard')
    );

    $response = $this->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonPath('notifications.0.id', $notification?->id)
        ->assertJsonPath('notifications.0.status', 'Unread')
        ->assertJsonPath('unread_count', 1);

    expect(array_keys($response->json('notifications.0')))->toBe([
        'id',
        'user_id',
        'title',
        'message',
        'status',
        'event_key',
        'link',
        'created_at',
        'created_at_timestamp',
    ]);
});

it('does not backfill or resolve rows that existed before the migration', function () {
    $migration = resolutionMetadataMigration();
    $migration->down();
    $notification = resolutionMetadataNotification(resolutionMetadataUser());

    $migration->up();

    $notification->refresh();

    expect($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull()
        ->and($notification->status)->toBe('Unread');
});
