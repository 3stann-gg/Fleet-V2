<?php

use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceAuthorityChangedException;
use App\Services\MaintenanceNotificationAuthorityEvaluator;
use App\Services\MaintenanceNotificationOccurrenceGuard;
use App\Services\MaintenanceNotificationSourceNotAuthoritativeException;
use App\Services\MaintenanceScheduleResolver;
use App\Services\MySqlDeadlockRetryService;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

function occurrenceAuthorityUser(): User
{
    return User::factory()->create([
        'role' => 'fleet_manager',
        'status' => 'active',
    ]);
}

function occurrenceAuthorityVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('MOA-####'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Occurrence',
        'model' => 'Authority',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ], $attributes));
}

function occurrenceAuthorityMaintenance(
    Vehicle $vehicle,
    array $attributes = []
): Maintenance {
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('MOA-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Occurrence authority test',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => null,
        'next_schedule' => now()->addDays(3)->toDateString(),
        'cost' => 0,
        'status' => 'Scheduled',
    ], $attributes));
}

function occurrenceAuthorityKey(
    Maintenance $maintenance,
    ?string $scheduleDate = null
): string {
    return 'maintenance_due:'.$maintenance->id.':'.(
        $scheduleDate ?? $maintenance->next_schedule?->toDateString()
    );
}

function enableOccurrenceAuthorityFoundation(): MockInterface
{
    $readiness = Mockery::mock(FleetNotificationSchemaReadinessService::class);
    $readiness->shouldReceive('assertOccurrenceActivationReady');
    app()->instance(FleetNotificationSchemaReadinessService::class, $readiness);
    config()->set('fleet-notifications.occurrences.enabled', true);

    return $readiness;
}

function createAuthoritativeOccurrence(
    User $user,
    string $eventKey,
    string $title = 'Maintenance Due Soon'
): ?FleetNotification {
    return FleetNotificationService::createForUserWhenEnabled(
        $user,
        'maintenanceDue',
        $title,
        'Authoritative maintenance reminder.',
        $eventKey,
        true,
        route('maintenance')
    );
}

beforeEach(function () {
    config()->set([
        'fleet-notifications.occurrences.enabled' => false,
        'fleet-notifications.reconciliation.after_commit_enabled' => false,
        'fleet-notifications.reconciliation.scheduled_recovery_enabled' => false,
        'fleet-notifications.reconciliation.historical_enabled' => false,
    ]);
});

it('creates occurrences for authoritative active maintenance', function (string $status) {
    enableOccurrenceAuthorityFoundation();
    $maintenance = occurrenceAuthorityMaintenance(
        occurrenceAuthorityVehicle(),
        ['status' => $status]
    );

    $notification = createAuthoritativeOccurrence(
        occurrenceAuthorityUser(),
        occurrenceAuthorityKey($maintenance)
    );

    expect($notification)->toBeInstanceOf(FleetNotification::class)
        ->and($notification?->event_key)->toBe(occurrenceAuthorityKey($maintenance))
        ->and($notification?->active_event_key)->toBe(occurrenceAuthorityKey($maintenance))
        ->and($notification?->status)->toBe('Unread');
})->with(['Scheduled', 'In Progress']);

it('creates an occurrence only for the resolver-selected completed maintenance', function () {
    enableOccurrenceAuthorityFoundation();
    $vehicle = occurrenceAuthorityVehicle();
    $older = occurrenceAuthorityMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonths(2)->toDateString(),
    ]);
    $latest = occurrenceAuthorityMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $user = occurrenceAuthorityUser();

    expect(createAuthoritativeOccurrence($user, occurrenceAuthorityKey($older)))->toBeNull()
        ->and(createAuthoritativeOccurrence($user, occurrenceAuthorityKey($latest)))
        ->toBeInstanceOf(FleetNotification::class)
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('rejects a stale schedule and keeps the current schedule eligible', function () {
    enableOccurrenceAuthorityFoundation();
    $maintenance = occurrenceAuthorityMaintenance(occurrenceAuthorityVehicle());
    $user = occurrenceAuthorityUser();
    $this->actingAs($user);
    $staleKey = occurrenceAuthorityKey($maintenance);
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);
    $currentKey = occurrenceAuthorityKey($maintenance->fresh());

    $staleResult = FleetNotificationService::createUniqueWhenEnabled(
        'maintenanceDue',
        'Maintenance Due Soon',
        'Stale maintenance reminder.',
        $staleKey,
        true,
        route('maintenance')
    );

    expect($staleResult)->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(0)
        ->and(createAuthoritativeOccurrence($user, $currentKey))
        ->toBeInstanceOf(FleetNotification::class)
        ->and(FleetNotification::query()->where('event_key', $currentKey)->count())->toBe(1);
});

it('fails closed for malformed and missing maintenance sources', function (string $eventKey) {
    enableOccurrenceAuthorityFoundation();
    $user = occurrenceAuthorityUser();

    expect(fn () => app(FleetNotificationKeyedCreationService::class)
        ->createOrUpdateActive(
            $user,
            'Maintenance Due Soon',
            'Invalid source.',
            $eventKey,
            route('maintenance')
        ))->toThrow(MaintenanceNotificationSourceNotAuthoritativeException::class)
        ->and(createAuthoritativeOccurrence($user, $eventKey))->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(0);
})->with([
    'malformed' => 'maintenance_due:not-an-id:2026-12-01',
    'missing' => 'maintenance_due:999999:2026-12-01',
]);

