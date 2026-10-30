<?php

use Mockery as M;
use Symfony\Component\Process\Process;
use Tests\MySqlConcurrency\Support\AuditFailureConstraint;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\MySqlAdvisoryLock;

function safeHarnessConfiguration(array $overrides = []): array
{
    return array_replace([
        'environment' => 'testing',
        'enabled' => '1',
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '33087',
        'database' => 'fleet_concurrency_test',
        'username' => 'fleet_concurrency',
        'password' => 'local-test-password',
        'instance_id' => '123e4567-e89b-42d3-a456-426614174000',
        'db_url' => '',
    ], $overrides);
}

it('accepts only the exact dedicated database identity', function (array $override) {
    expect(fn () => HarnessSafetyPolicy::assertConfiguration(safeHarnessConfiguration($override)))
        ->toThrow(RuntimeException::class);
})->with([
    'wrong database' => [['database' => 'another_test']],
    'wrong host' => [['host' => 'localhost']],
    'wrong port' => [['port' => '3306']],
    'missing opt-in' => [['enabled' => '']],
    'normal environment' => [['environment' => 'local']],
    'DB URL fallback' => [['db_url' => 'mysql://normal-database']],
    'invalid instance identity' => [['instance_id' => 'not-a-uuid']],
]);

it('accepts the exact dedicated configuration', function () {
    HarnessSafetyPolicy::assertConfiguration(safeHarnessConfiguration());

    expect(true)->toBeTrue();
});

it('rejects missing mismatched and reused sentinel identities', function (array $sentinel) {
    expect(fn () => HarnessSafetyPolicy::assertSentinel(
        $sentinel,
        '123e4567-e89b-42d3-a456-426614174000',
        'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'
    ))->toThrow(RuntimeException::class);
})->with([
    'missing sentinel' => [[]],
    'different instance' => [[
        'id' => 1,
        'instance_id' => '123e4567-e89b-42d3-a456-426614174999',
        'server_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        'environment_name' => 'mysql-concurrency-test',
    ]],
    'copied sentinel on another server' => [[
        'id' => 1,
        'instance_id' => '123e4567-e89b-42d3-a456-426614174000',
        'server_uuid' => 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee',
        'environment_name' => 'mysql-concurrency-test',
    ]],
]);

it('accepts only direct privileges on the disposable schema', function () {
    HarnessSafetyPolicy::assertPrivileges([
        'GRANT USAGE ON *.* TO `fleet_concurrency`@`%`',
        'GRANT ALL PRIVILEGES ON `fleet\_concurrency\_test`.* TO `fleet_concurrency`@`%`',
        'GRANT SELECT ON `fleet_concurrency_guard_test`.`_concurrency_test_guard` TO `fleet_concurrency`@`%`',
    ], 'NONE', '', false);

    expect(true)->toBeTrue();
});

