<?php

namespace Tests\MySqlConcurrency\Support;

use Dotenv\Dotenv;
use RuntimeException;

final class HarnessEnvironment
{
    public static function load(string $projectRoot): void
    {
        $path = $projectRoot.DIRECTORY_SEPARATOR.'.env.mysql-concurrency';

        if (! is_file($path)) {
            throw new RuntimeException('Missing .env.mysql-concurrency. Copy the example and generate new test-only credentials and instance UUID.');
        }

        Dotenv::createImmutable($projectRoot, '.env.mysql-concurrency')->safeLoad();
    }

    /** @return array<string, string> */
    public static function values(): array
    {
        return [
            'environment' => self::value('APP_ENV'),
            'enabled' => self::value('MYSQL_CONCURRENCY_ENABLED'),
            'driver' => 'mysql',
            'host' => self::value('MYSQL_CONCURRENCY_HOST'),
            'port' => self::value('MYSQL_CONCURRENCY_PORT'),
            'database' => self::value('MYSQL_CONCURRENCY_DATABASE'),
            'username' => self::value('MYSQL_CONCURRENCY_USERNAME'),
            'password' => self::value('MYSQL_CONCURRENCY_PASSWORD'),
            'instance_id' => self::value('MYSQL_CONCURRENCY_INSTANCE_ID'),
            'db_url' => self::value('DB_URL'),
        ];
    }

    /** @return array<string, string> */
    public static function processEnvironment(): array
    {
        $values = self::values();

        return [
            'APP_ENV' => $values['environment'],
            'DB_CONNECTION' => IsolatedMySqlEnvironment::CONNECTION,
            'DB_URL' => '',
            'MYSQL_CONCURRENCY_ENABLED' => $values['enabled'],
            'MYSQL_CONCURRENCY_HOST' => $values['host'],
            'MYSQL_CONCURRENCY_PORT' => $values['port'],
            'MYSQL_CONCURRENCY_DATABASE' => $values['database'],
            'MYSQL_CONCURRENCY_USERNAME' => $values['username'],
            'MYSQL_CONCURRENCY_PASSWORD' => $values['password'],
            'MYSQL_CONCURRENCY_INSTANCE_ID' => $values['instance_id'],
            'MYSQL_CONCURRENCY_LOCK_TIMEOUT_SECONDS' => self::value('MYSQL_CONCURRENCY_LOCK_TIMEOUT_SECONDS', '5'),
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
    }

    public static function inheritedValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) ? $value : null;
    }

    /** @return array<int, string> */
    public static function smokeTestCommand(string $projectRoot): array
    {
        return self::testCommand(
            $projectRoot,
            $projectRoot.'/tests/MySqlConcurrency/MySqlEnvironmentTest.php'
        );
    }

    /** @return array<int, string> */
    public static function businessTestCommand(string $projectRoot): array
    {
        return self::testCommand(
            $projectRoot,
            $projectRoot.'/tests/MySqlConcurrency/MaintenanceNotificationConcurrencyTest.php'
        );
    }

    /** @return array<string, string> */
    public static function workerEnvironment(): array
    {
        $lockName = self::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_NAME');
        $lockOwner = self::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_OWNER');

        if ($lockName === null || $lockOwner === null) {
            throw new RuntimeException('Concurrency workers require the guarded parent lock handoff.');
        }

        return array_merge(self::processEnvironment(), [
            'MYSQL_CONCURRENCY_PARENT_LOCK_NAME' => $lockName,
            'MYSQL_CONCURRENCY_PARENT_LOCK_OWNER' => $lockOwner,
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $payload
     * @return array<int, string>
     */
    public static function workerCommand(string $projectRoot, string $action, array $payload): array
    {
        $encodedPayload = base64_encode((string) json_encode($payload, JSON_THROW_ON_ERROR));

        return [
            PHP_BINARY,
            $projectRoot.'/tests/MySqlConcurrency/bin/worker.php',
            $action,
            $encodedPayload,
        ];
    }

    /** @return array<int, string> */
    private static function testCommand(string $projectRoot, string $testFile): array
    {
        return [
            PHP_BINARY,
            $projectRoot.'/vendor/bin/pest',
            '--configuration='.$projectRoot.'/phpunit.mysql-concurrency.xml',
            '--display-all-issues',
            '--fail-on-risky',
            $testFile,
        ];
    }

    private static function value(string $name, string $default = ''): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : $default;
    }
}
