<?php

use App\Models\AuditLog;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\MaintenanceNotificationAuthorityEvaluator;
use App\Services\MaintenanceNotificationReconciliationService;
use App\Services\MaintenanceScheduleResolver;
use App\Services\MySqlDeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function reconciliationVehicle(array $attributes = []): Vehicle
{
    return Vehicle::query()->create(array_merge([
        'plate_number' => fake()->unique()->bothify('MNR-####'),
        'vehicle_type' => 'Service Vehicle',
        'brand' => 'Reconciliation',
        'model' => 'Vehicle',
        'capacity' => 4,
        'fuel_type' => 'Gasoline',
        'status' => 'Available',
    ], $attributes));
}

function reconciliationMaintenance(Vehicle $vehicle, array $attributes = []): Maintenance
{
    return Maintenance::query()->create(array_merge([
        'maintenance_number' => fake()->unique()->bothify('MNR-MNT-####'),
        'vehicle_id' => $vehicle->id,
        'request_type' => 'Normal',
        'maintenance_type' => 'Preventive Maintenance',
        'description' => 'Reconciliation test maintenance',
        'maintenance_date' => now()->subMonth()->toDateString(),
        'completion_date' => null,
        'next_schedule' => now()->addDays(3)->toDateString(),
        'cost' => 0,
        'status' => 'Scheduled',
    ], $attributes));
}

function reconciliationEventKey(Maintenance $maintenance, ?string $scheduleDate = null): string
{
    return 'maintenance_due:'.$maintenance->id.':'.(
        $scheduleDate ?? $maintenance->next_schedule?->toDateString()
    );
}

function reconciliationNotification(
    string $eventKey,
    string $status = 'Unread',
    ?User $user = null
): FleetNotification {
    return FleetNotification::query()->create([
        'user_id' => ($user ?? User::factory()->create())->id,
        'title' => 'Maintenance Due Soon',
        'message' => 'Maintenance reminder.',
        'status' => $status,
        'event_key' => $eventKey,
        'link' => '/maintenance',
    ]);
}

function reconciliationService(): MaintenanceNotificationReconciliationService
{
    return app(MaintenanceNotificationReconciliationService::class);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-30 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('resolves an event whose next schedule changed', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);

    $result = reconciliationService()->reconcileEventKey($eventKey);

    expect($result)->toMatchArray([
        'verified' => true,
        'authoritative' => false,
        'resolution_reason' => 'schedule_changed',
        'resolved_count' => 1,
    ])->and($notification->fresh()->resolution_reason)->toBe('schedule_changed')
        ->and($notification->fresh()->resolved_at)->not->toBeNull();
});

it('resolves an event whose next schedule was cleared', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $maintenance->update(['next_schedule' => null]);

    reconciliationService()->reconcileEventKey($eventKey);

    expect($notification->fresh()->resolution_reason)->toBe('schedule_cleared');
});

it('resolves an event when its maintenance is cancelled', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $maintenance->update(['status' => 'Cancelled']);

    reconciliationService()->reconcileEventKey($eventKey);

    expect($notification->fresh()->resolution_reason)->toBe('maintenance_cancelled');
});

it('resolves a completed event superseded by a newer completion', function () {
    $vehicle = reconciliationVehicle();
    $older = reconciliationMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonths(2)->toDateString(),
    ]);
    $eventKey = reconciliationEventKey($older);
    $notification = reconciliationNotification($eventKey);
    reconciliationMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);

    reconciliationService()->reconcileEventKey($eventKey);

    expect($notification->fresh()->resolution_reason)
        ->toBe('superseded_by_newer_completion');
});

it('leaves the authoritative completed maintenance event active', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle(), [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);

    $result = reconciliationService()->reconcileEventKey($eventKey);

    expect(array_keys($result))->toBe([
        'event_key',
        'verified',
        'authoritative',
        'resolution_reason',
        'resolved_count',
    ])->and($result['authoritative'])->toBeTrue()
        ->and($result['resolution_reason'])->toBeNull()
        ->and($notification->fresh()->resolved_at)->toBeNull();
});