it('preserves acknowledgement when updating a valid active occurrence', function () {
    enableOccurrenceAuthorityFoundation();
    $maintenance = occurrenceAuthorityMaintenance(occurrenceAuthorityVehicle());
    $user = occurrenceAuthorityUser();
    $eventKey = occurrenceAuthorityKey($maintenance);
    $first = createAuthoritativeOccurrence($user, $eventKey);
    $first?->update(['status' => 'Read']);

    $updated = createAuthoritativeOccurrence($user, $eventKey, 'Maintenance Overdue');

    expect($updated?->id)->toBe($first?->id)
        ->and($updated?->status)->toBe('Read')
        ->and($updated?->title)->toBe('Maintenance Overdue')
        ->and(FleetNotification::query()->count())->toBe(1);
});

it('rejects obsolete authority without notification or audit side effects', function () {
    enableOccurrenceAuthorityFoundation();
    $maintenance = occurrenceAuthorityMaintenance(occurrenceAuthorityVehicle());
    $eventKey = occurrenceAuthorityKey($maintenance);
    $maintenance->update(['status' => 'Cancelled']);

    expect(createAuthoritativeOccurrence(occurrenceAuthorityUser(), $eventKey))->toBeNull()
        ->and(FleetNotification::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('leaves final rollback ownership with an ambient transaction', function () {
    enableOccurrenceAuthorityFoundation();
    $maintenance = occurrenceAuthorityMaintenance(occurrenceAuthorityVehicle());
    $user = occurrenceAuthorityUser();
    $eventKey = occurrenceAuthorityKey($maintenance);

    DB::beginTransaction();
    try {
        $notification = createAuthoritativeOccurrence($user, $eventKey);
        expect($notification)->toBeInstanceOf(FleetNotification::class);
    } finally {
        DB::rollBack();
    }

    expect(FleetNotification::query()->where('event_key', $eventKey)->exists())
        ->toBeFalse();
});

it('keeps generic keyed notification producers compatible in occurrence mode', function (
    string $setting,
    string $eventKey,
    string $link
) {
    enableOccurrenceAuthorityFoundation();
    $user = occurrenceAuthorityUser();

    $notification = FleetNotificationService::createForUserWhenEnabled(
        $user,
        $setting,
        'Generic keyed event',
        'Generic keyed notification compatibility.',
        $eventKey,
        true,
        $link
    );

    expect($notification)->toBeInstanceOf(FleetNotification::class)
        ->and($notification?->active_event_key)->toBe($eventKey);
})->with([
    'driver license' => [
        'licenseExpiring',
        'driver_license_expiring:42:2026-12-01',
        '/driver',
    ],
    'dispatch' => [
        'dispatchUpdates',
        'dispatch:42:assigned',
        '/dispatch',
    ],
]);

it('preserves legacy maintenance creation while occurrence mode is disabled', function () {
    $user = occurrenceAuthorityUser();
    $eventKey = 'maintenance_due:999999:2026-12-01';

    $notification = createAuthoritativeOccurrence($user, $eventKey);

    expect($notification)->toBeInstanceOf(FleetNotification::class)
        ->and($notification?->event_key)->toBe($eventKey)
        ->and($notification?->active_event_key)->toBeNull();
});

it('fails closed on stale vehicle discovery inside an ambient transaction', function () {
    $evaluator = new class(app(MaintenanceScheduleResolver::class)) extends MaintenanceNotificationAuthorityEvaluator
    {
        public int $discoveries = 0;

        public function discoverVehicleId(string $eventKey): ?int
        {
            $this->discoveries++;

            return 10;
        }

        public function evaluateEventKeyForUpdate(string $eventKey, int $expectedVehicleId): array
        {
            throw new MaintenanceAuthorityChangedException('Vehicle changed during locking.');
        }
    };
    $guard = new MaintenanceNotificationOccurrenceGuard(
        $evaluator,
        app(MySqlDeadlockRetryService::class)
    );
    $operations = 0;

    expect(fn () => $guard->run(
        'maintenance_due:1:2026-12-01',
        function () use (&$operations): string {
            $operations++;

            return 'created';
        }
    ))->toThrow(
        MaintenanceNotificationSourceNotAuthoritativeException::class,
        'source_vehicle_changed'
    )->and($evaluator->discoveries)->toBe(1)
        ->and($operations)->toBe(0);
});

it('does not swallow unrelated notification errors', function () {
    enableOccurrenceAuthorityFoundation();
    $maintenance = occurrenceAuthorityMaintenance(occurrenceAuthorityVehicle());
    $failure = new LogicException('Unexpected notification failure.');
    $service = Mockery::mock(FleetNotificationKeyedCreationService::class);
    $service->shouldReceive('createOrUpdateActive')->once()->andThrow($failure);
    app()->instance(FleetNotificationKeyedCreationService::class, $service);

    expect(fn () => createAuthoritativeOccurrence(
        occurrenceAuthorityUser(),
        occurrenceAuthorityKey($maintenance)
    ))->toThrow($failure);
});
