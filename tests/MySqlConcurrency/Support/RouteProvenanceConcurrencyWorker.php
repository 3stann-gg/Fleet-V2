<?php

namespace Tests\MySqlConcurrency\Support;

use App\Http\Controllers\RoutePlanController;
use App\Models\AuditLog;
use App\Models\RouteEstimateProvenance;
use App\Models\RoutePlan;
use App\Services\RouteEstimateProvenanceReadinessService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use PDOException;
use RuntimeException;

final class RouteProvenanceConcurrencyWorker
{
    public const ACTIONS = ['planned', 'manual', 'archive', 'restore', 'probe'];

    public const AUDIT_FAILURE = 'Synthetic route provenance audit failure.';

    /** Validate before any application boot or database access. */
    public static function assertPayload(string $action, mixed $payload): void
    {
        if (! in_array($action, self::ACTIONS, true) || ! is_array($payload)) {
            throw new RuntimeException('Unknown route provenance action or payload.');
        }
        foreach (['route_id', 'user_id'] as $key) {
            if (! is_int($payload[$key] ?? null) || $payload[$key] < 1) {
                throw new RuntimeException('Positive route provenance identities are required.');
            }
        }
        if (! is_string($payload['barrier'] ?? null) || preg_match('/\Arp-[a-z0-9-]{1,65}\z/', $payload['barrier']) !== 1
            || ! in_array($payload['participant'] ?? null, ['a', 'b', 'holder', 'contender', 'probe'], true)
            || ! in_array($payload['coordination'] ?? null, ['simultaneous', 'holder', 'contender', 'probe'], true)
            || (($action === 'probe') !== ($payload['coordination'] === 'probe'))
            || ($action !== 'probe' && ! is_array($payload['request'] ?? null))
            || ! is_bool($payload['audit_failure'] ?? false)) {
            throw new RuntimeException('Invalid route provenance synchronization contract.');
        }
        $expectedParticipant = match ($payload['coordination']) {
            'holder' => 'holder', 'contender' => 'contender', 'probe' => 'probe', default => null,
        };
        if (($expectedParticipant !== null && $payload['participant'] !== $expectedParticipant)
            || ($payload['coordination'] === 'simultaneous' && ! in_array($payload['participant'], ['a', 'b'], true))
            || (($payload['audit_failure'] ?? false) && $action !== 'planned')) {
            throw new RuntimeException('Mismatched route provenance worker role.');
        }
    }

    /** Enable only after all original dedicated-schema and parent-lock guards pass. */
    public static function enableTestCapabilities(): void
    {
        if (! HarnessEnvironment::routeProvenanceModeRequested() || HarnessEnvironment::occurrenceModeRequested()
            || ! app()->environment('testing') || DB::getDefaultConnection() !== IsolatedMySqlEnvironment::CONNECTION) {
            throw new RuntimeException('Route provenance tests require their guarded disposable MySQL mode.');
        }
        IsolatedMySqlEnvironment::assertSafe();
        IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
        IsolatedMySqlEnvironment::assertExternalLockOwner();
        (new NotificationOccurrenceSchemaTransition)->assertBaseline();
        HarnessSafetyPolicy::assertLifecycleFlags([
            'occurrences' => config('fleet-notifications.occurrences.enabled') === true,
            'after_commit' => config('fleet-notifications.reconciliation.after_commit_enabled') === true,
            'scheduled_recovery' => config('fleet-notifications.reconciliation.scheduled_recovery_enabled') === true,
            'historical' => config('fleet-notifications.reconciliation.historical_enabled') === true,
        ], false);
        if (! (new RouteEstimateProvenanceReadinessService)->installed(DB::connection())) {
            throw new RuntimeException('The disposable provenance schema is not ready.');
        }
        foreach (['planned_departure_enabled', 'planned_proof_issuance_enabled', 'planned_apply_enabled', 'planned_save_enabled'] as $flag) {
            config(['services.tomtom.'.$flag => true]);
        }
        config(['services.route_estimates.provenance_recording_enabled' => true]);
        Http::preventStrayRequests();
    }

    public static function matchesRouteLock(string $query, array $bindings, int $routeId): bool
    {
        return preg_match('/\Aselect\s+.+\s+from\s+`route_plans`\s+where\s+`(?:route_plans`\.`)?id`\s*=\s*\?\s+limit\s+1\s+for\s+update\z/i', trim($query)) === 1
            && count($bindings) === 1 && (string) $bindings[0] === (string) $routeId;
    }