it('rejects excessive direct or role-derived privileges', function (array $grants, string $role, string $mandatory, bool $activateAll) {
    expect(fn () => HarnessSafetyPolicy::assertPrivileges($grants, $role, $mandatory, $activateAll))
        ->toThrow(RuntimeException::class);
})->with([
    'global modification' => [['GRANT INSERT ON *.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'cross schema' => [['GRANT SELECT ON `fleet`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'unescaped schema wildcard underscores' => [['GRANT ALL PRIVILEGES ON `fleet_concurrency_test`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'percent schema wildcard' => [['GRANT ALL PRIVILEGES ON `fleet\_concurrency\_%`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'escaped percent lookalike' => [['GRANT ALL PRIVILEGES ON `fleet\_concurrency\_\%`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'double backslash lookalike' => [['GRANT ALL PRIVILEGES ON `fleet\\\\_concurrency\\\\_test`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'unexpected similarly named schema' => [['GRANT ALL PRIVILEGES ON `fleet\_concurrency\_test_backup`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'guard schema wildcard' => [['GRANT SELECT ON `fleet_concurrency_guard_test`.* TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'guard table suffix' => [['GRANT SELECT ON `fleet_concurrency_guard_test`.`_concurrency_test_guard_backup` TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'guard table modification' => [['GRANT UPDATE ON `fleet_concurrency_guard_test`.`_concurrency_test_guard` TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'unexpected main-schema table grant' => [['GRANT SELECT ON `fleet_concurrency_test`.`maintenances` TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'valid set plus cross schema' => [[
        'GRANT USAGE ON *.* TO `fleet_concurrency`@`%`',
        'GRANT ALL PRIVILEGES ON `fleet\_concurrency\_test`.* TO `fleet_concurrency`@`%`',
        'GRANT SELECT ON `fleet_concurrency_guard_test`.`_concurrency_test_guard` TO `fleet_concurrency`@`%`',
        'GRANT SELECT ON `normal_development`.* TO `fleet_concurrency`@`%`',
    ], 'NONE', '', false],
    'grant option' => [['GRANT ALL PRIVILEGES ON `fleet\_concurrency\_test`.* TO `fleet_concurrency`@`%` WITH GRANT OPTION'], 'NONE', '', false],
    'assigned role' => [['GRANT `database_admin`@`%` TO `fleet_concurrency`@`%`'], 'NONE', '', false],
    'active role' => [['GRANT USAGE ON *.* TO `fleet_concurrency`@`%`'], '`database_admin`@`%`', '', false],
    'mandatory role' => [['GRANT USAGE ON *.* TO `fleet_concurrency`@`%`'], 'NONE', '`mandatory`@`%`', false],
    'automatic roles' => [['GRANT USAGE ON *.* TO `fleet_concurrency`@`%`'], 'NONE', '', true],
]);

it('rejects any non InnoDB base table', function () {
    expect(fn () => HarnessSafetyPolicy::assertInnoDbTables([
        'maintenances' => 'InnoDB',
        'fleet_notifications' => 'MyISAM',
    ]))->toThrow(RuntimeException::class);
});

it('accepts correctly aliased InnoDB rows from both disposable schemas', function () {
    HarnessSafetyPolicy::assertInnoDbTableRows([
        (object) [
            'schema_name' => 'fleet_concurrency_test',
            'table_name' => 'migrations',
            'storage_engine' => 'InnoDB',
        ],
        (object) [
            'schema_name' => 'fleet_concurrency_guard_test',
            'table_name' => '_concurrency_test_guard',
            'storage_engine' => 'InnoDB',
        ],
    ]);

    expect(true)->toBeTrue();
});

it('rejects missing or differently cased information schema aliases', function (object $row) {
    expect(fn () => HarnessSafetyPolicy::assertInnoDbTableRows([$row]))
        ->toThrow(RuntimeException::class);
})->with([
    'missing schema alias' => [(object) ['table_name' => 'migrations', 'storage_engine' => 'InnoDB']],
    'canonical uppercase without aliases' => [(object) [
        'TABLE_SCHEMA' => 'fleet_concurrency_test',
        'TABLE_NAME' => 'migrations',
        'ENGINE' => 'InnoDB',
    ]],
]);

it('rejects unexpected schema and unsafe engine results without fallback', function (object $row) {
    expect(fn () => HarnessSafetyPolicy::assertInnoDbTableRows([$row]))
        ->toThrow(RuntimeException::class);
})->with([
    'normal development schema' => [(object) [
        'schema_name' => 'fleet',
        'table_name' => 'migrations',
        'storage_engine' => 'InnoDB',
    ]],
    'null engine' => [(object) [
        'schema_name' => 'fleet_concurrency_test',
        'table_name' => 'migrations',
        'storage_engine' => null,
    ]],
    'unexpected engine' => [(object) [
        'schema_name' => 'fleet_concurrency_test',
        'table_name' => 'migrations',
        'storage_engine' => 'MyISAM',
    ]],
]);

it('requires both the migrated application schema and exact guard sentinel', function (array $rows) {
    expect(fn () => HarnessSafetyPolicy::assertInnoDbTableRows($rows))
        ->toThrow(RuntimeException::class);
})->with([
    'guard only' => [[(object) [
        'schema_name' => 'fleet_concurrency_guard_test',
        'table_name' => '_concurrency_test_guard',
        'storage_engine' => 'InnoDB',
    ]]],
    'application only' => [[(object) [
        'schema_name' => 'fleet_concurrency_test',
        'table_name' => 'migrations',
        'storage_engine' => 'InnoDB',
    ]]],
]);

it('serializes preparation and test execution with one owned advisory lock', function () {
    $held = false;
    $connectionId = 41;
    $query = function (string $sql) use (&$held, $connectionId): int {
        return match (true) {
            str_contains($sql, 'GET_LOCK') => $held ? 0 : (int) ($held = true),
            str_contains($sql, 'CONNECTION_ID') => $connectionId,
            str_contains($sql, 'IS_USED_LOCK') => $held ? $connectionId : 0,
            str_contains($sql, 'RELEASE_LOCK') => (int) ! ($held = false),
            default => 0,
        };
    };
    $first = new MySqlAdvisoryLock('fleet-concurrency:test', 1, $query(...));
    $second = new MySqlAdvisoryLock('fleet-concurrency:test', 1, $query(...));

    expect($first->acquire())->toBe($connectionId)
        ->and(fn () => $second->acquire())->toThrow(RuntimeException::class);

    $first->release();
});

it('fails closed when advisory lock ownership is lost', function () {
    $query = fn (string $sql): int => match (true) {
        str_contains($sql, 'GET_LOCK') => 1,
        str_contains($sql, 'CONNECTION_ID') => 52,
        str_contains($sql, 'IS_USED_LOCK') => 0,
        default => 0,
    };
    $lock = new MySqlAdvisoryLock('fleet-concurrency:test', 1, $query(...));

    expect(fn () => $lock->acquire())->toThrow(RuntimeException::class);
});

it('accepts only an exact inherited parent lock with matching live MySQL ownership', function () {
    $lockName = HarnessSafetyPolicy::lockName('123e4567-e89b-42d3-a456-426614174000');

    expect(HarnessSafetyPolicy::assertExternalLockOwnership($lockName, $lockName, '52', 52, 77))->toBe(52);
});

it('accepts a well formed parent handoff before the Laravel test application boots', function () {
    $lockName = HarnessSafetyPolicy::lockName('123e4567-e89b-42d3-a456-426614174000');

    expect(HarnessSafetyPolicy::assertExternalLockHandoff($lockName, $lockName, '52'))->toBe(52);
});

it('rejects missing malformed stale or incorrect parent lock identity', function (?string $name, ?string $owner, mixed $liveOwner, mixed $verifierId = 77) {
    $expectedName = HarnessSafetyPolicy::lockName('123e4567-e89b-42d3-a456-426614174000');

    expect(fn () => HarnessSafetyPolicy::assertExternalLockOwnership($expectedName, $name, $owner, $liveOwner, $verifierId))
        ->toThrow(RuntimeException::class);
})->with(function (): array {
    $expectedName = HarnessSafetyPolicy::lockName('123e4567-e89b-42d3-a456-426614174000');

    return [
        'missing lock name' => [null, '52', 52],
        'incorrect lock name' => [$expectedName.'-other', '52', 52],
        'missing owner id' => [$expectedName, null, 52],
        'zero owner id' => [$expectedName, '0', 0],
        'negative owner id' => [$expectedName, '-1', -1],
        'owner id with whitespace' => [$expectedName, ' 52', 52],
        'partially numeric owner id' => [$expectedName, '52abc', 52],
        'malformed live owner id' => [$expectedName, '52', '52abc'],
        'incorrect owner id' => [$expectedName, '52', 53],
        'parent released before verification' => [$expectedName, '52', null],
        'child owns lock itself' => [$expectedName, '52', 52, 52],
        'malformed verifier id' => [$expectedName, '52', 52, 'invalid'],
    ];
});

it('reads inherited runner values through getenv when PHP does not populate ENV', function () {
    $name = 'CODEX_MYSQL_CONCURRENCY_PARENT_PROBE';
    $previous = getenv($name);

    try {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv("{$name}=inherited-value");

        expect(HarnessEnvironment::inheritedValue($name))->toBe('inherited-value');
    } finally {
        if (is_string($previous)) {
            putenv("{$name}={$previous}");
        } else {
            putenv($name);
        }
    }
});

it('builds a diagnostic smoke command that fails on risky tests', function () {
    $command = HarnessEnvironment::smokeTestCommand('C:/isolated-project');

    expect($command)->toContain('--display-all-issues')
        ->and($command)->toContain('--fail-on-risky')
        ->and($command)->not->toContain('--compact')
        ->and($command)->toContain('C:/isolated-project/tests/MySqlConcurrency/MySqlEnvironmentTest.php');
});

it('keeps guarded business scenarios separate from smoke tests', function () {
    $smokeCommand = HarnessEnvironment::smokeTestCommand('C:/isolated-project');
    $businessCommand = HarnessEnvironment::businessTestCommand('C:/isolated-project');

    expect($businessCommand)->toContain('--display-all-issues')
        ->and($businessCommand)->toContain('--fail-on-risky')
        ->and($businessCommand)->toContain('C:/isolated-project/tests/MySqlConcurrency/MaintenanceNotificationConcurrencyTest.php')
        ->and($businessCommand)->not->toContain('C:/isolated-project/tests/MySqlConcurrency/MySqlEnvironmentTest.php')
        ->and($smokeCommand)->not->toContain('C:/isolated-project/tests/MySqlConcurrency/MaintenanceNotificationConcurrencyTest.php');
});

it('encodes worker payloads without putting database credentials in commands', function () {
    $command = HarnessEnvironment::workerCommand('C:/isolated-project', 'reconcile', [
        'event_key' => 'maintenance_due:42:2026-12-01',
    ]);
    $payload = json_decode((string) base64_decode($command[3], true), true, 512, JSON_THROW_ON_ERROR);

    expect($command[1])->toBe('C:/isolated-project/tests/MySqlConcurrency/bin/worker.php')
        ->and($command[2])->toBe('reconcile')
        ->and($payload)->toBe(['event_key' => 'maintenance_due:42:2026-12-01'])
        ->and(implode(' ', $command))->not->toContain('MYSQL_CONCURRENCY_PASSWORD');
});

it('uses only the installed Symfony Process option for Windows process groups', function () {
    $options = ConcurrentProcessRunner::processOptions('Windows');
    $process = new Process([PHP_BINARY, '-r', 'exit(0);']);

    $process->setOptions($options);

    expect($options)->toBe(['create_process_group' => true])
        ->and(ConcurrentProcessRunner::processOptions('Linux'))->toBe([])
        ->and(fn () => $process->setOptions(['create_new_process_group' => true]))
        ->toThrow(LogicException::class);
});

it('reports worker startup causes while redacting concurrency credentials', function () {
    $secret = 'diagnostic-secret-that-must-not-leak';
    $previous = getenv('MYSQL_CONCURRENCY_PASSWORD');
    putenv("MYSQL_CONCURRENCY_PASSWORD={$secret}");
    $_ENV['MYSQL_CONCURRENCY_PASSWORD'] = $secret;
    $_SERVER['MYSQL_CONCURRENCY_PASSWORD'] = $secret;
    $runner = new ConcurrentProcessRunner(
        fn (): Process => throw new LogicException("Invalid process option near {$secret}")
    );

    try {
        $runner->run(['worker' => ['php']], 1);
        $this->fail('The worker startup failure was not propagated.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('Invalid process option')
            ->and($exception->getMessage())->toContain('[REDACTED]')
            ->and($exception->getMessage())->not->toContain($secret);
    } finally {
        if (is_string($previous)) {
            putenv("MYSQL_CONCURRENCY_PASSWORD={$previous}");
            $_ENV['MYSQL_CONCURRENCY_PASSWORD'] = $previous;
            $_SERVER['MYSQL_CONCURRENCY_PASSWORD'] = $previous;
        } else {
            putenv('MYSQL_CONCURRENCY_PASSWORD');
            unset($_ENV['MYSQL_CONCURRENCY_PASSWORD'], $_SERVER['MYSQL_CONCURRENCY_PASSWORD']);
        }
    }
});

it('builds a notification-specific enforced audit failure constraint', function () {
    $constraintName = AuditFailureConstraint::PREFIX.'abcdef123456';
    $addStatement = AuditFailureConstraint::addStatement($constraintName, 42);
    $dropStatement = AuditFailureConstraint::dropStatement($constraintName);

    expect($addStatement)->toContain('ALTER TABLE `audit_logs` ADD CONSTRAINT')
        ->and($addStatement)->toContain("`action` = 'Notification Resolved'")
        ->and($addStatement)->toContain('`auditable_id` = 42')
        ->and($addStatement)->not->toContain('TRIGGER')
        ->and($dropStatement)->toBe("ALTER TABLE `audit_logs` DROP CHECK `{$constraintName}`")
        ->and(fn () => AuditFailureConstraint::addStatement('unowned_constraint', 42))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => AuditFailureConstraint::addStatement($constraintName, 0))
        ->toThrow(InvalidArgumentException::class);
});

it('propagates every nonzero smoke test exit code as a harness failure', function (int $exitCode) {
    expect(fn () => HarnessSafetyPolicy::assertSuccessfulSmokeTestExitCode($exitCode))
        ->toThrow(RuntimeException::class);
})->with([
    'ordinary test failure' => 1,
    'risky test exit' => 2,
    'runner error' => 255,
]);

it('accepts only a zero smoke test exit code', function () {
    HarnessSafetyPolicy::assertSuccessfulSmokeTestExitCode(0);

    expect(true)->toBeTrue();
});

it('uses a business-specific failure message and accepts only a zero exit code', function () {
    expect(fn () => HarnessSafetyPolicy::assertSuccessfulBusinessTestExitCode(1))
        ->toThrow(RuntimeException::class, 'business concurrency tests failed')
        ->and(fn () => HarnessSafetyPolicy::assertSuccessfulBusinessTestExitCode(2))
        ->toThrow(RuntimeException::class, 'business concurrency tests failed');

    HarnessSafetyPolicy::assertSuccessfulBusinessTestExitCode(0);

    expect(true)->toBeTrue();
});

it('requires primary secondary and control registrations as exact isolated clones', function () {
    $base = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '33087',
        'database' => 'fleet_concurrency_test',
        'username' => 'fleet_concurrency',
        'password' => 'test-only-password',
        'url' => null,
    ];
    $names = [
        'mysql_concurrency_primary',
        'mysql_concurrency_secondary',
        'mysql_concurrency_control',
    ];

    HarnessSafetyPolicy::assertIndependentConnectionConfigurations(
        $base,
        array_fill_keys($names, $base),
        $names
    );

    expect(true)->toBeTrue();
});

it('detects missing or unsafe independent connection registration', function (array $base, array $registered) {
    $names = [
        'mysql_concurrency_primary',
        'mysql_concurrency_secondary',
        'mysql_concurrency_control',
    ];

    expect(fn () => HarnessSafetyPolicy::assertIndependentConnectionConfigurations($base, $registered, $names))
        ->toThrow(RuntimeException::class);
})->with(function (): array {
    $safeBase = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => '33087',
        'database' => 'fleet_concurrency_test',
        'username' => 'fleet_concurrency',
        'password' => 'test-only-password',
        'url' => null,
    ];
    $safeConnections = [
        'mysql_concurrency_primary' => $safeBase,
        'mysql_concurrency_secondary' => $safeBase,
        'mysql_concurrency_control' => $safeBase,
    ];

    return [
        'missing control connection' => [$safeBase, array_diff_key($safeConnections, ['mysql_concurrency_control' => true])],
        'missing primary connection' => [$safeBase, array_diff_key($safeConnections, ['mysql_concurrency_primary' => true])],
        'missing secondary connection' => [$safeBase, array_diff_key($safeConnections, ['mysql_concurrency_secondary' => true])],
        'normal mysql fallback' => [array_replace($safeBase, ['database' => 'fleet', 'port' => '3306']), $safeConnections],
        'DB URL fallback' => [array_replace($safeBase, ['url' => 'mysql://normal-database']), $safeConnections],
        'mismatched control clone' => [$safeBase, array_replace($safeConnections, [
            'mysql_concurrency_control' => array_replace($safeBase, ['database' => 'fleet']),
        ])],
    ];
});

it('cleans up only workers launched by the runner after a timeout', function () {
    $owned = M::mock(Process::class);
    $unrelated = M::mock(Process::class);
    $terminated = [];

    $owned->shouldReceive('start')->once();
    $owned->shouldReceive('wait')->once()->andThrow(new RuntimeException('timeout'));
    $owned->shouldReceive('isRunning')->once()->andReturnTrue();
    $owned->shouldReceive('getExitCode')->once()->andReturnNull();
    $owned->shouldReceive('getOutput')->once()->andReturn('partial output');
    $owned->shouldReceive('getErrorOutput')->once()->andReturn('timeout');
    $unrelated->shouldNotReceive('isRunning');

    $runner = new ConcurrentProcessRunner(
        fn (): Process => $owned,
        function (Process $process) use (&$terminated): bool {
            $terminated[] = $process;

            return true;
        }
    );

    expect(fn () => $runner->run(['owned' => ['php', 'worker.php']], 1))
        ->toThrow(RuntimeException::class)
        ->and($terminated)->toBe([$owned]);
});

it('cleans an earlier worker when a later worker fails during startup', function () {
    $first = M::mock(Process::class);
    $second = M::mock(Process::class);
    $created = collect([$first, $second]);
    $terminated = [];

    $first->shouldReceive('start')->once();
    $second->shouldReceive('start')->once()->andThrow(new RuntimeException('start failed'));
    $first->shouldReceive('isRunning')->once()->andReturnTrue();
    $second->shouldReceive('isRunning')->once()->andReturnFalse();
    foreach ([$first, $second] as $process) {
        $process->shouldReceive('getExitCode')->once()->andReturnNull();
        $process->shouldReceive('getOutput')->once()->andReturn('');
        $process->shouldReceive('getErrorOutput')->once()->andReturn('');
    }

    $runner = new ConcurrentProcessRunner(
        fn (): Process => $created->shift(),
        function (Process $process) use (&$terminated): bool {
            $terminated[] = $process;

            return true;
        }
    );

    expect(fn () => $runner->run(['first' => ['php'], 'second' => ['php']], 1))
        ->toThrow(RuntimeException::class)
        ->and($terminated)->toBe([$first]);
});

it('keeps normal SQLite configuration isolated and business concurrency execution explicitly guarded', function () {
    $projectRoot = dirname(__DIR__, 2);
    $normalConfiguration = file_get_contents($projectRoot.'/phpunit.xml');
    $pestConfiguration = file_get_contents($projectRoot.'/tests/Pest.php');
    $concurrencyTestCase = file_get_contents($projectRoot.'/tests/MySqlConcurrency/TestCase.php');
    $concurrencyBootstrap = file_get_contents($projectRoot.'/tests/MySqlConcurrency/bootstrap.php');
    $businessSpecifications = file_get_contents($projectRoot.'/tests/MySqlConcurrency/MaintenanceNotificationConcurrencyTest.php');
    $worker = file_get_contents($projectRoot.'/tests/MySqlConcurrency/bin/worker.php');
    $businessWorker = file_get_contents($projectRoot.'/tests/MySqlConcurrency/Support/BusinessConcurrencyWorker.php');
    $runner = file_get_contents($projectRoot.'/tests/MySqlConcurrency/bin/run.php');
    $auditFailureConstraint = file_get_contents($projectRoot.'/tests/MySqlConcurrency/Support/AuditFailureConstraint.php');

    expect($normalConfiguration)->toContain('value="sqlite"')
        ->and($normalConfiguration)->toContain('value=":memory:"')
        ->and($pestConfiguration)->toContain("->use(RefreshDatabase::class)\n    ->in('Feature');")
        ->and($pestConfiguration)->toContain("pest()->extend(MySqlConcurrencyTestCase::class)\n    ->in('MySqlConcurrency');")
        ->and($concurrencyTestCase)->toContain('IsolatedMySqlEnvironment::configureIndependentConnections();')
        ->and($concurrencyTestCase)->toContain('IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);')
        ->and($concurrencyTestCase)->toContain('IsolatedMySqlEnvironment::assertExternalLockOwner();')
        ->and($concurrencyTestCase)->not->toContain('RefreshDatabase')
        ->and($concurrencyBootstrap)->not->toContain('bootstrap/app.php')
        ->and($concurrencyBootstrap)->toContain('HarnessSafetyPolicy::assertExternalLockHandoff(')
        ->and(substr_count($businessSpecifications, "it('"))->toBe(10)
        ->and($businessSpecifications)->not->toContain('->todo(')
        ->and(substr_count($businessSpecifications, "->group('known-production-race')"))->toBe(3)
        ->and($worker)->toContain('IsolatedMySqlEnvironment::assertExternalLockOwner();')
        ->and($worker)->toContain('IsolatedMySqlEnvironment::assertSafe();')
        ->and(strpos($businessWorker, "\$this->wait(\$payload, 'start');", strpos($businessWorker, 'private function oppositeVehicleReassignment')))
        ->toBeLessThan(strpos($businessWorker, 'app(MySqlDeadlockRetryService::class)->run', strpos($businessWorker, 'private function oppositeVehicleReassignment')))
        ->and($businessWorker)->toContain('}, attempts: 1);')
        ->and($runner)->toContain("'--business-only'")
        ->and($runner)->toContain("\$mode === '--business-only'")
        ->and($businessSpecifications)->not->toContain('CREATE TRIGGER')
        ->and($businessSpecifications)->toContain('new AuditFailureConstraint')
        ->and($auditFailureConstraint)->toContain("->where('CONSTRAINT_SCHEMA', HarnessSafetyPolicy::DATABASE)")
        ->and($auditFailureConstraint)->toContain("->where('TABLE_NAME', 'audit_logs')")
        ->and($auditFailureConstraint)->toContain('DROP CHECK');
});
