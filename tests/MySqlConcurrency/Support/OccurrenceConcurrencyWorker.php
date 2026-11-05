<?php

namespace Tests\MySqlConcurrency\Support;

use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\MaintenanceNotificationOccurrenceGuard;
use App\Services\MaintenanceNotificationReconciliationService;
use App\Services\MaintenanceNotificationSourceNotAuthoritativeException;
use App\Services\MaintenanceScheduleResolver;
use App\Services\MySqlDeadlockRetryService;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OccurrenceConcurrencyWorker
{
    /** @var array<int, string> */
    public const ACTIONS = [
        'create',
        'create_with_collision_barrier',
        'mutate_collision_winner',
        'reconcile',
        'reconcile_then_signal',
        'create_after_reconciliation',
        'deadlock_update',
        'update_vehicle_presentation',
        'create_after_vehicle_presentation_update',
        'create_read_then_signal',
        'change_schedule_then_signal',
        'reconcile_after_schedule_change',
        'create_m1_with_post_authority_checkpoint',
        'complete_m2_then_signal',
        'reconcile_m1_create_m2',
        'reassign_maintenance_vehicle',
        'create_during_reassignment',
    ];

    /**
     * @param  array<string, int|string|null>  $payload
     * @return array<string, int|string|null>
     */
    public function run(string $action, array $payload): array
    {
        self::assertSafeActionPayload($action, $payload);
        $identity = DB::selectOne('SELECT CONNECTION_ID() AS connection_id');

        if (! is_object($identity) || ! property_exists($identity, 'connection_id')) {
            throw new RuntimeException('The occurrence worker connection identity could not be verified.');
        }

        $result = match ($action) {
            'create' => $this->create($payload),
            'create_with_collision_barrier' => $this->createWithCollisionBarrier($payload),
            'mutate_collision_winner' => $this->mutateCollisionWinner($payload),
            'reconcile' => $this->reconcile($payload),
            'reconcile_then_signal' => $this->reconcileThenSignal($payload),
            'create_after_reconciliation' => $this->createAfterReconciliation($payload),
            'deadlock_update' => $this->deadlockUpdate($payload),
            'update_vehicle_presentation' => $this->updateVehiclePresentation($payload),
            'create_after_vehicle_presentation_update' => $this->createAfterVehiclePresentationUpdate($payload),
            'create_read_then_signal' => $this->createReadThenSignal($payload),
            'change_schedule_then_signal' => $this->changeScheduleThenSignal($payload),
            'reconcile_after_schedule_change' => $this->reconcileAfterScheduleChange($payload),
            'create_m1_with_post_authority_checkpoint' => $this->createM1WithPostAuthorityCheckpoint($payload),
            'complete_m2_then_signal' => $this->completeM2ThenSignal($payload),
            'reconcile_m1_create_m2' => $this->reconcileM1CreateM2($payload),
            'reassign_maintenance_vehicle' => $this->reassignMaintenanceVehicle($payload),
            'create_during_reassignment' => $this->createDuringReassignment($payload),
            default => throw new \LogicException('Validated occurrence action was not dispatched.'),
        };

        return array_merge([
            'worker_action' => $action,
            'connection_id' => (int) $identity->connection_id,
        ], $result);
    }

    /** @param array<string, mixed> $payload */
    public static function assertSafeActionPayload(string $action, array $payload): void
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown occurrence worker action: {$action}");
        }

        if (array_key_exists('authority_attempt_history', $payload)) {
            throw new \InvalidArgumentException('Authority attempt history is worker-generated evidence.');
        }

        foreach (['barrier', 'participant'] as $field) {
            if (
                ! isset($payload[$field])
                || ! is_string($payload[$field])
                || $payload[$field] === ''
                || strlen($payload[$field]) > 80
                || preg_match('/\A[A-Za-z0-9:_-]+\z/', $payload[$field]) !== 1
            ) {
                throw new \InvalidArgumentException("A valid {$field} is required.");
            }
        }

        if (
            ! isset($payload['participants'])
            || ! is_int($payload['participants'])
            || $payload['participants'] < 1
            || $payload['participants'] > 4
        ) {
            throw new \InvalidArgumentException('A valid occurrence participant count is required.');
        }
    }

    /**
     * @param  array<string, array{exit_code: int, output: string, error_output: string}>  $results
     * @return array<string, array<string, mixed>>
     */
    public static function decodeWorkerResults(array $results, int $expectedWorkers): array
    {
        if ($expectedWorkers < 1 || count($results) !== $expectedWorkers) {
            throw new RuntimeException('Occurrence worker output count did not match the launched workers.');
        }

        $decoded = [];

        foreach ($results as $participant => $result) {
            $output = trim($result['output'] ?? '');

            if ($output === '') {
                throw new RuntimeException("Occurrence worker {$participant} returned no output.");
            }

            try {
                $workerResult = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException("Occurrence worker {$participant} returned invalid output.", 0, $exception);
            }

            if (
                ! is_array($workerResult)
                || ! isset($workerResult['worker_action'])
                || ! in_array($workerResult['worker_action'], self::ACTIONS, true)
                || ! isset($workerResult['connection_id'])
                || ! is_int($workerResult['connection_id'])
                || $workerResult['connection_id'] < 1
            ) {
                throw new RuntimeException("Occurrence worker {$participant} returned incomplete evidence.");
            }

            $decoded[$participant] = $workerResult;
        }

        if (collect($decoded)->pluck('connection_id')->unique()->count() !== $expectedWorkers) {
            throw new RuntimeException('Occurrence workers did not use distinct MySQL sessions.');
        }

        return $decoded;
    }

    /** @param array<string, int|string|null> $payload */
    private function create(array $payload): array
    {
        $this->wait($payload, 'start');
        $notification = $this->service()->createOrUpdateActive(
            User::query()->findOrFail((int) $payload['user_id']),
            (string) ($payload['title'] ?? 'Maintenance Due Soon'),
            (string) ($payload['message'] ?? 'Occurrence concurrency reminder.'),
            (string) $payload['event_key'],
            '/maintenance'
        );

        return $this->notificationResult('created_or_reused', $notification);
    }

    /** @param array<string, int|string|null> $payload */
    private function createWithCollisionBarrier(array $payload): array
    {
        $this->wait($payload, 'start');
        $successfulNotificationInsertObserved = false;
        DB::connection(IsolatedMySqlEnvironment::CONNECTION)->listen(
            function (QueryExecuted $query) use (&$successfulNotificationInsertObserved): void {
                if (self::isSuccessfulNotificationInsertQuery($query->connectionName, $query->sql)) {
                    $successfulNotificationInsertObserved = true;
                }
            }
        );
        $service = new BarrierFleetNotificationKeyedCreationService(
            app(FleetNotificationSchemaReadinessService::class),
            (string) $payload['barrier'],
            (string) $payload['participant'],
            (int) $payload['participants'],
            ($payload['mutate_winner'] ?? null) === '1'
        );

        $result = self::executeCollisionAttempt(
            fn (): FleetNotification => $service->createOrUpdateActive(
                User::query()->findOrFail((int) $payload['user_id']),
                'Maintenance Due Soon',
                'Collision recovery reminder.',
                (string) $payload['event_key'],
                '/maintenance'
            ),
            $service
        );

        if (is_array($result)) {
            return $result;
        }

        $activeCollisionRecovered = $service->activeCollisionRecoveryObserved();
        $outcome = self::classifySuccessfulCreationOutcome(
            $successfulNotificationInsertObserved,
            $activeCollisionRecovered
        );

        return array_merge(
            $this->notificationResult($outcome, $result),
            [
                'initial_insert_committed' => $successfulNotificationInsertObserved,
                'active_collision_recovered' => $activeCollisionRecovered,
            ]
        );
    }

    public static function classifySuccessfulCreationOutcome(
        bool $successfulNotificationInsertObserved,
        bool $activeCollisionRecovered
    ): string {
        if ($successfulNotificationInsertObserved === $activeCollisionRecovered) {
            throw new RuntimeException('Occurrence worker returned with missing or contradictory creation evidence.');
        }

        return $successfulNotificationInsertObserved ? 'created' : 'recovered';
    }

    public static function isSuccessfulNotificationInsertQuery(
        string $connectionName,
        string $sql
    ): bool {
        return $connectionName === IsolatedMySqlEnvironment::CONNECTION
            && str_starts_with(
                strtolower(ltrim($sql)),
                'insert into `fleet_notifications` '
            );
    }

    /**
     * @param  Closure(): FleetNotification  $attempt
     * @return FleetNotification|array{outcome: string, exception: class-string<UniqueConstraintViolationException>, driver_code: int, index_name: string}
     */
    public static function executeCollisionAttempt(
        Closure $attempt,
        FleetNotificationKeyedCreationService $service
    ): FleetNotification|array {
        try {
            return $attempt();
        } catch (UniqueConstraintViolationException $exception) {
            if (! $service->isExpectedActiveEventUniqueConflict($exception)) {
                throw $exception;
            }

            return [
                'outcome' => 'expected_stale_winner_conflict',
                'exception' => $exception::class,
                'driver_code' => (int) ($exception->errorInfo[1] ?? 0),
                'index_name' => FleetNotificationSchemaReadinessService::ACTIVE_EVENT_UNIQUE_INDEX,
            ];
        }
    }

    /** @param array<string, int|string|null> $payload */
    private function mutateCollisionWinner(array $payload): array
    {
        $this->wait($payload, 'start');
        DatabaseBarrier::arriveAndWait(
            (string) $payload['barrier'].'-collision',
            (string) $payload['participant'],
            2
        );

        DB::transaction(function () use ($payload): void {
            $notification = FleetNotification::query()
                ->where('user_id', (int) $payload['user_id'])
                ->where('active_event_key', (string) $payload['event_key'])
                ->lockForUpdate()
                ->firstOrFail();
            $updates = ['active_event_key' => null];

            if (($payload['mutation'] ?? null) === 'resolve') {
                $updates['resolved_at'] = now();
                $updates['resolution_reason'] = 'test_collision_resolution';
            }

            $notification->forceFill($updates)->save();
        });

        DatabaseBarrier::arriveAndWait(
            (string) $payload['barrier'].'-mutated',
            (string) $payload['participant'],
            2
        );

        return ['outcome' => 'mutated'];
    }

    /** @param array<string, int|string|null> $payload */
    private function reconcile(array $payload): array
    {
        $this->wait($payload, 'start');
        $result = app(MaintenanceNotificationReconciliationService::class)
            ->reconcileEventKey((string) $payload['event_key']);

        return ['outcome' => 'reconciled', 'resolved_count' => $result['resolved_count']];
    }

    /** @param array<string, int|string|null> $payload */
    private function reconcileThenSignal(array $payload): array
    {
        $result = $this->reconcile($payload);
        DatabaseBarrier::arriveAndWait(
            (string) $payload['barrier'].'-reconciled',
            (string) $payload['participant'],
            2
        );

        return $result;
    }

    /** @param array<string, int|string|null> $payload */
    private function createAfterReconciliation(array $payload): array
    {
        $this->wait($payload, 'start');
        DatabaseBarrier::arriveAndWait(
            (string) $payload['barrier'].'-reconciled',
            (string) $payload['participant'],
            2
        );

        return $this->createWithoutStartBarrier($payload);
    }

    /** @param array<string, int|string|null> $payload */
    private function deadlockUpdate(array $payload): array
    {
        $this->wait($payload, 'start');
        $attempts = 0;
        app(MySqlDeadlockRetryService::class)->run(function () use ($payload, &$attempts): void {
            $attempts++;
            FleetNotification::query()->whereKey((int) $payload['first_id'])->lockForUpdate()->firstOrFail();

            if ($attempts === 1) {
                DatabaseBarrier::arriveAndWait(
                    (string) $payload['barrier'].'-first-locked',
                    (string) $payload['participant'],
                    2
                );
            }

            FleetNotification::query()->whereKey((int) $payload['second_id'])->lockForUpdate()->firstOrFail();
            FleetNotification::query()->whereKey((int) $payload['first_id'])
                ->update(['message' => 'deadlock worker '.(string) $payload['participant']]);
        }, attempts: 3);

        return ['outcome' => 'committed', 'attempts' => $attempts];
    }

    /** @param array<string, int|string|null> $payload */
    private function updateVehiclePresentation(array $payload): array
    {
        $this->wait($payload, 'start');

        DB::transaction(function () use ($payload): void {
            $vehicle = Vehicle::query()
                ->whereKey((int) $payload['vehicle_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $vehicle->update(['brand' => (string) $payload['brand']]);

            $this->pairCheckpoint($payload, 'vehicle-locked');
            $this->pairCheckpoint($payload, 'pre-authority');
        });

        return [
            'outcome' => 'vehicle_update_committed',
            'vehicle_id' => (int) $payload['vehicle_id'],
            'brand' => (string) $payload['brand'],
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function createAfterVehiclePresentationUpdate(array $payload): array
    {
        $this->wait($payload, 'start');
        $this->pairCheckpoint($payload, 'vehicle-locked');
        $maintenance = Maintenance::query()
            ->with('vehicle')
            ->findOrFail((int) $payload['maintenance_id']);
        $message = $this->maintenancePresentationMessage($maintenance);
        [$service, $evaluator] = $this->instrumentedService(
            $payload,
            beforePhase: 'pre-authority',
        );
        $notification = $service->createOrUpdateActive(
            User::query()->findOrFail((int) $payload['user_id']),
            'Maintenance Due Soon',
            $message,
            (string) $payload['event_key'],
            '/maintenance',
        );
        $expectedBrand = (string) $payload['committed_brand'];

        return array_merge(
            $this->notificationResult('created_after_source_revalidation', $notification),
            [
                'authority_attempt_history' => $evaluator->expectedVehicleIds(),
                'presentation_classification' => str_contains($notification->message, $expectedBrand)
                    ? 'committed_source_presentation'
                    : 'stale_presentation_payload',
            ],
        );
    }

    /** @param array<string, int|string|null> $payload */
    private function createReadThenSignal(array $payload): array
    {
        $this->wait($payload, 'start');
        $notification = $this->service()->createOrUpdateActive(
            User::query()->findOrFail((int) $payload['user_id']),
            'Maintenance Due Soon',
            'Initially authoritative schedule reminder.',
            (string) $payload['event_key'],
            '/maintenance',
        );
        $notification->update(['status' => 'Read']);
        $this->pairCheckpoint($payload, 'creator-committed');

        return $this->notificationResult('valid_occurrence_committed', $notification->fresh());
    }

    /** @param array<string, int|string|null> $payload */
    private function changeScheduleThenSignal(array $payload): array
    {
        $this->wait($payload, 'start');
        $this->pairCheckpoint($payload, 'creator-committed');

        DB::transaction(function () use ($payload): void {
            Vehicle::query()
                ->whereKey((int) $payload['vehicle_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $maintenance = Maintenance::query()
                ->whereKey((int) $payload['maintenance_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $maintenance->vehicle_id !== (int) $payload['vehicle_id']) {
                throw new RuntimeException('The schedule-change source vehicle changed unexpectedly.');
            }

            $maintenance->update(['next_schedule' => (string) $payload['next_schedule']]);
        });

        $this->pairCheckpoint($payload, 'schedule-committed');

        return [
            'outcome' => 'schedule_change_committed',
            'maintenance_id' => (int) $payload['maintenance_id'],
            'next_schedule' => (string) $payload['next_schedule'],
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function reconcileAfterScheduleChange(array $payload): array
    {
        $this->wait($payload, 'start');
        $this->pairCheckpoint($payload, 'schedule-committed');
        $result = app(MaintenanceNotificationReconciliationService::class)
            ->reconcileEventKey((string) $payload['event_key']);

        return [
            'outcome' => 'temporarily_valid_then_resolved',
            'resolved_count' => $result['resolved_count'],
            'resolution_reason' => $result['resolution_reason'],
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function createM1WithPostAuthorityCheckpoint(array $payload): array
    {
        $this->wait($payload, 'start');
        $maintenance = Maintenance::query()
            ->with('vehicle')
            ->findOrFail((int) $payload['maintenance_id']);
        [$service, $evaluator] = $this->instrumentedService(
            $payload,
            afterPhase: 'post-authority',
        );
        $notification = $service->createOrUpdateActive(
            User::query()->findOrFail((int) $payload['user_id']),
            'Maintenance Due Soon',
            $this->maintenancePresentationMessage($maintenance),
            (string) $payload['event_key'],
            '/maintenance',
        );
        $notification->update(['status' => 'Read']);
        $this->pairCheckpoint($payload, 'm1-acknowledged');

        return array_merge(
            $this->notificationResult('m1_occurrence_committed', $notification->fresh()),
            ['authority_attempt_history' => $evaluator->expectedVehicleIds()],
        );
    }

    /** @param array<string, int|string|null> $payload */
    private function completeM2ThenSignal(array $payload): array
    {
        $this->wait($payload, 'start');
        $this->pairCheckpoint($payload, 'post-authority');

        DB::transaction(function () use ($payload): void {
            $vehicle = Vehicle::query()
                ->whereKey((int) $payload['vehicle_id'])
                ->lockForUpdate()
                ->firstOrFail();
            $maintenance = Maintenance::query()
                ->whereKey((int) $payload['maintenance_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $maintenance->vehicle_id !== (int) $vehicle->getKey()
                || $maintenance->status !== 'In Progress'
            ) {
                throw new RuntimeException('The completion source is not an active maintenance for the locked vehicle.');
            }

            $maintenance->update([
                'status' => 'Completed',
                'completion_date' => (string) $payload['completion_date'],
                'next_schedule' => (string) $payload['next_schedule'],
            ]);

            if ($vehicle->status === 'Maintenance') {
                $vehicle->update([
                    'status' => 'Available',
                    'readiness_status' => $vehicle->readiness_status === 'maintenance'
                        ? 'ready'
                        : $vehicle->readiness_status,
                ]);
            }
        });

        $this->pairCheckpoint($payload, 'completion-committed');

        return [
            'outcome' => 'supported_completion_committed',
            'maintenance_id' => (int) $payload['maintenance_id'],
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function reconcileM1CreateM2(array $payload): array
    {
        $this->wait($payload, 'start');
        $this->pairCheckpoint($payload, 'completion-committed');
        $this->pairCheckpoint($payload, 'm1-acknowledged');
        $reconciliation = app(MaintenanceNotificationReconciliationService::class)
            ->reconcileEventKey((string) $payload['obsolete_event_key']);
        $maintenance = Maintenance::query()
            ->with('vehicle')
            ->findOrFail((int) $payload['maintenance_id']);
        $notification = $this->service()->createOrUpdateActive(
            User::query()->findOrFail((int) $payload['user_id']),
            'Maintenance Due Soon',
            $this->maintenancePresentationMessage($maintenance),
            (string) $payload['event_key'],
            '/maintenance',
        );

        return array_merge(
            $this->notificationResult('superseded_completion_resolved', $notification),
            [
                'resolved_count' => $reconciliation['resolved_count'],
                'resolution_reason' => $reconciliation['resolution_reason'],
            ],
        );
    }

    /** @param array<string, int|string|null> $payload */
    private function reassignMaintenanceVehicle(array $payload): array
    {
        $this->wait($payload, 'start');

        DB::transaction(function () use ($payload): void {
            $vehicleIds = [(int) $payload['vehicle_a_id'], (int) $payload['vehicle_b_id']];
            sort($vehicleIds);
            $vehicles = Vehicle::query()
                ->whereKey($vehicleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($vehicles->count() !== 2) {
                throw new RuntimeException('Both reassignment vehicles must exist.');
            }

            $maintenance = Maintenance::query()
                ->whereKey((int) $payload['maintenance_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $maintenance->status !== 'Scheduled'
                || (int) $maintenance->vehicle_id !== (int) $payload['vehicle_a_id']
            ) {
                throw new RuntimeException('The reassignment source is not the expected Scheduled maintenance.');
            }

            $this->pairCheckpoint($payload, 'reassignment-locked');
            $this->pairCheckpoint($payload, 'pre-authority');
            $maintenance->update(['vehicle_id' => (int) $payload['vehicle_b_id']]);
        });

        return [
            'outcome' => 'maintenance_reassignment_committed',
            'maintenance_id' => (int) $payload['maintenance_id'],
            'vehicle_id' => (int) $payload['vehicle_b_id'],
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function createDuringReassignment(array $payload): array
    {
        $this->wait($payload, 'start');
        $this->pairCheckpoint($payload, 'reassignment-locked');
        $maintenance = Maintenance::query()
            ->with('vehicle')
            ->findOrFail((int) $payload['maintenance_id']);
        $message = $this->maintenancePresentationMessage($maintenance);
        [$service, $evaluator] = $this->instrumentedService(
            $payload,
            beforePhase: 'pre-authority',
        );
        $successfulNotificationInserts = 0;
        $notificationInsertAuthorityHistories = [];
        DB::connection(IsolatedMySqlEnvironment::CONNECTION)->listen(
            function (QueryExecuted $query) use (
                $evaluator,
                &$successfulNotificationInserts,
                &$notificationInsertAuthorityHistories,
            ): void {
                if (self::isSuccessfulNotificationInsertQuery($query->connectionName, $query->sql)) {
                    $successfulNotificationInserts++;
                    $notificationInsertAuthorityHistories[] = $evaluator->expectedVehicleIds();
                }
            },
        );

        try {
            $notification = $service->createOrUpdateActive(
                User::query()->findOrFail((int) $payload['user_id']),
                'Maintenance Due Soon',
                $message,
                (string) $payload['event_key'],
                '/maintenance',
            );
        } catch (MaintenanceNotificationSourceNotAuthoritativeException $exception) {
            return [
                'outcome' => 'source_vehicle_changed_fail_closed',
                'reason' => $exception->reason,
                'notification_id' => null,
                'authority_attempt_history' => $evaluator->expectedVehicleIds(),
                'successful_notification_inserts' => $successfulNotificationInserts,
                'notification_insert_authority_histories' => $notificationInsertAuthorityHistories,
            ];
        }

        $expectedBrand = (string) $payload['committed_brand'];

        return array_merge(
            $this->notificationResult('source_vehicle_retry_succeeded', $notification),
            [
                'authority_attempt_history' => $evaluator->expectedVehicleIds(),
                'successful_notification_inserts' => $successfulNotificationInserts,
                'notification_insert_authority_histories' => $notificationInsertAuthorityHistories,
                'presentation_classification' => str_contains($notification->message, $expectedBrand)
                    ? 'committed_source_presentation'
                    : 'stale_presentation_payload',
            ],
        );
    }

    /** @param array<string, int|string|null> $payload */
    private function createWithoutStartBarrier(array $payload): array
    {
        try {
            $notification = $this->service()->createOrUpdateActive(
                User::query()->findOrFail((int) $payload['user_id']),
                'Maintenance Due Soon',
                'Post-reconciliation reminder.',
                (string) $payload['event_key'],
                '/maintenance'
            );
        } catch (MaintenanceNotificationSourceNotAuthoritativeException $exception) {
            return [
                'outcome' => 'source_rejected',
                'reason' => $exception->reason,
                'notification_id' => null,
            ];
        }

        return $this->notificationResult('created_after_reconciliation', $notification);
    }

    /**
     * @param  array<string, int|string|null>  $payload
     * @return array{FleetNotificationKeyedCreationService, BarrierMaintenanceNotificationAuthorityEvaluator}
     */
    private function instrumentedService(
        array $payload,
        ?string $beforePhase = null,
        ?string $afterPhase = null,
    ): array {
        $evaluator = new BarrierMaintenanceNotificationAuthorityEvaluator(
            app(MaintenanceScheduleResolver::class),
            (string) $payload['barrier'],
            (string) $payload['participant'],
            $beforePhase,
            $afterPhase,
        );
        $guard = new MaintenanceNotificationOccurrenceGuard(
            $evaluator,
            app(MySqlDeadlockRetryService::class),
        );

        return [
            new FleetNotificationKeyedCreationService(
                app(FleetNotificationSchemaReadinessService::class),
                $guard,
            ),
            $evaluator,
        ];
    }

    private function maintenancePresentationMessage(Maintenance $maintenance): string
    {
        $maintenance->loadMissing('vehicle');
        $vehicleName = trim(
            ($maintenance->vehicle?->brand ?? '').' '.($maintenance->vehicle?->model ?? ''),
        );

        return ($vehicleName !== '' ? $vehicleName : 'Vehicle')
            ." maintenance {$maintenance->maintenance_number} is due soon.";
    }

    /** @param array<string, int|string|null> $payload */
    private function pairCheckpoint(array $payload, string $phase): void
    {
        DatabaseBarrier::arriveAndWait(
            (string) $payload['barrier'].'-'.$phase,
            (string) $payload['participant'],
            2,
        );
    }

    private function service(): FleetNotificationKeyedCreationService
    {
        return app(FleetNotificationKeyedCreationService::class);
    }

    /** @return array<string, int|string|null> */
    private function notificationResult(string $outcome, FleetNotification $notification): array
    {
        return [
            'outcome' => $outcome,
            'notification_id' => $notification->id,
            'status' => $notification->status,
            'event_key' => $notification->event_key,
            'active_event_key' => $notification->active_event_key,
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function wait(array $payload, string $phase): void
    {
        DatabaseBarrier::arriveAndWait(
            (string) $payload['barrier'].'-'.$phase,
            (string) $payload['participant'],
            (int) $payload['participants']
        );
    }
}
