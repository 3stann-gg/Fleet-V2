<?php

namespace Tests\MySqlConcurrency\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DatabaseBarrier
{
    public static function createTable(): void
    {
        IsolatedMySqlEnvironment::assertSafe(
            IsolatedMySqlEnvironment::CONTROL_CONNECTION
        );

        DB::connection(IsolatedMySqlEnvironment::CONTROL_CONNECTION)->statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS concurrency_test_barriers (
                barrier_name VARCHAR(120) NOT NULL,
                participant VARCHAR(120) NOT NULL,
                arrived_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
                PRIMARY KEY (barrier_name, participant)
            ) ENGINE=InnoDB
            SQL);
    }

    public static function reset(string $barrierName): void
    {
        self::createTable();

        DB::connection(IsolatedMySqlEnvironment::CONTROL_CONNECTION)
            ->table('concurrency_test_barriers')
            ->where('barrier_name', $barrierName)
            ->delete();
    }

    public static function arriveAndWait(
        string $barrierName,
        string $participant,
        int $expectedParticipants,
        int $timeoutMilliseconds = 5000
    ): void {
        if ($expectedParticipants < 1 || $timeoutMilliseconds < 1) {
            throw new RuntimeException('Barrier participant count and timeout must be positive.');
        }

        self::createTable();
        $connection = DB::connection(IsolatedMySqlEnvironment::CONTROL_CONNECTION);

        $connection->table('concurrency_test_barriers')->insertOrIgnore([
            'barrier_name' => $barrierName,
            'participant' => $participant,
        ]);

        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            $arrived = $connection->table('concurrency_test_barriers')
                ->where('barrier_name', $barrierName)
                ->count();

            if ($arrived >= $expectedParticipants) {
                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("Timed out waiting at concurrency barrier {$barrierName}.");
    }
}
