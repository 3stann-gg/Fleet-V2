<?php

namespace Tests\MySqlConcurrency\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

final class HarnessSafetyPolicy
{
    public const ACTIVE_EVENT_UNIQUE_INDEX = 'fleet_notifications_user_active_event_unique';

    public const LEGACY_EVENT_UNIQUE_INDEX = 'fleet_notifications_user_id_event_key_unique';

    public const NOTIFICATION_TABLE = 'fleet_notifications';

    private const APPLICATION_SCHEMA_GRANT_TARGET = '`fleet\_concurrency\_test`.*';

    private const SENTINEL_GRANT_TARGET = '`fleet_concurrency_guard_test`.`_concurrency_test_guard`';

    public const DATABASE = 'fleet_concurrency_test';

    public const HOST = '127.0.0.1';

    public const PORT = '33087';

    public const USERNAME = 'fleet_concurrency';

    public const CURRENT_USER = 'fleet_concurrency@%';

    public const ENVIRONMENT = 'mysql-concurrency-test';

    public const GUARD_DATABASE = 'fleet_concurrency_guard_test';

    public const ROUTE_DUPLICATE_MODE = '--prepare-and-route-provenance-duplicate-uuid';

    public const ROUTE_DUPLICATE_GROUP = 'mysql-route-provenance-duplicate-uuid';

    /** @var list<string> */
    public const RUNNER_MODES = [
        '--prepare-only',
        '--smoke-only',
        '--business-only',
        '--prepare-and-smoke',
        '--prepare-and-occurrence',
        '--prepare-and-route-provenance',
        self::ROUTE_DUPLICATE_MODE,
    ];

    /** Reject additional filters/options before configuration loading or database access. */
    public static function runnerMode(array $arguments): string
    {
        if (count($arguments) !== 2 || ! is_string($arguments[1] ?? null)) {
            throw new RuntimeException('Exactly one guarded mode is required; additional options are not supported.');
        }
        self::assertRunnerMode($arguments[1]);

        return $arguments[1];
    }

    public static function assertRunnerMode(string $mode): void
    {
        if (! in_array($mode, self::RUNNER_MODES, true)) {
            throw new RuntimeException(
                'Refusing to run. Choose an explicitly guarded MySQL concurrency mode.'
            );
        }
    }

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

    public static function assertSuccessfulRouteProvenanceExitCode(int $exitCode): void
    {
        if ($exitCode !== 0) {
            throw new RuntimeException("The guarded route provenance concurrency tests failed or were risky (exit {$exitCode}).");
        }
    }