it('uses the highest id when completed records share a completion date', function () {
    $vehicle = reconciliationVehicle();
    $completionDate = now()->subMonth()->toDateString();
    $lowerId = reconciliationMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => $completionDate,
    ]);
    $higherId = reconciliationMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => $completionDate,
    ]);
    $lowerNotification = reconciliationNotification(reconciliationEventKey($lowerId));
    $higherNotification = reconciliationNotification(reconciliationEventKey($higherId));

    reconciliationService()->reconcileEventKeys([
        $lowerNotification->event_key,
        $higherNotification->event_key,
    ]);

    expect($lowerNotification->fresh()->resolution_reason)
        ->toBe('superseded_by_newer_completion')
        ->and($higherNotification->fresh()->resolved_at)->toBeNull();
});

it('resolves the prior event when the authoritative next schedule is null', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle(), [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $maintenance->update(['next_schedule' => null]);

    reconciliationService()->reconcileEventKey($eventKey);

    expect($notification->fresh()->resolution_reason)->toBe('schedule_cleared');
});

it('preserves authority for an archived completed maintenance', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle(), [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
        'archived_at' => now(),
    ]);
    $notification = reconciliationNotification(reconciliationEventKey($maintenance));

    reconciliationService()->reconcileEventKey($notification->event_key);

    expect($notification->fresh()->resolved_at)->toBeNull();
});

it('leaves active maintenance reminders authoritative', function (string $status) {
    $maintenance = reconciliationMaintenance(reconciliationVehicle(), ['status' => $status]);
    $notification = reconciliationNotification(reconciliationEventKey($maintenance));

    $result = reconciliationService()->reconcileEventKey($notification->event_key);

    expect($result['authoritative'])->toBeTrue()
        ->and($notification->fresh()->resolved_at)->toBeNull();
})->with(['Scheduled', 'In Progress']);

it('allows active and completed reminders to coexist for one vehicle', function () {
    $vehicle = reconciliationVehicle();
    $completed = reconciliationMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $active = reconciliationMaintenance($vehicle, [
        'status' => 'Scheduled',
        'next_schedule' => now()->addDays(2)->toDateString(),
    ]);
    $completedNotification = reconciliationNotification(reconciliationEventKey($completed));
    $activeNotification = reconciliationNotification(reconciliationEventKey($active));

    reconciliationService()->reconcileEventKeys([
        $completedNotification->event_key,
        $activeNotification->event_key,
    ]);

    expect($completedNotification->fresh()->resolved_at)->toBeNull()
        ->and($activeNotification->fresh()->resolved_at)->toBeNull();
});

it('resolves every recipient while preserving independent read states', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $unread = reconciliationNotification($eventKey, 'Unread');
    $read = reconciliationNotification($eventKey, 'Read');
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);

    $result = reconciliationService()->reconcileEventKey($eventKey);

    expect($result['resolved_count'])->toBe(2)
        ->and($unread->fresh()->status)->toBe('Unread')
        ->and($read->fresh()->status)->toBe('Read')
        ->and($unread->fresh()->resolved_at)->not->toBeNull()
        ->and($read->fresh()->resolved_at)->not->toBeNull();
});

