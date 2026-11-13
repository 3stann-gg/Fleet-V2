<?php

namespace Tests\MySqlConcurrency\Support;

use Closure;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;
use RuntimeException;

/** Test-only error telemetry: production HTTP handlers may intentionally sanitize QueryException. */
final class RouteProvenanceConnection extends MySqlConnection
{
    /** @var list<array{state: string, code: int, constraint: string}> */
    public array $queryFailures = [];

    /** @var list<array{exception_class: string, previous_exception_class: ?string}> */
    public array $queryExceptionClasses = [];

    /** @var list<array{attempt: int, state: string, code: int, rolled_back: bool, pdo_closed_before_restart: bool}> */
    public array $recoveredDeadlocks = [];

    /** Archive only a deadlock attempt whose full transaction ended before a new one starts. */
    public function beginTransaction()
    {
        $recovering = $this->transactionLevel() === 0 && $this->queryFailures !== [];
        if ($recovering && ($this->getPdo()->inTransaction()
            || $this->queryFailures !== [['state' => '40001', 'code' => 1213, 'constraint' => 'other']]
            || count($this->recoveredDeadlocks) >= 2)) {
            throw new RuntimeException('An unclean or unrelated SQL failure cannot become a recovered deadlock.');
        }
        parent::beginTransaction();
        if ($recovering) {
            $this->recoveredDeadlocks[] = ['attempt' => count($this->recoveredDeadlocks) + 1, 'state' => '40001', 'code' => 1213,
                'rolled_back' => true, 'pdo_closed_before_restart' => true];
            $this->queryFailures = [];
            $this->queryExceptionClasses = [];
        }
    }

    protected function runQueryCallback($query, $bindings, Closure $callback): mixed
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $exception) {
            $this->queryExceptionClasses[] = [
                'exception_class' => $exception instanceof UniqueConstraintViolationException ? UniqueConstraintViolationException::class : QueryException::class,
                'previous_exception_class' => $exception->getPrevious() instanceof PDOException ? PDOException::class : null,
            ];
            $this->queryFailures[] = [
                'state' => (string) ($exception->errorInfo[0] ?? ''),
                'code' => (int) ($exception->errorInfo[1] ?? 0),
                'constraint' => preg_match('/for key [\'`](?:[^\'`]*\.)?rep_calculation_uq[\'`]/i', (string) ($exception->errorInfo[2] ?? '')) === 1 ? 'rep_calculation_uq' : 'other',
            ];

            throw $exception;
        }
    }
}
