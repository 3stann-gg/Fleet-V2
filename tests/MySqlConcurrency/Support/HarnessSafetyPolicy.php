<?php

namespace Tests\MySqlConcurrency\Support;

use RuntimeException;

final class HarnessSafetyPolicy
{
    private const APPLICATION_SCHEMA_GRANT_TARGET = '`fleet\_concurrency\_test`.*';

    private const SENTINEL_GRANT_TARGET = '`fleet_concurrency_guard_test`.`_concurrency_test_guard`';

    public const DATABASE = 'fleet_concurrency_test';

    public const HOST = '127.0.0.1';

    public const PORT = '33087';

    public const USERNAME = 'fleet_concurrency';

    public const CURRENT_USER = 'fleet_concurrency@%';

    public const ENVIRONMENT = 'mysql-concurrency-test';

    public const GUARD_DATABASE = 'fleet_concurrency_guard_test';

    /** @param array<string, mixed> $configuration */
    public static function assertConfiguration(array $configuration): void
    {
        $expected = [
            'environment' => 'testing',
            'enabled' => '1',
            'driver' => 'mysql',
            'host' => self::HOST,
            'port' => self::PORT,
            'database' => self::DATABASE,
            'username' => self::USERNAME,
            'db_url' => '',
        ];

        foreach ($expected as $key => $value) {
            if ((string) ($configuration[$key] ?? '') !== $value) {
                throw new RuntimeException("Unsafe MySQL concurrency configuration: {$key} does not match the dedicated test environment.");
            }
        }

        if ((string) ($configuration['password'] ?? '') === '') {
            throw new RuntimeException('The dedicated MySQL concurrency password is missing.');
        }

        if (! self::isUuidV4((string) ($configuration['instance_id'] ?? ''))) {
            throw new RuntimeException('MYSQL_CONCURRENCY_INSTANCE_ID must be a unique UUIDv4.');
        }
    }

    /** @param array<string, mixed> $sentinel */
    public static function assertSentinel(array $sentinel, string $expectedInstanceId, string $serverUuid): void
    {
        if (
            (int) ($sentinel['id'] ?? 0) !== 1
            || (string) ($sentinel['environment_name'] ?? '') !== self::ENVIRONMENT
            || ! hash_equals($expectedInstanceId, (string) ($sentinel['instance_id'] ?? ''))
            || ! hash_equals($serverUuid, (string) ($sentinel['server_uuid'] ?? ''))
        ) {
            throw new RuntimeException('The disposable MySQL instance sentinel does not match this test instance.');
        }
    }

    /**
     * @param  array<int, string>  $grants
     */
    public static function assertPrivileges(
        array $grants,
        string $currentRole,
        string $mandatoryRoles,
        bool $activateAllRolesOnLogin
    ): void {
        if ($grants === []) {
            throw new RuntimeException('MySQL grants could not be verified.');
        }

        if (strtoupper(trim($currentRole)) !== 'NONE') {
            throw new RuntimeException('The concurrency test account must not have an active role.');
        }

        if (trim($mandatoryRoles) !== '' || $activateAllRolesOnLogin) {
            throw new RuntimeException('MySQL role activation is not permitted for the concurrency test account.');
        }

        foreach ($grants as $grant) {
            $normalized = strtoupper(trim($grant));

            if (str_contains($normalized, 'WITH GRANT OPTION') || str_contains($normalized, 'GRANT PROXY')) {
                throw new RuntimeException('The concurrency test account has an administrative grant.');
            }

            if (preg_match('/^GRANT (?<privileges>.+?) ON (?<target>.+?) TO .+$/i', trim($grant), $matches) !== 1) {
                throw new RuntimeException('Assigned MySQL roles are not permitted for the concurrency test account.');
            }

            $privileges = strtoupper(trim($matches['privileges']));
            $target = trim($matches['target']);

            if ($privileges === 'USAGE' && $target === '*.*') {
                continue;
            }

            if ($target === '*.*') {
                throw new RuntimeException('The concurrency test account has global privileges.');
            }

            $isApplicationSchemaGrant = $privileges === 'ALL PRIVILEGES'
                && hash_equals(self::APPLICATION_SCHEMA_GRANT_TARGET, $target);
            $isSentinelReadGrant = $privileges === 'SELECT'
                && hash_equals(self::SENTINEL_GRANT_TARGET, $target);

            if (! $isApplicationSchemaGrant && ! $isSentinelReadGrant) {
                throw new RuntimeException('The concurrency test account has privileges outside the disposable schema.');
            }
        }
    }

