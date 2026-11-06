<?php

use App\Services\MySqlDeadlockRetryService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function concurrencyQueryException(string $sqlState, int $driverCode): QueryException
{
    $previous = new PDOException('Simulated concurrency database failure.', $driverCode);
    $previous->errorInfo = [$sqlState, $driverCode, 'Simulated concurrency database failure.'];

    return new QueryException('testing', 'select 1', [], $previous);
}

it('retries only an exact MySQL deadlock and rolls back the failed attempt', function () {
    $attempts = 0;

    $result = app(MySqlDeadlockRetryService::class)->run(function () use (&$attempts): string {
        $attempts++;
        DB::table('users')->insert([
            'name' => 'Deadlock attempt '.$attempts,
            'email' => "deadlock-attempt-{$attempts}@example.test",
            'password' => 'not-a-working-password',
            'role' => 'staff',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($attempts === 1) {
            throw concurrencyQueryException('40001', 1213);
        }

        return 'committed';
    });

    expect($result)->toBe('committed')
        ->and($attempts)->toBe(2)
        ->and(DB::table('users')->where('email', 'deadlock-attempt-1@example.test')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'deadlock-attempt-2@example.test')->exists())->toBeTrue();
});

it('does not retry non-deadlock database failures', function (string $sqlState, int $driverCode) {
    $attempts = 0;

    $operation = function () use (&$attempts, $sqlState, $driverCode): void {
        app(MySqlDeadlockRetryService::class)->run(
            function () use (&$attempts, $sqlState, $driverCode): void {
                $attempts++;
                throw concurrencyQueryException($sqlState, $driverCode);
            }
        );
    };

    expect($operation)->toThrow(QueryException::class);

    expect($attempts)->toBe(1);
})->with([
    'lock wait timeout' => ['HY000', 1205],
    'unique violation' => ['23000', 1062],
    'audit check violation' => ['HY000', 3819],
    'serialization without MySQL deadlock code' => ['40001', 0],
]);

it('stops after the configured deadlock retry bound', function () {
    $attempts = 0;

    $operation = function () use (&$attempts): void {
        app(MySqlDeadlockRetryService::class)->run(
            function () use (&$attempts): void {
                $attempts++;
                throw concurrencyQueryException('40001', 1213);
            },
            3
        );
    };

    expect($operation)->toThrow(QueryException::class);

    expect($attempts)->toBe(3);
});