    /** SQL failures cannot be mistaken for ordinary stale-revision responses. */
    public static function classify(int $status, array $data, array $sqlFailures, bool $auditFailure): string
    {
        if ($sqlFailures !== []) {
            return $status === 422 && ($data['message'] ?? null) === 'Route changes could not be saved.'
                && $sqlFailures === [['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']]
                    ? 'uuid_conflict' : 'unexpected_response';
        }
        if ($status === 200 && ($data['success'] ?? null) === true && is_array($data['routePlan'] ?? null)) {
            return 'accepted';
        }
        if ($status === 409 && ($data['code'] ?? null) === 'ROUTE_ESTIMATE_REVISION_STALE') {
            return 'revision_conflict';
        }
        if ($status === 422 && $auditFailure && ($data['message'] ?? null) === self::AUDIT_FAILURE) {
            return 'audit_rolled_back';
        }

        return 'unexpected_response';
    }

    /**
     * Pure result guard shared by real workers and connection-free safety tests.
     * Only allowlisted, redacted diagnostics enter the existing stderr failure channel.
     *
     * @return array{data: array, outcome: string, diagnostic: array}
     */
    public static function assertResult(int $status, string $content, array $payload, array $sqlFailures, array $sqlExceptionClasses, bool $auditFailure, bool $attempted, bool $locked, int $transactionLevel, bool $pdoInTransaction, array $recoveredDeadlocks = []): array
    {
        self::assertDeadlockRecoveries($recoveredDeadlocks);
        $malformed = false;
        try {
            $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                $data = [];
                $malformed = true;
            }
        } catch (JsonException) {
            $data = [];
            $malformed = true;
        }
        $outcome = self::classify($status, $data, $sqlFailures, $auditFailure);
        $failures = [
            'malformed_response' => $malformed,
            'unrecognized_response_or_sql_failure' => $outcome === 'unexpected_response',
            'missing_route_lock_attempt' => ! $attempted,
            'open_laravel_transaction' => $transactionLevel !== 0,
            'open_pdo_transaction' => $pdoInTransaction,
        ];
        $revision = $payload['request']['expected_estimate_revision'] ?? null;
        $diagnostic = [
            'recovered_deadlocks' => $recoveredDeadlocks,
            'worker_pid' => getmypid(),
            'worker' => in_array($payload['participant'] ?? null, ['a', 'b', 'holder', 'contender', 'probe'], true) ? $payload['participant'] : null,
            'barrier' => is_string($payload['barrier'] ?? null) && preg_match('/\Arp-[a-z0-9-]{1,65}\z/', $payload['barrier']) === 1 ? $payload['barrier'] : null,
            'http_status' => $status,
            'response' => self::responseDiagnostic($data),
            'expected_estimate_revision' => is_int($revision) && $revision >= 0 ? $revision : null,
            'route_lock_attempted' => $attempted,
            'route_lock_acquired' => $locked,
            'laravel_transaction_level' => $transactionLevel,
            'pdo_in_transaction' => $pdoInTransaction,
            'failure_conditions' => $failures,
            'worker_exception_class' => in_array(true, $failures, true) ? RuntimeException::class : null,
            'sql_failure_count' => count($sqlFailures),
            'sql_failures' => array_map(fn (mixed $failure): array => [
                'state' => is_string($failure['state'] ?? null) && preg_match('/\A[A-Z0-9]{5}\z/', $failure['state']) === 1 ? $failure['state'] : null,
                'code' => is_int($failure['code'] ?? null) && $failure['code'] >= 0 && $failure['code'] <= 65535 ? $failure['code'] : null,
                'constraint' => ($failure['constraint'] ?? null) === 'rep_calculation_uq' ? 'rep_calculation_uq' : 'other',
            ], array_slice($sqlFailures, 0, 16)),
            'sql_exception_count' => count($sqlExceptionClasses),
            'sql_exceptions' => array_map(fn (mixed $exception): array => [
                'exception_class' => in_array($exception['exception_class'] ?? null, [QueryException::class, UniqueConstraintViolationException::class], true) ? $exception['exception_class'] : null,
                'previous_exception_class' => ($exception['previous_exception_class'] ?? null) === PDOException::class ? PDOException::class : null,
            ], array_slice($sqlExceptionClasses, 0, 16)),
        ];
        array_walk_recursive($diagnostic, function (mixed &$value): void {
            if (is_string($value)) {
                $value = HarnessEnvironment::sanitizeDiagnostic($value);
            }
        });
        if (in_array(true, $failures, true)) {
            throw new RuntimeException('Unexpected route worker result, SQL failure, missing lock attempt or open transaction (HTTP '.$status.'). Diagnostics: '.json_encode($diagnostic, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return ['data' => $data, 'outcome' => $outcome, 'diagnostic' => $diagnostic];
    }

    /** A recovered deadlock is never the terminal SQL error and never a UUID conflict itself. */
    public static function assertDeadlockRecoveries(array $recoveries): void
    {
        if (! array_is_list($recoveries) || count($recoveries) > 2) {
            throw new RuntimeException('Invalid bounded deadlock recovery evidence.');
        }
        foreach ($recoveries as $index => $recovery) {
            if ($recovery !== ['attempt' => $index + 1, 'state' => '40001', 'code' => 1213,
                'rolled_back' => true, 'pdo_closed_before_restart' => true]) {
                throw new RuntimeException('Unverified deadlock recovery evidence.');
            }
        }
    }

    /** Never retain response messages, validation values, proofs or arbitrary response keys. */
    private static function responseDiagnostic(array $data): array
    {
        $codes = ['ROUTE_ESTIMATE_REVISION_REQUIRED', 'ROUTE_ESTIMATE_REVISION_STALE', 'ROUTE_PROVENANCE_SCHEMA_NOT_READY',
            'ROUTE_PLANNED_SAVE_DISABLED', 'ROUTE_PLANNED_PROOF_INVALID', 'ROUTE_PLANNED_MODE_DISABLED',
            'ROUTE_DEPARTURE_MODE_UNSUPPORTED', 'ROUTE_PLANNED_PROOF_REQUIRED', 'ROUTE_PLANNED_SAVE_UNSUPPORTED',
            'ROUTE_PLANNED_PROOF_EXPIRED', 'ROUTE_DEPARTURE_CONTEXT_INVALID', 'ROUTE_PLANNED_SCHEDULE_CHANGED',
            'ROUTE_PLANNED_DEPARTURE_MISMATCH', 'ROUTE_PLANNED_VALUES_MISMATCH'];
        $fields = ['origin', 'destination', 'department', 'origin_latitude', 'origin_longitude', 'destination_latitude',
            'destination_longitude', 'status', 'estimated_distance', 'estimated_time', 'optimization_strategy',
            'optimization_score', 'optimization_applied', 'optimization_proof', 'purpose', 'notes', 'stops',
            'expected_estimate_revision', 'departure_mode', 'planned_stop_order'];
        $validationFields = [];
        $omitted = 0;
        foreach (is_array($data['errors'] ?? null) ? array_keys($data['errors']) : [] as $field) {
            if (is_string($field) && (in_array($field, $fields, true) || preg_match('/\Astops\.(?:0|[1-9][0-9]{0,3})\.(?:location|latitude|longitude)\z/', $field) === 1) && count($validationFields) < 64) {
                $validationFields[] = $field;
            } else {
                $omitted++;
            }
        }

        return [
            'application_code' => in_array($data['code'] ?? null, $codes, true) ? $data['code'] : null,
            'message_category' => match ($data['message'] ?? null) {
                'Route changes could not be saved.' => 'persistence_error',
                'Please check the route information.' => 'validation_error',
                self::AUDIT_FAILURE => 'synthetic_audit_failure',
                default => 'unrecognized',
            },
            'validation_fields' => $validationFields,
            'omitted_validation_fields' => $omitted,
        ];
    }

    public function run(string $action, array $payload): array
    {
        self::assertPayload($action, $payload);
        self::enableTestCapabilities();
        $connection = DB::connection();
        if (! $connection instanceof RouteProvenanceConnection) {
            throw new RuntimeException('The dedicated error telemetry connection is required.');
        }
        $identity = IsolatedMySqlEnvironment::assertSafe();
        $controlId = IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION)['connection_id'];
        if ($identity['connection_id'] === $controlId) {
            throw new RuntimeException('Barrier connection is not independent.');
        }
        if ($action === 'probe') {
            return $this->probe($payload) + ['connection_id' => $identity['connection_id'], 'control_connection_id' => $controlId, 'pid' => getmypid()];
        }
        Auth::loginUsingId($payload['user_id']);
        if (! Auth::check()) {
            throw new RuntimeException('Synthetic route worker authentication failed.');
        }
        $attempted = false;
        $locked = false;
        $connection->beforeExecuting(function (string $query, array $bindings) use ($payload, &$attempted): void {
            if ($attempted || ! self::matchesRouteLock($query, $bindings, $payload['route_id'])) {
                return;
            }
            $attempted = true;
            if ($payload['coordination'] === 'simultaneous') {
                DatabaseBarrier::arriveAndWait($payload['barrier'].'-start', $payload['participant'], 2, 8000);
            } elseif ($payload['coordination'] === 'contender') {
                $this->wait($payload, 'locked', 'holder');
                $this->signal($payload, 'attempt', 'contender');
            }
        });
        $connection->listen(function (QueryExecuted $query) use ($payload, $connection, &$locked): void {
            if ($locked || $query->connectionName !== $connection->getName()
                || ! self::matchesRouteLock($query->sql, $query->bindings, $payload['route_id'])) {
                return;
            }
            $locked = true;
            if ($connection->transactionLevel() < 1) {
                throw new RuntimeException('Route lock was acquired outside a transaction.');
            }
            if ($payload['coordination'] === 'holder') {
                $this->signal($payload, 'locked', 'holder');
                $this->wait($payload, 'attempt', 'contender');
                $this->wait($payload, 'probed', 'probe');
            }
        });
        $auditFailed = false;
        $rolledBackIds = null;
        if ($payload['audit_failure'] ?? false) {
            AuditLog::created(function (AuditLog $audit) use ($payload, &$auditFailed, &$rolledBackIds): void {
                if ($audit->auditable_type === RoutePlan::class && (int) $audit->auditable_id === $payload['route_id']) {
                    $route = RoutePlan::findOrFail($payload['route_id']);
                    $record = RouteEstimateProvenance::where('route_plan_id', $route->id)->where('current_marker', 1)->sole();
                    if (DB::transactionLevel() !== 1 || ! DB::connection()->getPdo()->inTransaction()
                        || (int) $route->estimate_revision !== 1 || $route->status !== 'Planned' || $route->stops()->count() !== 2) {
                        throw new RuntimeException('The audit failure did not occur after all expected writes.');
                    }
                    $rolledBackIds = ['audit' => $audit->id, 'provenance' => $record->id,
                        'calculation' => $record->calculation_uuid, 'stops' => $route->stops()->pluck('id')->all()];
                    $auditFailed = true;
                    throw new RuntimeException(self::AUDIT_FAILURE);
                }
            });
        }
        [$method, $suffix, $controllerAction] = match ($action) {
            'planned' => ['POST', '/transition', 'transition'], 'manual' => ['PUT', '', 'update'],
            default => ['POST', '/'.$action, $action],
        };
        $request = Request::create('/route-planning/'.$payload['route_id'].$suffix, $method, $payload['request'], server: [
            'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        if (app('router')->getRoutes()->match($request)->getActionName() !== RoutePlanController::class.'@'.$controllerAction) {
            throw new RuntimeException('Unexpected production route action.');
        }
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        ['data' => $data, 'outcome' => $outcome, 'diagnostic' => $diagnostic] = self::assertResult($response->getStatusCode(), $response->getContent(), $payload,
            $connection->queryFailures, $connection->queryExceptionClasses, $auditFailed, $attempted, $locked,
            $connection->transactionLevel(), $connection->getPdo()->inTransaction(), $connection->recoveredDeadlocks);
        Http::assertNothingSent();

        return ['outcome' => $outcome, 'http_status' => $response->getStatusCode(), 'connection_id' => $identity['connection_id'],
            'control_connection_id' => $controlId, 'pid' => getmypid(), 'lock_acquired' => $locked,
            'revision' => $data['routePlan']['estimate_revision'] ?? null, 'provenance_status' => $data['estimateProvenance']['status'] ?? null,
            'rolled_back_ids' => $rolledBackIds, 'diagnostic' => $diagnostic];
    }

    /** A real MySQL NOWAIT failure proves an incompatible row lock is held; it does not inspect InnoDB wait metadata. */
    private function probe(array $payload): array
    {
        $this->wait($payload, 'locked', 'holder');
        $this->wait($payload, 'attempt', 'contender');
        $identity = IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::PRIMARY_CONNECTION);
        $connection = DB::connection(IsolatedMySqlEnvironment::PRIMARY_CONNECTION);
        $blocked = false;
        $connection->beginTransaction();
        try {
            $connection->select('SELECT id FROM route_plans WHERE id = ? FOR UPDATE NOWAIT', [$payload['route_id']]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            $blocked = true;
        } finally {
            $connection->rollBack();
        }
        if (! $blocked) {
            throw new RuntimeException('NOWAIT unexpectedly acquired the held route lock.');
        }
        $this->signal($payload, 'probed', 'probe');

        return ['outcome' => 'row_lock_confirmed', 'mysql_error' => 3572, 'probe_connection_id' => $identity['connection_id']];
    }

    private function signal(array $payload, string $phase, string $participant): void
    {
        DB::connection(IsolatedMySqlEnvironment::CONTROL_CONNECTION)->table('concurrency_test_barriers')->insertOrIgnore([
            'barrier_name' => $payload['barrier'].'-'.$phase, 'participant' => $participant,
        ]);
    }

    private function wait(array $payload, string $phase, string $participant): void
    {
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            if (DB::connection(IsolatedMySqlEnvironment::CONTROL_CONNECTION)->table('concurrency_test_barriers')
                ->where('barrier_name', $payload['barrier'].'-'.$phase)->where('participant', $participant)->exists()) {
                return;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        throw new RuntimeException('Timed out at route provenance synchronization phase '.$phase.'.');
    }
}