it('audits each resolved recipient without changing acknowledgement state', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $unread = reconciliationNotification($eventKey, 'Unread');
    $read = reconciliationNotification($eventKey, 'Read');
    $unread->active_event_key = $eventKey;
    $unread->save();
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);
    $resolvedAt = Carbon::parse('2026-10-30 11:30:00');

    reconciliationService()->reconcileEventKey($eventKey, $resolvedAt);

    $audits = AuditLog::query()
        ->where('module', 'Fleet Notifications')
        ->where('action', 'Notification Resolved')
        ->orderBy('auditable_id')
        ->get();

    expect($audits)->toHaveCount(2)
        ->and($audits->pluck('auditable_id')->all())->toBe([
            $unread->id,
            $read->id,
        ])
        ->and($audits->every(fn (AuditLog $audit): bool => $audit->user_id === null &&
            $audit->user_role === 'system' &&
            $audit->ip_address === null &&
            $audit->request_path === null
        ))->toBeTrue()
        ->and($audits[0]->old_values)->toMatchArray([
            'notification_id' => $unread->id,
            'user_id' => $unread->user_id,
            'event_key' => $eventKey,
            'status' => 'Unread',
            'resolved_at' => null,
            'resolution_reason' => null,
            'active_event_key' => $eventKey,
        ])
        ->and($audits[0]->new_values)->toMatchArray([
            'notification_id' => $unread->id,
            'user_id' => $unread->user_id,
            'event_key' => $eventKey,
            'status' => 'Unread',
            'resolved_at' => $resolvedAt->format('Y-m-d H:i:s'),
            'resolution_reason' => 'schedule_changed',
            'active_event_key' => null,
        ])
        ->and($unread->fresh()->status)->toBe('Unread')
        ->and($read->fresh()->status)->toBe('Read')
        ->and($unread->fresh()->active_event_key)->toBeNull();
});

it('does not add resolution audits for authoritative or already resolved events', function () {
    $authoritative = reconciliationMaintenance(reconciliationVehicle());
    $authoritativeNotification = reconciliationNotification(
        reconciliationEventKey($authoritative)
    );

    reconciliationService()->reconcileEventKey(
        $authoritativeNotification->event_key
    );

    $obsolete = reconciliationMaintenance(reconciliationVehicle());
    $obsoleteEventKey = reconciliationEventKey($obsolete);
    reconciliationNotification($obsoleteEventKey);
    $obsolete->update(['next_schedule' => now()->addWeek()->toDateString()]);

    reconciliationService()->reconcileEventKey($obsoleteEventKey);
    reconciliationService()->reconcileEventKey($obsoleteEventKey);

    expect(AuditLog::query()
        ->where('module', 'Fleet Notifications')
        ->where('action', 'Notification Resolved')
        ->count())->toBe(1);
});

it('rolls back resolution changes when system audit persistence fails', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);

    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_notification_resolution_audit
        BEFORE INSERT ON audit_logs
        WHEN NEW.action = 'Notification Resolved'
        BEGIN
            SELECT RAISE(ABORT, 'forced audit failure');
        END
        SQL);

    try {
        expect(fn () => reconciliationService()->reconcileEventKey($eventKey))
            ->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS fail_notification_resolution_audit');
    }

    $notification->refresh();

    expect($notification->resolved_at)->toBeNull()
        ->and($notification->resolution_reason)->toBeNull()
        ->and($notification->active_event_key)->toBe($eventKey)
        ->and(AuditLog::query()
            ->where('action', 'Notification Resolved')
            ->count())->toBe(0);
});

it('is idempotent and does not modify an already resolved event', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);
    $firstResolvedAt = Carbon::parse('2026-10-30 11:00:00');

    $first = reconciliationService()->reconcileEventKey($eventKey, $firstResolvedAt);
    $second = reconciliationService()->reconcileEventKey(
        $eventKey,
        Carbon::parse('2026-10-30 12:00:00')
    );

    expect($first['resolved_count'])->toBe(1)
        ->and($second['resolved_count'])->toBe(0)
        ->and($notification->fresh()->resolved_at?->equalTo($firstResolvedAt))->toBeTrue()
        ->and($notification->fresh()->resolution_reason)->toBe('schedule_changed');
});

it('preserves prior metadata while conditionally resolving other recipients', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $eventKey = reconciliationEventKey($maintenance);
    $previousResolvedAt = Carbon::parse('2026-10-29 09:00:00');
    $alreadyResolved = reconciliationNotification($eventKey, 'Read');
    $alreadyResolved->resolved_at = $previousResolvedAt;
    $alreadyResolved->resolution_reason = 'maintenance_cancelled';
    $alreadyResolved->save();
    $unresolved = reconciliationNotification($eventKey, 'Unread');
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);
    $newResolvedAt = Carbon::parse('2026-10-30 13:00:00');

    $result = reconciliationService()->reconcileEventKey($eventKey, $newResolvedAt);

    expect($result['resolved_count'])->toBe(1)
        ->and($alreadyResolved->fresh()->resolved_at?->equalTo($previousResolvedAt))->toBeTrue()
        ->and($alreadyResolved->fresh()->resolution_reason)->toBe('maintenance_cancelled')
        ->and($alreadyResolved->fresh()->status)->toBe('Read')
        ->and($unresolved->fresh()->resolved_at?->equalTo($newResolvedAt))->toBeTrue()
        ->and($unresolved->fresh()->resolution_reason)->toBe('schedule_changed')
        ->and($unresolved->fresh()->status)->toBe('Unread');
});

