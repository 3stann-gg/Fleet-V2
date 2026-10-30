<?php

namespace Tests\MySqlConcurrency\Support;

use App\Models\FleetNotification;
use App\Services\FleetNotificationActiveEventService;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceNotificationAuthorityEvaluator;
use App\Services\MaintenanceNotificationReconciliationService;
use App\Services\MaintenanceScheduleResolver;
use App\Services\MySqlDeadlockRetryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class BusinessConcurrencyWorker
{
    /**
     * @param  array<string, int|string|null>  $payload
     * @return array<string, int|string|null>
     */
    public function run(string $action, array $payload): array
    {
        $identity = DB::selectOne('SELECT CONNECTION_ID() AS connection_id');

        if (! is_object($identity) || ! property_exists($identity, 'connection_id')) {
            throw new \RuntimeException('The worker database connection identity could not be verified.');
        }

        $result = match ($action) {
            'reconcile' => $this->reconcile($payload),
            'reconcile_after_authority_barrier' => $this->reconcileAfterAuthorityBarrier($payload),
            'mutate_after_authority_barrier' => $this->mutateAfterAuthorityBarrier($payload),
            'create_unique_notification' => $this->createUniqueNotification($payload),
            'assign_active_event' => $this->assignActiveEvent($payload),
            'assign_active_identity_directly' => $this->assignActiveIdentityDirectly($payload),
            'opposite_vehicle_reassignment' => $this->oppositeVehicleReassignment($payload),
            default => throw new \InvalidArgumentException("Unknown concurrency worker action: {$action}"),
        };

        return array_merge([
            'connection_id' => (int) $identity->connection_id,
        ], $result);
    }

    /** @param array<string, int|string|null> $payload */
    private function reconcile(array $payload): array
    {
        $this->wait($payload, 'start');

        try {
            $result = app(MaintenanceNotificationReconciliationService::class)
                ->reconcileEventKey((string) $payload['event_key']);

            return [
                'outcome' => 'reconciled',
                'resolved_count' => $result['resolved_count'],
            ];
        } catch (QueryException $exception) {
            if (($payload['expect_query_failure'] ?? null) !== '1') {
                throw $exception;
            }

            return [
                'outcome' => 'query_failure',
                'resolved_count' => 0,
            ];
        }
    }

    /** @param array<string, int|string|null> $payload */
    private function reconcileAfterAuthorityBarrier(array $payload): array
    {
        $barrierName = (string) $payload['barrier'];
        $participant = (string) $payload['participant'];
        $expectedParticipants = (int) $payload['participants'];
        $resolver = app(MaintenanceScheduleResolver::class);
        $evaluator = new class($resolver, $barrierName, $participant, $expectedParticipants) extends MaintenanceNotificationAuthorityEvaluator
        {
            public function __construct(
                MaintenanceScheduleResolver $resolver,
                private string $barrierName,
                private string $participant,
                private int $expectedParticipants
            ) {
                parent::__construct($resolver);
            }

            public function discoverVehicleId(string $eventKey): ?int
            {
                $vehicleId = parent::discoverVehicleId($eventKey);

                DatabaseBarrier::arriveAndWait(
                    $this->barrierName.'-evaluated',
                    $this->participant,
                    $this->expectedParticipants
                );
                DatabaseBarrier::arriveAndWait(
                    $this->barrierName.'-mutated',
                    $this->participant,
                    $this->expectedParticipants
                );

                return $vehicleId;
            }
        };

        $result = (new MaintenanceNotificationReconciliationService(
            $evaluator,
            app(MySqlDeadlockRetryService::class)
        ))
            ->reconcileEventKey((string) $payload['event_key']);

        return [
            'outcome' => 'reconciled',
            'authoritative' => $result['authoritative'] === true ? 1 : 0,
            'resolved_count' => $result['resolved_count'],
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function mutateAfterAuthorityBarrier(array $payload): array
    {
        $barrierName = (string) $payload['barrier'];
        $participant = (string) $payload['participant'];
        $expectedParticipants = (int) $payload['participants'];

        DatabaseBarrier::arriveAndWait(
            $barrierName.'-evaluated',
            $participant,
            $expectedParticipants
        );

        match ((string) $payload['mutation']) {
            'update_schedule' => DB::table('maintenances')
                ->where('id', (int) $payload['maintenance_id'])
                ->update(['next_schedule' => (string) $payload['next_schedule']]),
            'insert_completed' => DB::table('maintenances')->insert([
                'maintenance_number' => (string) $payload['maintenance_number'],
                'vehicle_id' => (int) $payload['vehicle_id'],
                'request_type' => 'Normal',
                'maintenance_type' => 'Preventive Maintenance',
                'description' => 'Concurrent authoritative completion',
                'maintenance_date' => (string) $payload['maintenance_date'],
                'completion_date' => (string) $payload['completion_date'],
                'next_schedule' => (string) $payload['next_schedule'],
                'cost' => 0,
                'status' => 'Completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'mark_read' => DB::table('fleet_notifications')
                ->where('id', (int) $payload['notification_id'])
                ->update(['status' => 'Read']),
            default => throw new \InvalidArgumentException('Unknown authority-barrier mutation.'),
        };

        DatabaseBarrier::arriveAndWait(
            $barrierName.'-mutated',
            $participant,
            $expectedParticipants
        );

        return ['outcome' => 'mutated'];
    }

    /** @param array<string, int|string|null> $payload */
    private function createUniqueNotification(array $payload): array
    {
        Auth::loginUsingId((int) $payload['user_id']);
        $this->wait($payload, 'start');

        $notification = FleetNotificationService::createUniqueWhenEnabled(
            'maintenanceDue',
            'Maintenance Due Soon',
            'Concurrent exact-key reminder.',
            (string) $payload['event_key'],
            true,
            route('maintenance')
        );

        return [
            'outcome' => 'created_or_found',
            'notification_id' => $notification?->id,
        ];
    }

    /** @param array<string, int|string|null> $payload */
    private function assignActiveEvent(array $payload): array
    {
        $this->wait($payload, 'start');
        $notification = FleetNotification::query()->findOrFail((int) $payload['notification_id']);

        try {
            $assigned = app(FleetNotificationActiveEventService::class)
                ->assignMaintenanceEventKey($notification, (string) $payload['event_key']);

            return [
                'outcome' => 'assigned',
                'notification_id' => $assigned->id,
            ];
        } catch (\LogicException) {
            return [
                'outcome' => 'collision',
                'notification_id' => $notification->id,
            ];
        }
    }

    /** @param array<string, int|string|null> $payload */
    private function assignActiveIdentityDirectly(array $payload): array
    {
        $this->wait($payload, 'start');

        try {
            DB::transaction(function () use ($payload): void {
                FleetNotification::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $payload['notification_id'])
                    ->forceFill(['active_event_key' => (string) $payload['event_key']])
                    ->save();
            });

            return ['outcome' => 'assigned'];
        } catch (QueryException) {
            return ['outcome' => 'collision'];
        }
    }

    /** @param array<string, int|string|null> $payload */
    private function oppositeVehicleReassignment(array $payload): array
    {
        $this->wait($payload, 'start');

        try {
            app(MySqlDeadlockRetryService::class)->run(function () use ($payload): void {
                DB::table('vehicles')
                    ->whereIn('id', [
                        (int) $payload['current_vehicle_id'],
                        (int) $payload['new_vehicle_id'],
                    ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                DB::table('maintenances')
                    ->where('id', (int) $payload['maintenance_id'])
                    ->lockForUpdate()
                    ->first();
                DB::table('maintenances')
                    ->where('id', (int) $payload['maintenance_id'])
                    ->update(['vehicle_id' => (int) $payload['new_vehicle_id']]);
            }, attempts: 1);

            return ['outcome' => 'committed'];
        } catch (QueryException $exception) {
            $errorInfo = $exception->errorInfo;

            return [
                'outcome' => 'database_error',
                'sqlstate' => is_string($errorInfo[0] ?? null)
                    ? $errorInfo[0]
                    : null,
                'driver_code' => is_int($errorInfo[1] ?? null)
                    ? $errorInfo[1]
                    : null,
                'exception_code' => is_int($exception->getCode())
                    ? $exception->getCode()
                    : null,
            ];
        }
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
