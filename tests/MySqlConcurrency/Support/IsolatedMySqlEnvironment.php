<?php

namespace Tests\MySqlConcurrency\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class IsolatedMySqlEnvironment
{
    public const CONNECTION = 'mysql_concurrency';

    public const PRIMARY_CONNECTION = 'mysql_concurrency_primary';

    public const SECONDARY_CONNECTION = 'mysql_concurrency_secondary';

    public const CONTROL_CONNECTION = 'mysql_concurrency_control';

    /**
     * @return array{database: string, current_user: string, server_uuid: string, version: string, storage_engine: string, isolation_level: string, connection_id: int}
     */
    public static function assertSafe(string $connectionName = self::CONNECTION): array
    {
        $configuration = config("database.connections.{$connectionName}");

        if (! is_array($configuration)) {
            throw new RuntimeException('The dedicated MySQL concurrency connection is not configured.');
        }

        HarnessSafetyPolicy::assertConfiguration([
            'environment' => app()->environment(),
            'enabled' => (string) config('mysql-concurrency.enabled'),
            'driver' => $configuration['driver'] ?? '',
            'host' => $configuration['host'] ?? '',
            'port' => $configuration['port'] ?? '',
            'database' => $configuration['database'] ?? '',
            'username' => $configuration['username'] ?? '',
            'password' => $configuration['password'] ?? '',
            'instance_id' => config('mysql-concurrency.instance_id'),
            'db_url' => $_ENV['DB_URL'] ?? $_SERVER['DB_URL'] ?? getenv('DB_URL') ?: '',
        ]);

        $identity = self::connection($connectionName)->selectOne(<<<'SQL'
            SELECT DATABASE() AS database_name,
                CURRENT_USER() AS current_user_name,
                CURRENT_ROLE() AS current_role_name,
                @@server_uuid AS server_uuid,
                VERSION() AS version_number,
                @@default_storage_engine AS storage_engine,
                @@transaction_isolation AS isolation_level,
                @@GLOBAL.mandatory_roles AS mandatory_roles,
                @@GLOBAL.activate_all_roles_on_login AS activate_all_roles,
                CONNECTION_ID() AS connection_id
            SQL);

        if (! is_object($identity)) {
            throw new RuntimeException('Unable to verify the concurrency database identity.');
        }

        $database = (string) $identity->database_name;
        $currentUser = (string) $identity->current_user_name;
        $serverUuid = (string) $identity->server_uuid;
        $version = (string) $identity->version_number;
        $storageEngine = (string) $identity->storage_engine;

        if ($database !== HarnessSafetyPolicy::DATABASE || $currentUser !== HarnessSafetyPolicy::CURRENT_USER) {
            throw new RuntimeException('The connected database or account is not the exact disposable test identity.');
        }

        if (! str_starts_with($version, '8.') || strcasecmp($storageEngine, 'InnoDB') !== 0) {
            throw new RuntimeException('The concurrency suite requires MySQL 8 with InnoDB.');
        }

        $sentinel = (array) self::connection($connectionName)->selectOne(
            'SELECT id, instance_id, server_uuid, environment_name FROM fleet_concurrency_guard_test._concurrency_test_guard WHERE id = 1'
        );

        HarnessSafetyPolicy::assertSentinel($sentinel, (string) config('mysql-concurrency.instance_id'), $serverUuid);

        $grants = collect(self::connection($connectionName)->select('SHOW GRANTS FOR CURRENT_USER'))
            ->flatMap(fn (object $row): array => array_values((array) $row))
            ->map(fn (mixed $grant): string => (string) $grant)
            ->all();

        HarnessSafetyPolicy::assertPrivileges(
            $grants,
            (string) $identity->current_role_name,
            (string) $identity->mandatory_roles,
            (bool) $identity->activate_all_roles
        );

        return [
            'database' => $database,
            'current_user' => $currentUser,
            'server_uuid' => $serverUuid,
            'version' => $version,
            'storage_engine' => $storageEngine,
            'isolation_level' => (string) $identity->isolation_level,
            'connection_id' => (int) $identity->connection_id,
        ];
    }

