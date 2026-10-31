<?php

use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogService;
use App\Services\FleetNotificationActiveEventService;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceNotificationReconciliationService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

const TAE_LEGACY_UNIQUE_INDEX =
    'fleet_notifications_user_id_event_key_unique';

function trustedActiveEventUser(string $role = 'fleet_manager'): User
{
    return User::factory()->create(['role' => $role]);
}

function trustedActiveEventNotification(
    User $user,
    string $eventKey,
    array $attributes = []
): FleetNotification {
    return FleetNotification::query()->create(array_merge([
        'user_id' => $user->id,
        'title' => 'Maintenance Due Soon',
        'message' => 'Trusted active event test.',
        'status' => 'Unread',
        'event_key' => $eventKey,
        'link' => route('maintenance'),
    ], $attributes));
}

function trustedActiveEventService(): FleetNotificationActiveEventService
{
    return app(FleetNotificationActiveEventService::class);
}

function trustedActiveEventVehicle(): Vehicle
{
    return Vehicle::query()->create([
        'plate_number' => fake()->unique()->bothify('TAE-####'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Trusted',
        'model' => 'Active Event',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ]);
}

function trustedActiveEventMaintenance(Vehicle $vehicle): Maintenance
{
    return Maintenance::query()->create([
        'maintenance_number' => fake()->unique()->bothify('TAE-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Trusted active event maintenance',
        'maintenance_date' => now()->toDateString(),
        'next_schedule' => now()->addDay()->toDateString(),
        'cost' => 0,
        'status' => 'Scheduled',
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-30 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('assigns a trusted canonical maintenance event key without changing notification state', function () {
    $user = trustedActiveEventUser();
    $eventKey = 'maintenance_due:100:2026-12-01';
    $notification = trustedActiveEventNotification($user, $eventKey, [
        'status' => 'Read',
    ]);
    $originalAttributes = $notification->only([
        'user_id',
        'title',
        'message',
        'status',
        'event_key',
        'link',
    ]);

    $assigned = trustedActiveEventService()
        ->assignMaintenanceEventKey($notification, $eventKey);

    expect($assigned->active_event_key)->toBe($eventKey)
        ->and($assigned->only(array_keys($originalAttributes)))->toBe($originalAttributes)
        ->and($assigned->resolved_at)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('rejects invalid canonical maintenance event keys', function (string $eventKey) {
    $notification = trustedActiveEventNotification(
        trustedActiveEventUser(),
        'maintenance_due:101:2026-12-02'
    );

    expect(fn () => trustedActiveEventService()
        ->assignMaintenanceEventKey($notification, $eventKey))
        ->toThrow(InvalidArgumentException::class)
        ->and($notification->fresh()->active_event_key)->toBeNull();
})->with([
    'wrong type' => 'driver_license_expiring:101:2026-12-02',
    'zero id' => 'maintenance_due:0:2026-12-02',
    'invalid date' => 'maintenance_due:101:2026-02-30',
    'extra suffix' => 'maintenance_due:101:2026-12-02:occurrence:2',
]);

it('requires the active key to match the stored canonical event identity', function () {
    $notification = trustedActiveEventNotification(
        trustedActiveEventUser(),
        'maintenance_due:102:2026-12-03'
    );

    expect(fn () => trustedActiveEventService()->assignMaintenanceEventKey(
        $notification,
        'maintenance_due:103:2026-12-03'
    ))->toThrow(InvalidArgumentException::class)
        ->and($notification->fresh()->active_event_key)->toBeNull();
});

it('rejects an existing active occurrence collision for the same user', function () {
    $user = trustedActiveEventUser();
    $canonicalEventKey = 'maintenance_due:104:2026-12-04';

    Schema::table('fleet_notifications', function ($table) {
        $table->dropUnique(TAE_LEGACY_UNIQUE_INDEX);
    });

    $candidate = null;

    try {
        $existing = trustedActiveEventNotification($user, $canonicalEventKey);
        $existing->active_event_key = $canonicalEventKey;
        $existing->save();
        $candidate = trustedActiveEventNotification($user, $canonicalEventKey);

        expect(fn () => trustedActiveEventService()
            ->assignMaintenanceEventKey($candidate, $canonicalEventKey))
            ->toThrow(LogicException::class)
            ->and($existing->fresh()->event_key)->toBe($canonicalEventKey)
            ->and($existing->fresh()->active_event_key)->toBe($canonicalEventKey)
            ->and($existing->fresh()->resolved_at)->toBeNull()
            ->and($candidate->fresh()->active_event_key)->toBeNull();
    } finally {
        $candidate?->delete();

        Schema::table('fleet_notifications', function ($table) {
            $table->unique(
                ['user_id', 'event_key'],
                TAE_LEGACY_UNIQUE_INDEX
            );
        });
    }
});

it('does not accept a collision winner with a mismatched canonical key', function () {
    $user = trustedActiveEventUser();
    $canonicalEventKey = 'maintenance_due:112:2026-12-12';
    $existing = trustedActiveEventNotification(
        $user,
        'maintenance_due:999:2026-12-12'
    );
    $existing->active_event_key = $canonicalEventKey;
    $existing->save();
    $candidate = trustedActiveEventNotification($user, $canonicalEventKey);

    expect(fn () => trustedActiveEventService()
        ->assignMaintenanceEventKey($candidate, $canonicalEventKey))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and($existing->fresh()->active_event_key)->toBe($canonicalEventKey)
        ->and($candidate->fresh()->active_event_key)->toBeNull();
});

it('does not accept a resolved collision winner', function () {
    $user = trustedActiveEventUser();
    $canonicalEventKey = 'maintenance_due:113:2026-12-13';

    Schema::table('fleet_notifications', function ($table) {
        $table->dropUnique(TAE_LEGACY_UNIQUE_INDEX);
    });

    $candidate = null;

    try {
        $existing = trustedActiveEventNotification($user, $canonicalEventKey);
        $existing->forceFill([
            'active_event_key' => $canonicalEventKey,
            'resolved_at' => now(),
            'resolution_reason' => 'schedule_changed',
        ])->save();
        $candidate = trustedActiveEventNotification($user, $canonicalEventKey);

        expect(fn () => trustedActiveEventService()
            ->assignMaintenanceEventKey($candidate, $canonicalEventKey))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and($existing->fresh()->resolved_at)->not->toBeNull()
            ->and($existing->fresh()->active_event_key)->toBe($canonicalEventKey)
            ->and($candidate->fresh()->active_event_key)->toBeNull();
    } finally {
        $candidate?->delete();

        Schema::table('fleet_notifications', function ($table) {
            $table->unique(
                ['user_id', 'event_key'],
                TAE_LEGACY_UNIQUE_INDEX
            );
        });
    }
});

it('allows different users to hold the same active event identity', function () {
    $eventKey = 'maintenance_due:105:2026-12-05';
    $first = trustedActiveEventNotification(trustedActiveEventUser(), $eventKey);
    $second = trustedActiveEventNotification(trustedActiveEventUser(), $eventKey);

    trustedActiveEventService()->assignMaintenanceEventKey($first, $eventKey);
    trustedActiveEventService()->assignMaintenanceEventKey($second, $eventKey);

    expect($first->fresh()->active_event_key)->toBe($eventKey)
        ->and($second->fresh()->active_event_key)->toBe($eventKey);
});

it('refuses to assign an active key to a resolved notification', function () {
    $eventKey = 'maintenance_due:106:2026-12-06';
    $notification = trustedActiveEventNotification(
        trustedActiveEventUser(),
        $eventKey,
        ['status' => 'Read']
    );
    $notification->resolved_at = now();
    $notification->resolution_reason = 'schedule_changed';
    $notification->save();

    expect(fn () => trustedActiveEventService()
        ->assignMaintenanceEventKey($notification, $eventKey))
        ->toThrow(LogicException::class)
        ->and($notification->fresh()->active_event_key)->toBeNull()
        ->and($notification->fresh()->status)->toBe('Read')
        ->and($notification->fresh()->resolution_reason)->toBe('schedule_changed');
});

it('preserves read acknowledgement during trusted assignment', function (string $status) {
    $eventKey = 'maintenance_due:107:2026-12-07';
    $notification = trustedActiveEventNotification(
        trustedActiveEventUser(),
        $eventKey,
        ['status' => $status]
    );

    trustedActiveEventService()->assignMaintenanceEventKey(
        $notification,
        $eventKey
    );

    expect($notification->fresh()->status)->toBe($status);
})->with(['Unread', 'Read']);

it('is idempotent for the same notification and active key', function () {
    $eventKey = 'maintenance_due:108:2026-12-08';
    $notification = trustedActiveEventNotification(
        trustedActiveEventUser(),
        $eventKey
    );

    $first = trustedActiveEventService()
        ->assignMaintenanceEventKey($notification, $eventKey);
    $second = trustedActiveEventService()
        ->assignMaintenanceEventKey($notification, $eventKey);

    expect($second->id)->toBe($first->id)
        ->and($second->active_event_key)->toBe($eventKey)
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('keeps existing unique creation dormant and does not create a new occurrence', function () {
    $user = trustedActiveEventUser();
    $this->actingAs($user);
    $eventKey = 'maintenance_due:109:2026-12-09';
    $notification = FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'Original occurrence.',
        $eventKey,
        true,
        route('maintenance')
    );
    $notification->resolved_at = now();
    $notification->resolution_reason = 'schedule_changed';
    $notification->save();

    $existing = FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'Repeated creation remains dormant.',
        $eventKey,
        true,
        route('maintenance')
    );

    expect($existing?->id)->toBe($notification->id)
        ->and($existing?->fresh()->resolved_at)->not->toBeNull()
        ->and($existing?->fresh()->active_event_key)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('atomically clears the active key when dormant reconciliation resolves an event', function () {
    $user = trustedActiveEventUser();
    $maintenance = trustedActiveEventMaintenance(trustedActiveEventVehicle());
    $eventKey = 'maintenance_due:'.$maintenance->id.':'.$maintenance->next_schedule->toDateString();
    $notification = trustedActiveEventNotification($user, $eventKey, [
        'status' => 'Read',
    ]);
    trustedActiveEventService()->assignMaintenanceEventKey(
        $notification,
        $eventKey
    );
    $preservedAttributes = $notification->fresh()->only([
        'user_id',
        'title',
        'message',
        'status',
        'event_key',
        'link',
    ]);
    $maintenance->update([
        'next_schedule' => now()->addDays(2)->toDateString(),
    ]);

    $result = app(MaintenanceNotificationReconciliationService::class)
        ->reconcileEventKey($eventKey);
    $notification->refresh();

    expect($result['resolved_count'])->toBe(1)
        ->and($notification->resolved_at)->not->toBeNull()
        ->and($notification->resolution_reason)->toBe('schedule_changed')
        ->and($notification->active_event_key)->toBeNull()
        ->and($notification->only(array_keys($preservedAttributes)))
        ->toBe($preservedAttributes)
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('records an explicit system actor without attributing the authenticated user', function () {
    $authenticatedUser = trustedActiveEventUser();
    $this->actingAs($authenticatedUser);
    $notification = trustedActiveEventNotification(
        $authenticatedUser,
        'maintenance_due:110:2026-12-10'
    );

    $auditLog = AuditLogService::logSystem(
        module: 'Fleet Notifications',
        action: 'Active Event Assigned',
        description: 'Assigned a dormant active notification event key.',
        record: $notification,
        oldValues: [
            'active_event_key' => null,
            'password' => 'must-not-be-logged',
        ],
        newValues: [
            'active_event_key' => $notification->event_key,
            'nested' => ['token' => 'must-not-be-logged'],
        ]
    );

    expect($auditLog->user_id)->toBeNull()
        ->and($auditLog->user_name)->toBeNull()
        ->and($auditLog->user_email)->toBeNull()
        ->and($auditLog->user_role)->toBe('system')
        ->and($auditLog->ip_address)->toBeNull()
        ->and($auditLog->user_agent)->toBeNull()
        ->and($auditLog->request_method)->toBeNull()
        ->and($auditLog->request_path)->toBeNull()
        ->and($auditLog->auditable_type)->toBe(FleetNotification::class)
        ->and($auditLog->auditable_id)->toBe($notification->id)
        ->and($auditLog->old_values)->not->toHaveKey('password')
        ->and($auditLog->new_values['nested'])->not->toHaveKey('token');
});

it('preserves existing authenticated user audit attribution', function () {
    $user = trustedActiveEventUser();
    $this->actingAs($user);
    $notification = trustedActiveEventNotification(
        $user,
        'maintenance_due:111:2026-12-11'
    );

    $auditLog = AuditLogService::log(
        module: 'Fleet Notifications',
        action: 'Existing User Action',
        description: 'Existing audit attribution remains unchanged.',
        record: $notification
    );

    expect($auditLog->user_id)->toBe($user->id)
        ->and($auditLog->user_name)->toBe($user->name)
        ->and($auditLog->user_email)->toBe($user->email)
        ->and($auditLog->user_role)->toBe($user->role)
        ->and(AuditLog::query()->count())->toBe(1);
});
