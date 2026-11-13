<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Best-effort scalar telemetry only: exceptions and request data never enter the log context. */
final class RouteSaveDiagnosticService
{
    private const CORRELATION_ATTRIBUTE = '_fleet_route_save_diagnostic_id';

    public static function failed(string $operation, Throwable $exception): void
    {
        self::write('warning', 'fleet.route_save.failed', [
            'operation' => in_array($operation, ['create', 'update', 'transition', 'archive', 'restore', 'duplicate'], true) ? $operation : 'unknown',
            'outcome' => 'failed',
        ], $exception);
    }

    public static function retryScheduled(int $failedAttempt): void
    {
        self::write('notice', 'fleet.route_save.deadlock_retry', [
            'operation' => 'route_transaction', 'outcome' => 'retry_scheduled',
            'attempt' => $failedAttempt, 'max_attempts' => 3, 'transaction_clean' => true,
        ]);
    }

    public static function recovered(int $attempt): void
    {
        self::write('notice', 'fleet.route_save.deadlock_recovered', [
            'operation' => 'route_transaction', 'outcome' => 'committed_after_retry',
            'attempt' => $attempt, 'max_attempts' => 3,
        ]);
    }

    /** @param array<string, string|int|bool> $context */
    private static function write(string $level, string $event, array $context, ?Throwable $exception = null): void
    {
        try {
            $request = app()->bound('request') ? app('request') : null;
            $request = $request instanceof Request ? $request : null;
            $correlation = $request?->attributes->get(self::CORRELATION_ATTRIBUTE);
            if (! is_string($correlation) || ! Str::isUuid($correlation)) {
                $correlation = (string) Str::uuid();
                $request?->attributes->set(self::CORRELATION_ATTRIBUTE, $correlation);
            }
            $context['correlation_id'] = $correlation;
            if ($exception !== null) {
                $state = $exception instanceof QueryException ? ($exception->errorInfo[0] ?? null) : null;
                $number = $exception instanceof QueryException ? ($exception->errorInfo[1] ?? null) : null;
                $number = is_string($number) && preg_match('/\A[0-9]{1,5}\z/', $number) === 1 ? (int) $number : $number;
                $state = in_array($state, ['40001', '23000', 'HY000', '42000', '42S02', '42S22', 'HY093', '08000', '08001', '08003', '08004', '08006', '08S01'], true) ? $state : null;
                $number = in_array($number, [1062, 1205, 1213, 1451, 1452, 3819], true) ? $number : null;
                $context += [
                    'category' => match (true) {
                        ! $exception instanceof QueryException => 'operation_failure',
                        $state === '40001' && $number === 1213 => 'deadlock',
                        $state === 'HY000' && $number === 1205 => 'lock_timeout',
                        $state === '23000' && $number === 1062 => 'unique_constraint',
                        $state === '23000' && in_array($number, [1451, 1452], true) => 'foreign_key',
                        $number === 3819 => 'check_constraint',
                        default => 'query_failure',
                    },
                    'sqlstate' => $state, 'database_error' => $number,
                ];
            }
            Log::log($level, $event, $context);
        } catch (Throwable) {
            // Monitoring must never replace a Save result, change rollback, or trigger a retry.
        }
    }
}
