<?php

use App\Models\Driver;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;

function activeNotificationUser(string $role = 'fleet_manager'): User
{
    return User::factory()->create(['role' => $role]);
}

function activeNotification(User $user, array $attributes = []): FleetNotification
{
    return FleetNotification::query()->create(array_merge([
        'user_id' => $user->id,
        'title' => 'Fleet Notification',
        'message' => 'Notification retrieval test.',
        'status' => 'Unread',
        'event_key' => fake()->unique()->bothify('active-notification-########'),
        'link' => route('dashboard'),
    ], $attributes));
}

function resolveActiveNotification(
    FleetNotification $notification,
    string $reason = 'schedule_changed'
): FleetNotification {
    $notification->resolved_at = now();
    $notification->resolution_reason = $reason;
    $notification->save();

    return $notification;
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-30 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('returns active unread and active read notifications with the existing count semantics', function () {
    $user = activeNotificationUser();
    $unread = activeNotification($user, ['status' => 'Unread']);
    $read = activeNotification($user, ['status' => 'Read']);

    $response = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonPath('unread_count', 1);

    expect($response->json('notifications.*.id'))->toBe([$read->id, $unread->id])
        ->and($response->json('notifications.*.status'))->toBe(['Read', 'Unread']);
});

it('excludes resolved unread and resolved read notifications from active results and counts', function () {
    $user = activeNotificationUser();
    $active = activeNotification($user);
    $resolvedUnread = resolveActiveNotification(activeNotification($user, [
        'status' => 'Unread',
    ]));
    $resolvedRead = resolveActiveNotification(activeNotification($user, [
        'status' => 'Read',
    ]));

    $response = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonPath('unread_count', 1);

    expect($response->json('notifications.*.id'))->toBe([$active->id])
        ->not->toContain($resolvedUnread->id, $resolvedRead->id);
});

it('mark all read ignores resolved notifications and preserves their acknowledgement state', function () {
    $user = activeNotificationUser();
    $activeUnread = activeNotification($user, ['status' => 'Unread']);
    $resolvedUnread = resolveActiveNotification(activeNotification($user, [
        'status' => 'Unread',
    ]));
    $resolvedRead = resolveActiveNotification(activeNotification($user, [
        'status' => 'Read',
    ]));

    $this->actingAs($user)
        ->patchJson(route('notifications.readAll'))
        ->assertSuccessful();

    expect($activeUnread->fresh()->status)->toBe('Read')
        ->and($resolvedUnread->fresh()->status)->toBe('Unread')
        ->and($resolvedRead->fresh()->status)->toBe('Read');
});

it('mark all read respects module access and user ownership', function () {
    $user = activeNotificationUser('department_head');
    $otherUser = activeNotificationUser('department_head');
    $accessible = activeNotification($user, [
        'link' => route('dashboard'),
    ]);
    $inaccessible = activeNotification($user, [
        'link' => route('maintenance'),
    ]);
    $otherUsersNotification = activeNotification($otherUser, [
        'link' => route('dashboard'),
    ]);

    $this->actingAs($user)
        ->patchJson(route('notifications.readAll'))
        ->assertSuccessful();

    expect($accessible->fresh()->status)->toBe('Read')
        ->and($inaccessible->fresh()->status)->toBe('Unread')
        ->and($otherUsersNotification->fresh()->status)->toBe('Unread');
});

it('preserves driver notification ownership in retrieval and mark all read', function () {
    $user = activeNotificationUser('driver');
    $driver = Driver::query()->create([
        'user_id' => $user->id,
        'first_name' => 'Assigned',
        'last_name' => 'Driver',
        'license_number' => 'ACTIVE-DL-1',
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000001',
        'status' => 'Available',
    ]);
    $otherDriver = Driver::query()->create([
        'first_name' => 'Other',
        'last_name' => 'Driver',
        'license_number' => 'ACTIVE-DL-2',
        'license_class' => 'Professional',
        'clutch_authorization' => 'AT',
        'license_expiry' => now()->addYear()->toDateString(),
        'contact_number' => '09170000002',
        'status' => 'Available',
    ]);
    $owned = activeNotification($user, [
        'event_key' => "driver_license_expiring:{$driver->id}:2026-12-01",
        'link' => null,
    ]);
    $notOwned = activeNotification($user, [
        'event_key' => "driver_license_expiring:{$otherDriver->id}:2026-12-01",
        'link' => null,
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonPath('unread_count', 1);

    expect($response->json('notifications.*.id'))->toBe([$owned->id]);

    $this->patchJson(route('notifications.readAll'))->assertSuccessful();

    expect($owned->fresh()->status)->toBe('Read')
        ->and($notOwned->fresh()->status)->toBe('Unread');
});

it('keeps the same event key isolated by recipient', function () {
    $firstUser = activeNotificationUser();
    $secondUser = activeNotificationUser();
    $eventKey = 'maintenance_due:42:2026-11-01';
    $first = activeNotification($firstUser, ['event_key' => $eventKey]);
    $second = activeNotification($secondUser, ['event_key' => $eventKey]);

    $response = $this->actingAs($firstUser)
        ->getJson(route('notifications.index'))
        ->assertSuccessful();

    expect($response->json('notifications.*.id'))->toBe([$first->id])
        ->and($response->json('notifications.*.id'))->not->toContain($second->id);

    $this->patchJson(route('notifications.readAll'))->assertSuccessful();

    expect($first->fresh()->status)->toBe('Read')
        ->and($second->fresh()->status)->toBe('Unread');
});

it('finds 30 accessible active rows beyond more than 50 resolved or inaccessible rows', function () {
    $user = activeNotificationUser('department_head');
    $accessibleIds = collect();

    foreach (range(1, 30) as $index) {
        $accessibleIds->push(activeNotification($user, [
            'event_key' => "accessible-{$index}",
            'link' => route('dashboard'),
        ])->id);
    }

    foreach (range(1, 30) as $index) {
        resolveActiveNotification(activeNotification($user, [
            'event_key' => "resolved-{$index}",
            'link' => route('dashboard'),
        ]));
    }

    foreach (range(1, 31) as $index) {
        activeNotification($user, [
            'event_key' => "inaccessible-{$index}",
            'link' => route('maintenance'),
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonCount(30, 'notifications')
        ->assertJsonPath('unread_count', 30);

    expect($response->json('notifications.*.id'))
        ->toBe($accessibleIds->reverse()->values()->all());
});

it('preserves the existing navbar response and browser notification contract', function () {
    $user = activeNotificationUser();
    $notification = activeNotification($user, [
        'title' => 'Browser Contract',
        'message' => 'Visible unread notification.',
        'status' => 'Unread',
    ]);

    $response = $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonStructure([
            'notifications' => [[
                'id',
                'user_id',
                'title',
                'message',
                'status',
                'event_key',
                'link',
                'created_at',
                'created_at_timestamp',
            ]],
            'unread_count',
        ])
        ->assertJsonPath('notifications.0.id', $notification->id)
        ->assertJsonPath('notifications.0.status', 'Unread');

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

it('keeps resolved notifications in dashboard recent activity', function () {
    $user = activeNotificationUser();
    $notification = resolveActiveNotification(activeNotification($user, [
        'title' => 'Resolved Dashboard Activity',
    ]));

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee($notification->title);
});

it('does not invoke automatic maintenance reconciliation during active retrieval', function () {
    $user = activeNotificationUser();
    $vehicle = Vehicle::query()->create([
        'plate_number' => 'ACTIVE-RECON-1',
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Active',
        'model' => 'Retrieval',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ]);
    $maintenance = Maintenance::query()->create([
        'maintenance_number' => 'ACTIVE-MNT-1',
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'No automatic reconciliation test',
        'maintenance_date' => now()->toDateString(),
        'next_schedule' => now()->addDay()->toDateString(),
        'cost' => 0,
        'status' => 'Scheduled',
    ]);
    $oldEventKey = 'maintenance_due:'.$maintenance->id.':'.$maintenance->next_schedule->toDateString();
    $notification = activeNotification($user, ['event_key' => $oldEventKey]);
    $maintenance->update(['next_schedule' => now()->addDays(2)->toDateString()]);

    $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful();

    expect($notification->fresh()->resolved_at)->toBeNull()
        ->and($notification->fresh()->resolution_reason)->toBeNull();
});

it('treats legacy null resolution metadata as active', function () {
    $user = activeNotificationUser();
    $notification = activeNotification($user);

    $this->actingAs($user)
        ->getJson(route('notifications.index'))
        ->assertSuccessful()
        ->assertJsonPath('notifications.0.id', $notification->id)
        ->assertJsonPath('unread_count', 1);

    expect($notification->fresh()->resolved_at)->toBeNull()
        ->and($notification->fresh()->resolution_reason)->toBeNull();
});

it('preserves single acknowledgement authorization and resolution independence', function () {
    $user = activeNotificationUser();
    $otherUser = activeNotificationUser();
    $resolved = resolveActiveNotification(activeNotification($user));
    $otherUsersNotification = activeNotification($otherUser);

    $this->actingAs($user)
        ->patchJson(route('notifications.read', $resolved))
        ->assertSuccessful();

    $this->patchJson(route('notifications.read', $otherUsersNotification))
        ->assertForbidden();

    expect($resolved->fresh()->status)->toBe('Read')
        ->and($resolved->fresh()->resolved_at)->not->toBeNull()
        ->and($otherUsersNotification->fresh()->status)->toBe('Unread');
});