    public static function assertAllBaseTablesUseInnoDb(string $connectionName = self::CONNECTION): void
    {
        $rows = self::connection($connectionName)
            ->table('information_schema.tables')
            ->whereIn('TABLE_SCHEMA', [HarnessSafetyPolicy::DATABASE, HarnessSafetyPolicy::GUARD_DATABASE])
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->selectRaw('TABLE_SCHEMA AS schema_name, TABLE_NAME AS table_name, ENGINE AS storage_engine')
            ->get();

        HarnessSafetyPolicy::assertInnoDbTableRows($rows);
    }

    public static function advisoryLock(string $connectionName = self::CONTROL_CONNECTION): MySqlAdvisoryLock
    {
        $pdo = self::connection($connectionName)->getPdo();

        return new MySqlAdvisoryLock(
            HarnessSafetyPolicy::lockName((string) config('mysql-concurrency.instance_id')),
            (int) config('mysql-concurrency.lock_timeout_seconds', 5),
            static function (string $sql, array $bindings) use ($pdo): mixed {
                $statement = $pdo->prepare($sql);
                $statement->execute($bindings);

                return $statement->fetchColumn();
            }
        );
    }

    public static function assertExternalLockOwner(string $connectionName = self::CONTROL_CONNECTION): void
    {
        self::assertIndependentConnectionsConfigured();
        $expectedName = HarnessSafetyPolicy::lockName((string) config('mysql-concurrency.instance_id'));
        $providedName = HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_NAME');
        $providedOwner = HarnessEnvironment::inheritedValue('MYSQL_CONCURRENCY_PARENT_LOCK_OWNER');

        $owner = self::connection($connectionName)->selectOne(
            'SELECT IS_USED_LOCK(?) AS owner_id, CONNECTION_ID() AS verifier_connection_id',
            [$expectedName]
        );

        if (
            ! is_object($owner)
            || ! property_exists($owner, 'owner_id')
            || ! property_exists($owner, 'verifier_connection_id')
        ) {
            throw new RuntimeException('The live parent concurrency lock owner could not be queried.');
        }

        HarnessSafetyPolicy::assertExternalLockOwnership(
            $expectedName,
            $providedName,
            $providedOwner,
            $owner->owner_id,
            $owner->verifier_connection_id
        );
    }

    public static function configureIndependentConnections(): void
    {
        $configuration = config('database.connections.'.self::CONNECTION);

        if (! is_array($configuration)) {
            throw new RuntimeException('The base MySQL concurrency connection is not configured.');
        }

        foreach (self::independentConnectionNames() as $connectionName) {
            config(["database.connections.{$connectionName}" => $configuration]);
            DB::purge($connectionName);
        }

        self::assertIndependentConnectionsConfigured();
    }

    public static function assertIndependentConnectionsConfigured(): void
    {
        $baseConfiguration = config('database.connections.'.self::CONNECTION);

        if (! is_array($baseConfiguration)) {
            throw new RuntimeException('The base MySQL concurrency connection is not configured.');
        }

        $registeredConnections = [];

        foreach (self::independentConnectionNames() as $connectionName) {
            $registeredConnections[$connectionName] = config("database.connections.{$connectionName}");
        }

        HarnessSafetyPolicy::assertIndependentConnectionConfigurations(
            $baseConfiguration,
            $registeredConnections,
            self::independentConnectionNames()
        );
    }

    /** @return array<int, string> */
    public static function independentConnectionNames(): array
    {
        return [self::PRIMARY_CONNECTION, self::SECONDARY_CONNECTION, self::CONTROL_CONNECTION];
    }

    private static function connection(string $connectionName): Connection
    {
        $connection = DB::connection($connectionName);

        if (! $connection instanceof Connection || ! $connection->getPdo() instanceof PDO) {
            throw new RuntimeException('Unable to resolve the MySQL concurrency connection.');
        }

        return $connection;
    }
}
