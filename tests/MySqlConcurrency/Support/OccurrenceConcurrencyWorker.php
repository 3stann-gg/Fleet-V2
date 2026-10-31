<?php

namespace Tests\MySqlConcurrency\Support;

use App\Models\FleetNotification;
use App\Models\User;
use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\MaintenanceNotificationReconciliationService;
use App\Services\MaintenanceNotificationSourceNotAuthoritativeException;
use App\Services\MySqlDeadlockRetryService;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OccurrenceConcurrencyWorker
{
    /**
     * @param  array<string, int|string|null>  $payload
     * @return array<string, int|string|null>
     */
    public function run(string $action, array $payload): array
    {
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
            default => throw new \InvalidArgumentException("Unknown occurrence worker action: {$action}"),
        };

        return array_merge(['connection_id' => (int) $identity->connection_id], $result);
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