it('treats malformed and invalid-date event keys conservatively', function (string $eventKey) {
    $notification = reconciliationNotification($eventKey);

    $result = reconciliationService()->reconcileEventKey($eventKey);

    expect($result['verified'])->toBeFalse()
        ->and($result['authoritative'])->toBeNull()
        ->and($result['resolved_count'])->toBe(0)
        ->and($notification->fresh()->resolved_at)->toBeNull();
})->with([
    'wrong event type' => 'maintenance:1:2026-11-01',
    'broad suffix' => 'maintenance_due:1:2026-11-01:extra',
    'invalid date' => 'maintenance_due:1:2026-02-30',
    'invalid id' => 'maintenance_due:0:2026-11-01',
]);

it('does not modify an event for an unknown maintenance id', function () {
    $eventKey = 'maintenance_due:999999:2026-11-01';
    $notification = reconciliationNotification($eventKey);

    $result = reconciliationService()->reconcileEventKey($eventKey);

    expect($result['verified'])->toBeFalse()
        ->and($notification->fresh()->resolved_at)->toBeNull();
});

it('does not resolve another vehicle or maintenance event', function () {
    $changed = reconciliationMaintenance(reconciliationVehicle());
    $unchanged = reconciliationMaintenance(reconciliationVehicle());
    $changedEventKey = reconciliationEventKey($changed);
    $changedNotification = reconciliationNotification($changedEventKey);
    $unchangedNotification = reconciliationNotification(reconciliationEventKey($unchanged));
    $changed->update(['next_schedule' => now()->addWeek()->toDateString()]);

    reconciliationService()->reconcileEventKey($changedEventKey);

    expect($changedNotification->fresh()->resolved_at)->not->toBeNull()
        ->and($unchangedNotification->fresh()->resolved_at)->toBeNull();
});

it('does not modify unrelated notification types', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $maintenanceEventKey = reconciliationEventKey($maintenance);
    reconciliationNotification($maintenanceEventKey);
    $unrelated = reconciliationNotification('vehicle_registration_due:42:2026-11-01');
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);

    reconciliationService()->reconcileEventKeys([
        $maintenanceEventKey,
        $unrelated->event_key,
    ]);

    expect($unrelated->fresh()->resolved_at)->toBeNull()
        ->and($unrelated->fresh()->resolution_reason)->toBeNull();
});

it('does not automatically reactivate a resolved exact event', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $originalSchedule = $maintenance->next_schedule->toDateString();
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);
    reconciliationService()->reconcileEventKey($eventKey);
    $resolvedAt = $notification->fresh()->resolved_at;
    $maintenance->update(['next_schedule' => $originalSchedule]);

    $result = reconciliationService()->reconcileEventKey($eventKey);

    expect($result['authoritative'])->toBeTrue()
        ->and($result['resolved_count'])->toBe(0)
        ->and($notification->fresh()->resolved_at?->equalTo($resolvedAt))->toBeTrue()
        ->and($notification->fresh()->resolution_reason)->toBe('schedule_changed');
});

it('keeps the same key active when active maintenance becomes authoritative completed work', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $notification = reconciliationNotification(reconciliationEventKey($maintenance));
    $maintenance->update([
        'status' => 'Completed',
        'completion_date' => now()->toDateString(),
    ]);

    $result = reconciliationService()->reconcileEventKey($notification->event_key);

    expect($result['authoritative'])->toBeTrue()
        ->and($notification->fresh()->resolved_at)->toBeNull();
});

