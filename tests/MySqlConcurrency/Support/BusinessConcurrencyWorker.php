<?php

namespace Tests\MySqlConcurrency\Support;

use App\Http\Controllers\MaintenanceController;
use App\Models\FleetNotification;
use App\Models\Maintenance;
use App\Services\FleetNotificationActiveEventService;
use App\Services\FleetNotificationService;
use App\Services\MaintenanceNotificationAuthorityEvaluator;
use App\Services\MaintenanceNotificationReconciliationService;
use App\Services\MaintenanceScheduleResolver;
use App\Services\MySqlDeadlockRetryService;
use Carbon\Carbon;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class BusinessConcurrencyWorker
{
    /** @var list<string> */
    public const ACTIONS = [
        'reconcile',
        'reconcile_after_authority_barrier',
        'mutate_after_authority_barrier',
        'create_unique_notification',
        'assign_active_event',
        'assign_active_identity_directly',
        'opposite_vehicle_reassignment',
        'complete_maintenance',
        'update_maintenance',
    ];

    /**
     * @param  array<string, int|string|null>  $payload
     * @return array<string, int|string|null>
     */
    public function run(string $action, array $payload): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown concurrency worker action: {$action}");
        }

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
            'complete_maintenance' => $this->completeMaintenance($payload),
            'update_maintenance' => $this->updateMaintenance($payload),
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
    private function completeMaintenance(array $payload): array
    {
        $maintenanceId = $this->positiveInteger($payload, 'maintenance_id');

        return $this->dispatchMaintenanceRequest(
            $payload,
            'POST',
            "/maintenance/{$maintenanceId}/transition",
            ['status' => 'Completed'],
            MaintenanceController::class.'@transition',
            'completion'
        );
    }

    /** @param array<string, int|string|null> $payload */
    private function updateMaintenance(array $payload): array
    {
        $maintenanceId = $this->positiveInteger($payload, 'maintenance_id');
        $maintenance = Maintenance::query()->findOrFail($maintenanceId);
        $description = $payload['description'] ?? null;

        if (! is_string($description) || trim($description) === '') {
            throw new \InvalidArgumentException('A non-empty maintenance description is required.');
        }

        return $this->dispatchMaintenanceRequest(
            $payload,
            'PUT',
            "/maintenance/{$maintenanceId}",
            [
                'vehicle_id' => $maintenance->vehicle_id,
                'maintenance_type' => $maintenance->maintenance_type,
                'description' => $description,
                'maintenance_date' => $maintenance->maintenance_date?->toDateString(),
                'completion_date' => $maintenance->completion_date?->toDateString(),
                'next_schedule' => $maintenance->next_schedule?->toDateString(),
                'recurrence_enabled' => $maintenance->recurrence_frequency !== null,
                'recurrence_frequency' => $maintenance->recurrence_frequency,
                'recurrence_interval' => $maintenance->recurrence_interval,
                'recurrence_preserve_month_end' => $maintenance->recurrence_preserve_month_end,
                'technician' => $maintenance->technician,
                'priority' => $maintenance->priority,
                'odometer' => $maintenance->odometer,
                'parts_used' => $maintenance->parts_used,
                'cost' => $maintenance->cost,
                'notes' => $maintenance->notes,
            ],
            MaintenanceController::class.'@update',
            'generic_update'
        );
    }

    /**
     * @param  array<string, int|string|null>  $payload
     * @param  array<string, bool|float|int|string|null>  $requestData
     * @return array<string, int|string|null>
     */
    private function dispatchMaintenanceRequest(
        array $payload,
        string $method,
        string $path,
        array $requestData,
        string $expectedRouteAction,
        string $operation
    ): array {
        $userId = $this->positiveInteger($payload, 'user_id');
        $lockOrder = [];
        $vehicleBarrierReached = false;
        $connection = DB::connection();
        $testNow = $this->testNow($payload);

        Auth::loginUsingId($userId);

        if (! Auth::check()) {
            throw new \RuntimeException('The synthetic maintenance worker could not authenticate.');
        }

        $connection->beforeExecuting(function (string $query) use (
            &$lockOrder,
            &$vehicleBarrierReached,
            $payload
        ): void {
            $normalizedQuery = strtolower($query);

            if (
                str_contains($normalizedQuery, 'from `vehicles`')
                && str_contains($normalizedQuery, 'for update')
            ) {
                $lockOrder[] = 'vehicles';

                if (! $vehicleBarrierReached) {
                    $vehicleBarrierReached = true;
                    $this->wait($payload, 'vehicle-lock-ready');
                }

                return;
            }

            if (
                str_contains($normalizedQuery, 'from `maintenances`')
                && str_contains($normalizedQuery, 'for update')
            ) {
                $lockOrder[] = 'maintenances';
            }
        });

        $request = Request::create(
            $path,
            $method,
            $requestData,
            server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]
        );
        $routeAction = app('router')->getRoutes()->match($request)->getActionName();

        if ($routeAction !== $expectedRouteAction) {
            throw new \RuntimeException('The maintenance worker resolved an unexpected production route action.');
        }

        $kernel = app(HttpKernel::class);

        if ($testNow !== null) {
            Carbon::setTestNow($testNow);
        }

        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } finally {
            Carbon::setTestNow();
        }

        $decoded = json_decode($response->getContent(), true);
        $responseData = is_array($decoded) ? $decoded : [];

        return [
            'outcome' => self::classifyMaintenanceResponse(
                $operation,
                $response->getStatusCode(),
                $responseData
            ),
            'http_status' => $response->getStatusCode(),
            'route_action' => $routeAction,
            'lock_order' => implode('>', $lockOrder),
            'response_success' => ($responseData['success'] ?? false) === true ? 1 : 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    public static function classifyMaintenanceResponse(
        string $operation,
        int $statusCode,
        array $responseData
    ): string {
        if ($statusCode === 200 && ($responseData['success'] ?? false) === true) {
            return $operation === 'completion' ? 'completed' : 'updated';
        }

        $message = $responseData['message'] ?? null;
        $nextScheduleErrors = $responseData['errors']['next_schedule'] ?? null;

        if (
            $operation === 'completion'
            && $statusCode === 422
            && is_string($message)
            && $message === 'Cannot change maintenance status from Completed to Completed.'
        ) {
            return 'lifecycle_rejected';
        }

        if (
            $operation === 'completion'
            && $statusCode === 422
            && $message === 'Please check the maintenance information.'
            && $nextScheduleErrors === [
                'The saved Next Service Due date conflicts with the date calculated from the completion date.',
            ]
        ) {
            return 'recurrence_conflict_rejected';
        }

        if (
            $operation === 'generic_update'
            && $statusCode === 422
            && $message === 'Please check the maintenance information.'
            && $nextScheduleErrors === [
                'The saved Next Service Due date cannot be cleared while recurring maintenance remains enabled.',
            ]
        ) {
            return 'stale_edit_rejected';
        }

        return 'unexpected_response';
    }

    /** @param array<string, int|string|null> $payload */
    private function testNow(array $payload): ?Carbon
    {
        $value = $payload['test_now'] ?? null;

        if ($value === null) {
            return null;
        }

        if (
            ! is_string($value) ||
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) !== 1 ||
            ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
        ) {
            throw new \InvalidArgumentException('The synthetic maintenance test clock must be a valid Y-m-d date.');
        }

        return Carbon::create(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            10,
            0,
            0,
            (string) config('app.timezone')
        );
    }

    /** @param array<string, int|string|null> $payload */
    private function positiveInteger(array $payload, string $key): int
    {
        $value = filter_var(
            $payload[$key] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (! is_int($value)) {
            throw new \InvalidArgumentException("A positive {$key} is required.");
        }

        return $value;
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