    /** @param array<string, string|null> $engines */
    public static function assertInnoDbTables(array $engines): void
    {
        if ($engines === []) {
            throw new RuntimeException('No migrated base tables were found in the disposable schema.');
        }

        foreach ($engines as $table => $engine) {
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                throw new RuntimeException("Table {$table} does not use InnoDB.");
            }
        }
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $rows
     */
    public static function assertInnoDbTableRows(iterable $rows): void
    {
        $engines = [];

        foreach ($rows as $row) {
            $attributes = (array) $row;

            foreach (['schema_name', 'table_name', 'storage_engine'] as $requiredAttribute) {
                if (! array_key_exists($requiredAttribute, $attributes)) {
                    throw new RuntimeException("The information_schema result is missing the required {$requiredAttribute} alias.");
                }
            }

            $schema = $attributes['schema_name'];
            $table = $attributes['table_name'];
            $engine = $attributes['storage_engine'];

            if (! is_string($schema) || ! in_array($schema, [self::DATABASE, self::GUARD_DATABASE], true)) {
                throw new RuntimeException('An information_schema result belongs to an unexpected schema.');
            }

            if (! is_string($table) || $table === '') {
                throw new RuntimeException('An information_schema result has an invalid table name.');
            }

            $qualifiedTable = "{$schema}.{$table}";

            if (array_key_exists($qualifiedTable, $engines)) {
                throw new RuntimeException("Duplicate information_schema result for {$qualifiedTable}.");
            }

            $engines[$qualifiedTable] = is_string($engine) ? $engine : null;
        }

        self::assertInnoDbTables($engines);

        if (! array_key_exists(self::DATABASE.'.migrations', $engines)) {
            throw new RuntimeException('The disposable application schema is missing its migrations table.');
        }

        if (! array_key_exists(self::GUARD_DATABASE.'._concurrency_test_guard', $engines)) {
            throw new RuntimeException('The disposable guard schema is missing its sentinel table.');
        }
    }

    public static function lockName(string $instanceId): string
    {
        if (! self::isUuidV4($instanceId)) {
            throw new RuntimeException('A valid instance UUID is required for the concurrency lock.');
        }

        return 'fleet-concurrency:'.substr(hash('sha256', self::DATABASE.'|'.$instanceId), 0, 40);
    }

    public static function assertExternalLockOwnership(
        string $expectedName,
        ?string $providedName,
        ?string $providedOwner,
        mixed $liveOwner,
        mixed $verifierConnectionId
    ): int {
        $providedOwnerId = (string) self::assertExternalLockHandoff($expectedName, $providedName, $providedOwner);
        $liveOwnerId = self::positiveIntegerString($liveOwner);
        $verifierId = self::positiveIntegerString($verifierConnectionId);

        if (
            $liveOwnerId === null
            || $verifierId === null
            || ! hash_equals($providedOwnerId, $liveOwnerId)
            || hash_equals($liveOwnerId, $verifierId)
        ) {
            throw new RuntimeException('The parent concurrency lock owner is missing, malformed, stale, or incorrect.');
        }

        return (int) $providedOwnerId;
    }

    public static function assertExternalLockHandoff(
        string $expectedName,
        ?string $providedName,
        ?string $providedOwner
    ): int {
        if ($providedName === null || ! hash_equals($expectedName, $providedName)) {
            throw new RuntimeException('The child process did not inherit the exact parent lock name.');
        }

        $providedOwnerId = self::positiveIntegerString($providedOwner);

        if ($providedOwnerId === null) {
            throw new RuntimeException('The inherited parent concurrency lock owner is missing or malformed.');
        }

        return (int) $providedOwnerId;
    }

    public static function assertSuccessfulSmokeTestExitCode(int $exitCode): void
    {
        if ($exitCode !== 0) {
            throw new RuntimeException('The guarded MySQL environment smoke tests failed or were considered risky.');
        }
    }

    public static function assertSuccessfulBusinessTestExitCode(int $exitCode): void
    {
        if ($exitCode !== 0) {
            throw new RuntimeException('The guarded MySQL business concurrency tests failed or were considered risky.');
        }
    }

    /**
     * @param  array<string, mixed>  $baseConfiguration
     * @param  array<string, mixed>  $registeredConnections
     * @param  array<int, string>  $requiredNames
     */
    public static function assertIndependentConnectionConfigurations(
        array $baseConfiguration,
        array $registeredConnections,
        array $requiredNames
    ): void {
        $expectedBase = [
            'driver' => 'mysql',
            'host' => self::HOST,
            'port' => self::PORT,
            'database' => self::DATABASE,
            'username' => self::USERNAME,
        ];

        foreach ($expectedBase as $key => $expectedValue) {
            if ((string) ($baseConfiguration[$key] ?? '') !== $expectedValue) {
                throw new RuntimeException("The base concurrency connection has an unsafe {$key} value.");
            }
        }

        if (isset($baseConfiguration['url']) && (string) $baseConfiguration['url'] !== '') {
            throw new RuntimeException('The concurrency connection must not use a database URL fallback.');
        }

        foreach ($requiredNames as $connectionName) {
            $registered = $registeredConnections[$connectionName] ?? null;

            if (! is_array($registered) || $registered !== $baseConfiguration) {
                throw new RuntimeException("Database connection {$connectionName} is missing or is not an exact isolated clone.");
            }
        }
    }

    private static function isUuidV4(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private static function positiveIntegerString(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($integer) && (string) $integer === $value ? $value : null;
    }
}