it('preserves legacy null completion-date selection behavior', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle(), [
        'status' => 'Completed',
        'completion_date' => null,
    ]);
    $notification = reconciliationNotification(reconciliationEventKey($maintenance));

    $result = reconciliationService()->reconcileEventKey($notification->event_key);

    expect($result['resolution_reason'])->toBe('source_no_longer_eligible')
        ->and($notification->fresh()->resolution_reason)->toBe('source_no_longer_eligible');
});

it('uses bounded short locking transactions for multiple event sources', function () {
    $first = reconciliationMaintenance(reconciliationVehicle());
    $second = reconciliationMaintenance(reconciliationVehicle());
    $selectQueries = 0;

    DB::listen(function ($query) use (&$selectQueries): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selectQueries++;
        }
    });

    reconciliationService()->reconcileEventKeys([
        reconciliationEventKey($first),
        reconciliationEventKey($second),
    ]);

    expect($selectQueries)->toBeLessThanOrEqual(8);
});

it('revalidates a restored schedule after vehicle discovery', function () {
    $maintenance = reconciliationMaintenance(reconciliationVehicle());
    $originalSchedule = $maintenance->next_schedule->toDateString();
    $eventKey = reconciliationEventKey($maintenance);
    $notification = reconciliationNotification($eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $maintenance->update(['next_schedule' => now()->addWeek()->toDateString()]);
    $evaluator = new class(app(MaintenanceScheduleResolver::class), $maintenance, $originalSchedule) extends MaintenanceNotificationAuthorityEvaluator
    {
        public function __construct(
            MaintenanceScheduleResolver $resolver,
            private Maintenance $maintenance,
            private string $originalSchedule
        ) {
            parent::__construct($resolver);
        }

        public function discoverVehicleId(string $eventKey): ?int
        {
            $vehicleId = parent::discoverVehicleId($eventKey);
            $this->maintenance->update(['next_schedule' => $this->originalSchedule]);

            return $vehicleId;
        }
    };
    $service = new MaintenanceNotificationReconciliationService(
        $evaluator,
        app(MySqlDeadlockRetryService::class)
    );

    $result = $service->reconcileEventKey($eventKey);

    expect($result['authoritative'])->toBeTrue()
        ->and($notification->fresh()->resolved_at)->toBeNull()
        ->and($notification->fresh()->active_event_key)->toBe($eventKey);
});

it('revalidates a newer completed maintenance after vehicle discovery', function () {
    $vehicle = reconciliationVehicle();
    $older = reconciliationMaintenance($vehicle, [
        'status' => 'Completed',
        'completion_date' => now()->subMonth()->toDateString(),
    ]);
    $eventKey = reconciliationEventKey($older);
    $notification = reconciliationNotification($eventKey);
    $notification->active_event_key = $eventKey;
    $notification->save();
    $evaluator = new class(app(MaintenanceScheduleResolver::class), $vehicle) extends MaintenanceNotificationAuthorityEvaluator
    {
        private bool $inserted = false;

        public function __construct(
            MaintenanceScheduleResolver $resolver,
            private Vehicle $vehicle
        ) {
            parent::__construct($resolver);
        }

        public function discoverVehicleId(string $eventKey): ?int
        {
            $vehicleId = parent::discoverVehicleId($eventKey);

            if (! $this->inserted) {
                reconciliationMaintenance($this->vehicle, [
                    'status' => 'Completed',
                    'completion_date' => now()->toDateString(),
                ]);
                $this->inserted = true;
            }

            return $vehicleId;
        }
    };
    $service = new MaintenanceNotificationReconciliationService(
        $evaluator,
        app(MySqlDeadlockRetryService::class)
    );

    $result = $service->reconcileEventKey($eventKey);

    expect($result['resolution_reason'])->toBe('superseded_by_newer_completion')
        ->and($notification->fresh()->resolution_reason)->toBe('superseded_by_newer_completion')
        ->and($notification->fresh()->active_event_key)->toBeNull();
});
