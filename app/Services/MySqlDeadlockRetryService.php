<?php

namespace App\Services;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MySqlDeadlockRetryService
{
    public function run(Closure $callback, int $attempts = 3): mixed
    {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('Deadlock retry attempts must be positive.');
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $exception) {
                if (! $this->isMySqlDeadlock($exception) || $attempt === $attempts) {
                    throw $exception;
                }

                usleep($attempt * 10_000);
            }
        }

        throw new \LogicException('The bounded deadlock retry loop ended unexpectedly.');
    }

    public function isMySqlDeadlock(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '40001'
            && (int) ($exception->errorInfo[1] ?? 0) === 1213;
    }
}