    public static function assertSuccessfulOccurrenceTestExitCode(int $exitCode): void
    {
        if ($exitCode !== 0) {
            throw new RuntimeException('The guarded MySQL occurrence concurrency tests failed or were considered risky.');
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public static function assertExpectedStaleWinnerResponse(array $response): void
    {
        $connectionIdValue = $response['connection_id'] ?? null;
        $connectionId = is_int($connectionIdValue) && $connectionIdValue > 0
            ? $connectionIdValue
            : null;
        $expected = [
            'worker_action' => 'create_with_collision_barrier',
            'connection_id' => $connectionId,
            'outcome' => 'expected_stale_winner_conflict',
            'exception' => UniqueConstraintViolationException::class,
            'driver_code' => 1062,
            'index_name' => self::ACTIVE_EVENT_UNIQUE_INDEX,
        ];

        if (! is_int($connectionId) || $response !== $expected) {
            $actualWorkerAction = self::allowlistedDiagnosticValue(
                $response['worker_action'] ?? null,
                OccurrenceConcurrencyWorker::ACTIONS
            );
            $actualOutcome = self::allowlistedDiagnosticValue(
                $response['outcome'] ?? null,
                [
                    'expected_stale_winner_conflict',
                    'created',
                    'recovered',
                    'failed_closed',
                    'error',
                ]
            );
            $actualException = self::allowlistedDiagnosticValue(
                $response['exception'] ?? null,
                [
                    UniqueConstraintViolationException::class,
                    \LogicException::class,
                    RuntimeException::class,
                ]
            );
            $actualIndexName = self::allowlistedDiagnosticValue(
                $response['index_name'] ?? null,
                [
                    self::ACTIVE_EVENT_UNIQUE_INDEX,
                    self::LEGACY_EVENT_UNIQUE_INDEX,
                    'cc_unrelated_unique_index',
                ]
            );
            $actualDriverCode = match (true) {
                ! is_int($response['driver_code'] ?? null) => '[non-integer]',
                $response['driver_code'] === 1062 => '1062',
                default => '[unexpected]',
            };

            throw new RuntimeException(
                'The occurrence worker returned an unexpected stale-winner outcome. '.
                'expected_worker_action=create_with_collision_barrier '.
                "actual_worker_action={$actualWorkerAction} ".
                'expected_outcome=expected_stale_winner_conflict '.
                "actual_outcome={$actualOutcome} ".
                'expected_exception='.UniqueConstraintViolationException::class.' '.
                "actual_exception={$actualException} ".
                'expected_driver_code=1062 '.
                "actual_driver_code={$actualDriverCode} ".
                'expected_index_name='.self::ACTIVE_EVENT_UNIQUE_INDEX.' '.
                "actual_index_name={$actualIndexName} ".
                'response_fields='.self::diagnosticResponseFields($response)
            );
        }
    }

    /** @param list<string> $allowlist */
    private static function allowlistedDiagnosticValue(mixed $value, array $allowlist): string
    {
        if (! is_string($value)) {
            return '[non-string]';
        }

        return in_array($value, $allowlist, true)
            ? $value
            : '[unexpected]';
    }

    /** @param array<string, mixed> $response */
    private static function diagnosticResponseFields(array $response): string
    {
        $allowlist = [
            'worker_action',
            'connection_id',
            'outcome',
            'exception',
            'driver_code',
            'index_name',
        ];
        $recognizedFields = array_values(array_intersect($allowlist, array_keys($response)));
        $unexpectedFieldCount = count(array_diff(array_keys($response), $allowlist));

        return '['.implode(',', $recognizedFields).']'.
            ";unexpected_field_count={$unexpectedFieldCount}";
    }

    /**
     * @param  array<string, array<string, bool|int|string|null>>  $results
     */
    public static function assertSuccessfulOccurrenceCreationRaceResults(
        array $results,
        string $eventKey
    ): void {
        if (count($results) !== 2) {
            throw new RuntimeException('The occurrence creation race must return exactly two worker results.');
        }

        $connectionIds = [];
        $notificationIds = [];
        $outcomes = [];

        foreach ($results as $result) {
            $connectionId = filter_var(
                $result['connection_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $notificationId = filter_var(
                $result['notification_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $outcome = $result['outcome'] ?? null;

            if (
                ! is_int($connectionId)
                || ! is_int($notificationId)
                || ! in_array($outcome, ['created', 'recovered'], true)
                || ($result['event_key'] ?? null) !== $eventKey
                || ($result['active_event_key'] ?? null) !== $eventKey
                || ($result['status'] ?? null) !== 'Unread'
            ) {
                throw new RuntimeException('The occurrence creation worker returned an invalid successful result.');
            }

            $expectedEvidence = $outcome === 'created'
                ? [true, false]
                : [false, true];
            $actualEvidence = [
                $result['initial_insert_committed'] ?? null,
                $result['active_collision_recovered'] ?? null,
            ];

            if ($actualEvidence !== $expectedEvidence) {
                throw new RuntimeException('The occurrence creation worker returned contradictory outcome evidence.');
            }

            $connectionIds[] = $connectionId;
            $notificationIds[] = $notificationId;
            $outcomes[] = $outcome;
        }

        sort($outcomes);

        if (
            count(array_unique($connectionIds)) !== 2
            || count(array_unique($notificationIds)) !== 1
            || $outcomes !== ['created', 'recovered']
        ) {
            throw new RuntimeException('The occurrence creation race did not produce one creator and one collision recovery.');
        }
    }

    /**
     * @param  array{occurrences: bool, after_commit: bool, scheduled_recovery: bool, historical: bool}  $flags
     */
    public static function assertLifecycleFlags(array $flags, bool $occurrenceMode): void
    {
        $expected = [
            'occurrences' => $occurrenceMode,
            'after_commit' => false,
            'scheduled_recovery' => false,
            'historical' => false,
        ];

        if ($flags !== $expected) {
            throw new RuntimeException('Notification lifecycle flags do not match the guarded test mode.');
        }
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $rows
     */
    public static function assertNotificationIndexes(
        iterable $rows,
        bool $legacyExpected
    ): void {
        $indexes = [];

        foreach ($rows as $row) {
            $attributes = (array) $row;

            foreach (
                ['schema_name', 'table_name', 'index_name', 'non_unique', 'sequence_number', 'column_name'] as $requiredAttribute
            ) {
                if (! array_key_exists($requiredAttribute, $attributes)) {
                    throw new RuntimeException("The notification index result is missing the required {$requiredAttribute} alias.");
                }
            }

            if (
                $attributes['schema_name'] !== self::DATABASE
                || $attributes['table_name'] !== self::NOTIFICATION_TABLE
                || ! is_string($attributes['index_name'])
                || ! is_string($attributes['column_name'])
            ) {
                throw new RuntimeException('Notification index metadata belongs to an unexpected schema, table, or identifier.');
            }

            $sequence = filter_var(
                $attributes['sequence_number'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $nonUnique = filter_var(
                $attributes['non_unique'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0, 'max_range' => 1]]
            );

            if (! is_int($sequence) || ! is_int($nonUnique)) {
                throw new RuntimeException('Notification index metadata has an invalid sequence or uniqueness value.');
            }

            $name = $attributes['index_name'];

            if (isset($indexes[$name]['columns'][$sequence])) {
                throw new RuntimeException("Notification index {$name} contains duplicate sequence metadata.");
            }

            if (isset($indexes[$name]['non_unique']) && $indexes[$name]['non_unique'] !== $nonUnique) {
                throw new RuntimeException("Notification index {$name} has inconsistent uniqueness metadata.");
            }

            $indexes[$name]['non_unique'] = $nonUnique;
            $indexes[$name]['columns'][$sequence] = $attributes['column_name'];
        }

        foreach ($indexes as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);

        $expectedUniqueIndexes = [
            'PRIMARY' => ['id'],
            self::ACTIVE_EVENT_UNIQUE_INDEX => ['user_id', 'active_event_key'],
        ];

        if ($legacyExpected) {
            $expectedUniqueIndexes[self::LEGACY_EVENT_UNIQUE_INDEX] = ['user_id', 'event_key'];
        }

        $actualUniqueIndexes = collect($indexes)
            ->filter(fn (array $index): bool => $index['non_unique'] === 0)
            ->map(fn (array $index): array => $index['columns'])
            ->all();

        ksort($expectedUniqueIndexes);
        ksort($actualUniqueIndexes);

        if ($actualUniqueIndexes !== $expectedUniqueIndexes) {
            throw new RuntimeException('The disposable notification unique-index contract is missing or unexpected.');
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

    /** Docker's --tmpfs configuration is recorded in HostConfig.Tmpfs, not necessarily Mounts. */
    public static function assertDisposableMySqlStorage(array $container): void
    {
        $host = $container['HostConfig'] ?? null;
        $tmpfs = is_array($host) ? ($host['Tmpfs'] ?? null) : null;
        if (! is_array($tmpfs) || ! array_key_exists('/var/lib/mysql', $tmpfs)
            || ! is_string($tmpfs['/var/lib/mysql'])
            || in_array('ro', explode(',', $tmpfs['/var/lib/mysql']), true)) {
            throw new RuntimeException('MySQL data must have an explicit writable HostConfig.Tmpfs configuration.');
        }
        if (array_keys($tmpfs) !== ['/var/lib/mysql']
            || ! in_array($host['VolumesFrom'] ?? null, [null, []], true)
            || ! in_array($host['Mounts'] ?? null, [null, []], true)) {
            throw new RuntimeException('Unexpected inherited or additional storage configuration.');
        }
        $mounts = $container['Mounts'] ?? null;
        if (! is_array($mounts) || ! array_is_list($mounts)) {
            throw new RuntimeException('Complete Docker mount metadata is required.');
        }
        $destinations = [];
        foreach ($mounts as $mount) {
            if (! is_array($mount)) {
                throw new RuntimeException('Malformed Docker mount metadata.');
            }
            $destination = $mount['Destination'] ?? null;
            if (! is_string($destination) || in_array($destination, $destinations, true)) {
                throw new RuntimeException('Missing or duplicate Docker mount destination.');
            }
            $destinations[] = $destination;
            $allowedInitializer = ($mount['Type'] ?? null) === 'bind'
                && ($mount['Destination'] ?? null) === '/docker-entrypoint-initdb.d/01-concurrency-guard.sh'
                && ($mount['RW'] ?? null) === false;
            $explicitTmpfs = ($mount['Type'] ?? null) === 'tmpfs' && $destination === '/var/lib/mysql' && ($mount['RW'] ?? null) === true;
            if (! $allowedInitializer && ! $explicitTmpfs) {
                throw new RuntimeException('Unexpected persistent, overlapping or unapproved Docker mount.');
            }
        }
        $binds = $host['Binds'] ?? [];
        if ($binds === null) {
            $binds = [];
        }
        if (! is_array($binds) || ! array_is_list($binds) || count($binds) > 1) {
            throw new RuntimeException('Unexpected Docker bind configuration.');
        }
        foreach ($binds as $bind) {
            if (! is_string($bind) || preg_match('~\A[^\r\n]+:/docker-entrypoint-initdb\.d/01-concurrency-guard\.sh:ro\z~', $bind) !== 1) {
                throw new RuntimeException('An unapproved Docker host bind is configured.');
            }
        }
        if (($container['Config']['Cmd'] ?? null) !== ['--default-storage-engine=InnoDB']
            || ($container['Config']['Entrypoint'] ?? null) !== ['docker-entrypoint.sh']) {
            throw new RuntimeException('MySQL command or entrypoint could override the approved data directory.');
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
