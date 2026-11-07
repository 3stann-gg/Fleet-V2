<?php

use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\MaintenanceNotificationOccurrenceGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Mockery as M;
use Symfony\Component\Process\Process;
use Tests\MySqlConcurrency\Support\AuditFailureConstraint;
use Tests\MySqlConcurrency\Support\BusinessConcurrencyWorker;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\MySqlAdvisoryLock;
use Tests\MySqlConcurrency\Support\OccurrenceConcurrencyWorker;
use Tests\MySqlConcurrency\Support\OccurrenceRunCoordinator;

final class HarnessDiagnosticStringableFixture implements Stringable
{
    public bool $wasInvoked = false;

    public function __construct(public readonly string $secretMarker) {}

    public function __toString(): string
    {
        $this->wasInvoked = true;

        throw new RuntimeException($this->secretMarker);
    }
}

/** @return array<int, object> */
function notificationIndexRows(bool $legacy = true): array
{
    $definitions = [
        'PRIMARY' => ['id'],
        HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX => ['user_id', 'active_event_key'],
    ];

    if ($legacy) {
        $definitions[HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX] = ['user_id', 'event_key'];
    }

    $rows = [];
    foreach ($definitions as $name => $columns) {
        foreach ($columns as $offset => $column) {
            $rows[] = (object) [
                'schema_name' => HarnessSafetyPolicy::DATABASE,
                'table_name' => HarnessSafetyPolicy::NOTIFICATION_TABLE,
                'index_name' => $name,
                'non_unique' => 0,
                'sequence_number' => $offset + 1,
                'column_name' => $column,
            ];
        }
    }

    return $rows;
}

function harnessUniqueViolation(string $indexName): UniqueConstraintViolationException
{
    $previous = new PDOException(
        "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key '{$indexName}'",
        1062
    );
    $previous->errorInfo = [
        '23000',
        1062,
        "Duplicate entry for key '{$indexName}'",
    ];

    return new UniqueConstraintViolationException(
        'mysql_concurrency',
        'insert into `fleet_notifications` (`user_id`, `active_event_key`) values (?, ?)',
        [1, 'maintenance_due:1:2027-01-01'],
        $previous
    );
}

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
    $occurrenceCommand = HarnessEnvironment::occurrenceTestCommand('C:/isolated-project');
    $notificationSuite = 'C:/isolated-project/tests/MySqlConcurrency/MaintenanceNotificationConcurrencyTest.php';
    $completionSuite = 'C:/isolated-project/tests/MySqlConcurrency/PreventiveMaintenanceCompletionConcurrencyTest.php';

    expect($businessCommand)->toContain('--display-all-issues')
        ->and($businessCommand)->toContain('--fail-on-risky')
        ->and(collect($businessCommand)->filter(
            fn (string $argument): bool => $argument === $notificationSuite
        ))->toHaveCount(1)
        ->and(collect($businessCommand)->filter(
            fn (string $argument): bool => $argument === $completionSuite
        ))->toHaveCount(1)
        ->and($businessCommand)->not->toContain('C:/isolated-project/tests/MySqlConcurrency/MySqlEnvironmentTest.php')
        ->and($smokeCommand)->not->toContain($notificationSuite)
        ->and($smokeCommand)->not->toContain($completionSuite)
        ->and($occurrenceCommand)->not->toContain($notificationSuite)
        ->and($occurrenceCommand)->not->toContain($completionSuite)
        ->and($occurrenceCommand)->toContain('C:/isolated-project/tests/MySqlConcurrency/NotificationOccurrenceConcurrencyTest.php');
});

it('allowlists only reviewed maintenance completion worker actions', function () {
    expect(BusinessConcurrencyWorker::ACTIONS)
        ->toContain('complete_maintenance', 'update_maintenance')
        ->not->toContain('arbitrary_sql', 'invoke_method')
        ->and(fn () => (new BusinessConcurrencyWorker)->run('arbitrary_sql', []))
        ->toThrow(InvalidArgumentException::class, 'Unknown concurrency worker action');
});

it('classifies only exact reviewed maintenance validation responses', function () {
    $validationMessage = 'Please check the maintenance information.';
    $recurrenceConflict = [
        'success' => false,
        'message' => $validationMessage,
        'errors' => [
            'next_schedule' => [
                'The saved Next Service Due date conflicts with the date calculated from the completion date.',
            ],
        ],
    ];
    $staleEdit = [
        'success' => false,
        'message' => $validationMessage,
        'errors' => [
            'next_schedule' => [
                'The saved Next Service Due date cannot be cleared while recurring maintenance remains enabled.',
            ],
        ],
    ];

    expect(BusinessConcurrencyWorker::classifyMaintenanceResponse(
        'completion',
        422,
        $recurrenceConflict
    ))->toBe('recurrence_conflict_rejected')
        ->and(BusinessConcurrencyWorker::classifyMaintenanceResponse(
            'generic_update',
            422,
            $staleEdit
        ))->toBe('stale_edit_rejected')
        ->and(BusinessConcurrencyWorker::classifyMaintenanceResponse(
            'completion',
            422,
            $staleEdit
        ))->toBe('unexpected_response')
        ->and(BusinessConcurrencyWorker::classifyMaintenanceResponse(
            'generic_update',
            422,
            $recurrenceConflict
        ))->toBe('unexpected_response')
        ->and(BusinessConcurrencyWorker::classifyMaintenanceResponse(
            'completion',
            422,
            [
                'message' => $validationMessage,
                'errors' => ['next_schedule' => ['Unrelated next schedule error.']],
            ]
        ))->toBe('unexpected_response');
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

it('accepts only explicit guarded runner modes including occurrence preparation', function () {
    foreach (HarnessSafetyPolicy::RUNNER_MODES as $mode) {
        HarnessSafetyPolicy::assertRunnerMode($mode);
    }

    expect(HarnessSafetyPolicy::RUNNER_MODES)->toContain('--prepare-and-occurrence')
        ->and(fn () => HarnessSafetyPolicy::assertRunnerMode('--occurrence-only'))
        ->toThrow(RuntimeException::class);
});

it('uses a dedicated occurrence command and strict failure propagation', function () {
    $command = HarnessEnvironment::occurrenceTestCommand('C:/isolated-project');

    expect($command)->toContain('--configuration=C:/isolated-project/phpunit.mysql-occurrence.xml')
        ->and($command)->toContain('--fail-on-risky')
        ->and($command)->toContain('C:/isolated-project/tests/MySqlConcurrency/NotificationOccurrenceConcurrencyTest.php')
        ->and(fn () => HarnessSafetyPolicy::assertSuccessfulOccurrenceTestExitCode(1))
        ->toThrow(RuntimeException::class, 'occurrence concurrency tests failed')
        ->and(fn () => HarnessSafetyPolicy::assertSuccessfulOccurrenceTestExitCode(2))
        ->toThrow(RuntimeException::class, 'occurrence concurrency tests failed');

    HarnessSafetyPolicy::assertSuccessfulOccurrenceTestExitCode(0);
});

it('accepts only the reviewed occurrence worker actions and coordination payload', function () {
    $payload = [
        'barrier' => 'maintenance-source-test',
        'participant' => 'creator',
        'participants' => 2,
    ];

    foreach (OccurrenceConcurrencyWorker::ACTIONS as $action) {
        OccurrenceConcurrencyWorker::assertSafeActionPayload($action, $payload);
    }

    expect(OccurrenceConcurrencyWorker::ACTIONS)
        ->toContain('update_vehicle_presentation')
        ->toContain('change_schedule_then_signal')
        ->toContain('complete_m2_then_signal')
        ->toContain('reassign_maintenance_vehicle')
        ->and(fn () => OccurrenceConcurrencyWorker::assertSafeActionPayload('unreviewed_action', $payload))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects malformed occurrence coordination and fabricated authority history', function (array $payload) {
    expect(fn () => OccurrenceConcurrencyWorker::assertSafeActionPayload('create', $payload))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing barrier' => [[
        'participant' => 'creator',
        'participants' => 2,
    ]],
    'empty participant' => [[
        'barrier' => 'source-test',
        'participant' => '',
        'participants' => 2,
    ]],
    'unsafe barrier characters' => [[
        'barrier' => 'source test;drop',
        'participant' => 'creator',
        'participants' => 2,
    ]],
    'zero participants' => [[
        'barrier' => 'source-test',
        'participant' => 'creator',
        'participants' => 0,
    ]],
    'fabricated history' => [[
        'barrier' => 'source-test',
        'participant' => 'creator',
        'participants' => 2,
        'authority_attempt_history' => [10, 20],
    ]],
]);

it('decodes complete occurrence worker evidence and rejects missing or reused identities', function () {
    $valid = [
        'first' => [
            'exit_code' => 0,
            'output' => json_encode([
                'worker_action' => 'create',
                'connection_id' => 41,
                'outcome' => 'created',
            ], JSON_THROW_ON_ERROR),
            'error_output' => '',
        ],
        'second' => [
            'exit_code' => 0,
            'output' => json_encode([
                'worker_action' => 'reconcile',
                'connection_id' => 42,
                'outcome' => 'reconciled',
            ], JSON_THROW_ON_ERROR),
            'error_output' => '',
        ],
    ];

    expect(OccurrenceConcurrencyWorker::decodeWorkerResults($valid, 2))->toHaveKeys(['first', 'second']);

    $missingOutput = $valid;
    $missingOutput['second']['output'] = '';
    $reusedConnection = $valid;
    $reusedConnection['second']['output'] = json_encode([
        'worker_action' => 'reconcile',
        'connection_id' => 41,
    ], JSON_THROW_ON_ERROR);
    $missingAction = $valid;
    $missingAction['second']['output'] = json_encode([
        'connection_id' => 42,
    ], JSON_THROW_ON_ERROR);

    expect(fn () => OccurrenceConcurrencyWorker::decodeWorkerResults($missingOutput, 2))
        ->toThrow(RuntimeException::class, 'returned no output')
        ->and(fn () => OccurrenceConcurrencyWorker::decodeWorkerResults($reusedConnection, 2))
        ->toThrow(RuntimeException::class, 'distinct MySQL sessions')
        ->and(fn () => OccurrenceConcurrencyWorker::decodeWorkerResults($missingAction, 2))
        ->toThrow(RuntimeException::class, 'incomplete evidence');
});

it('classifies only the exact active-index stale-winner conflict', function () {
    $service = new FleetNotificationKeyedCreationService(
        new FleetNotificationSchemaReadinessService,
        app(MaintenanceNotificationOccurrenceGuard::class)
    );
    $exception = harnessUniqueViolation(
        FleetNotificationSchemaReadinessService::ACTIVE_EVENT_UNIQUE_INDEX
    );
    $response = OccurrenceConcurrencyWorker::executeCollisionAttempt(
        fn () => throw $exception,
        $service
    );

    expect($response)->toBe([
        'outcome' => 'expected_stale_winner_conflict',
        'exception' => UniqueConstraintViolationException::class,
        'driver_code' => 1062,
        'index_name' => HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX,
    ]);
    HarnessSafetyPolicy::assertExpectedStaleWinnerResponse(array_merge(
        [
            'worker_action' => 'create_with_collision_barrier',
            'connection_id' => 42,
        ],
        $response
    ));
});

it('accepts only one proven occurrence creator and one proven collision recovery', function () {
    $eventKey = 'occurrence_generic:create:test';
    $results = [
        'first' => [
            'connection_id' => 41,
            'outcome' => 'created',
            'notification_id' => 91,
            'status' => 'Unread',
            'event_key' => $eventKey,
            'active_event_key' => $eventKey,
            'initial_insert_committed' => true,
            'active_collision_recovered' => false,
        ],
        'second' => [
            'connection_id' => 42,
            'outcome' => 'recovered',
            'notification_id' => 91,
            'status' => 'Unread',
            'event_key' => $eventKey,
            'active_event_key' => $eventKey,
            'initial_insert_committed' => false,
            'active_collision_recovered' => true,
        ],
    ];

    HarnessSafetyPolicy::assertSuccessfulOccurrenceCreationRaceResults($results, $eventKey);
    HarnessSafetyPolicy::assertSuccessfulOccurrenceCreationRaceResults(array_reverse($results), $eventKey);

    expect(true)->toBeTrue();
});

it('classifies successful occurrence creation only from exclusive insert or collision evidence', function () {
    expect(OccurrenceConcurrencyWorker::classifySuccessfulCreationOutcome(true, false))->toBe('created')
        ->and(OccurrenceConcurrencyWorker::classifySuccessfulCreationOutcome(false, true))->toBe('recovered')
        ->and(fn () => OccurrenceConcurrencyWorker::classifySuccessfulCreationOutcome(false, false))
        ->toThrow(RuntimeException::class)
        ->and(fn () => OccurrenceConcurrencyWorker::classifySuccessfulCreationOutcome(true, true))
        ->toThrow(RuntimeException::class);
});

it('recognizes only successful notification inserts on the isolated worker connection', function () {
    expect(OccurrenceConcurrencyWorker::isSuccessfulNotificationInsertQuery(
        'mysql_concurrency',
        'insert into `fleet_notifications` (`user_id`, `event_key`) values (?, ?)'
    ))->toBeTrue()
        ->and(OccurrenceConcurrencyWorker::isSuccessfulNotificationInsertQuery(
            'mysql_concurrency_control',
            'insert into `fleet_notifications` (`user_id`, `event_key`) values (?, ?)'
        ))->toBeFalse()
        ->and(OccurrenceConcurrencyWorker::isSuccessfulNotificationInsertQuery(
            'mysql_concurrency',
            'insert into `audit_logs` (`action`) values (?)'
        ))->toBeFalse()
        ->and(OccurrenceConcurrencyWorker::isSuccessfulNotificationInsertQuery(
            'mysql_concurrency',
            'update `fleet_notifications` set `message` = ?'
        ))->toBeFalse();
});

it('rejects invalid occurrence creation race classifications', function (Closure $mutate) {
    $eventKey = 'occurrence_generic:create:test';
    $results = [
        'first' => [
            'connection_id' => 41,
            'outcome' => 'created',
            'notification_id' => 91,
            'status' => 'Unread',
            'event_key' => $eventKey,
            'active_event_key' => $eventKey,
            'initial_insert_committed' => true,
            'active_collision_recovered' => false,
        ],
        'second' => [
            'connection_id' => 42,
            'outcome' => 'recovered',
            'notification_id' => 91,
            'status' => 'Unread',
            'event_key' => $eventKey,
            'active_event_key' => $eventKey,
            'initial_insert_committed' => false,
            'active_collision_recovered' => true,
        ],
    ];

    expect(fn () => HarnessSafetyPolicy::assertSuccessfulOccurrenceCreationRaceResults(
        $mutate($results),
        $eventKey
    ))->toThrow(RuntimeException::class);
})->with([
    'two creators' => [function (array $results): array {
        $results['second']['outcome'] = 'created';
        $results['second']['initial_insert_committed'] = true;
        $results['second']['active_collision_recovered'] = false;

        return $results;
    }],
    'two recoveries' => [function (array $results): array {
        $results['first']['outcome'] = 'recovered';
        $results['first']['initial_insert_committed'] = false;
        $results['first']['active_collision_recovered'] = true;

        return $results;
    }],
    'unknown outcome' => [function (array $results): array {
        $results['second']['outcome'] = 'unknown';

        return $results;
    }],
    'missing evidence' => [function (array $results): array {
        unset($results['first']['initial_insert_committed']);

        return $results;
    }],
    'contradictory evidence' => [function (array $results): array {
        $results['second']['initial_insert_committed'] = true;

        return $results;
    }],
    'same connection identity' => [function (array $results): array {
        $results['second']['connection_id'] = $results['first']['connection_id'];

        return $results;
    }],
    'different notification identity' => [function (array $results): array {
        $results['second']['notification_id'] = 92;

        return $results;
    }],
    'worker error response' => [function (array $results): array {
        $results['second'] = [
            'connection_id' => 42,
            'outcome' => 'error',
            'message' => 'worker failed',
        ];

        return $results;
    }],
]);

it('propagates unrelated logic and unique-constraint failures from collision attempts', function () {
    $service = new FleetNotificationKeyedCreationService(
        new FleetNotificationSchemaReadinessService,
        app(MaintenanceNotificationOccurrenceGuard::class)
    );
    $logicFailure = new LogicException('Unexpected readiness defect.');
    $uniqueFailure = harnessUniqueViolation('cc_unrelated_unique_index');

    expect(fn () => OccurrenceConcurrencyWorker::executeCollisionAttempt(
        fn () => throw $logicFailure,
        $service
    ))->toThrow($logicFailure)
        ->and(fn () => OccurrenceConcurrencyWorker::executeCollisionAttempt(
            fn () => throw $uniqueFailure,
            $service
        ))->toThrow($uniqueFailure);
});

it('rejects malformed and unexpected stale-winner worker responses', function (Closure $mutate) {
    $response = [
        'worker_action' => 'create_with_collision_barrier',
        'connection_id' => 42,
        'outcome' => 'expected_stale_winner_conflict',
        'exception' => UniqueConstraintViolationException::class,
        'driver_code' => 1062,
        'index_name' => HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX,
    ];

    expect(fn () => HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($mutate($response)))
        ->toThrow(RuntimeException::class);
})->with([
    'missing worker action' => [function (array $response): array {
        unset($response['worker_action']);

        return $response;
    }],
    'incorrect worker action' => [function (array $response): array {
        $response['worker_action'] = 'create';

        return $response;
    }],
    'missing required field' => [function (array $response): array {
        unset($response['exception']);

        return $response;
    }],
    'unexpected extra field' => [function (array $response): array {
        $response['unexpected'] = 'value';

        return $response;
    }],
    'wrong outcome' => [function (array $response): array {
        $response['outcome'] = 'failed_closed';

        return $response;
    }],
    'wrong exception class' => [function (array $response): array {
        $response['exception'] = LogicException::class;

        return $response;
    }],
    'wrong driver code' => [function (array $response): array {
        $response['driver_code'] = 1213;

        return $response;
    }],
    'wrong index' => [function (array $response): array {
        $response['index_name'] = 'cc_unrelated_unique_index';

        return $response;
    }],
    'missing connection identity' => [function (array $response): array {
        unset($response['connection_id']);

        return $response;
    }],
    'zero connection identity' => [function (array $response): array {
        $response['connection_id'] = 0;

        return $response;
    }],
    'negative connection identity' => [function (array $response): array {
        $response['connection_id'] = -1;

        return $response;
    }],
    'string connection identity' => [function (array $response): array {
        $response['connection_id'] = '42';

        return $response;
    }],
    'malformed response values' => [function (array $response): array {
        $response['worker_action'] = ['create_with_collision_barrier'];
        $response['outcome'] = null;

        return $response;
    }],
    'contradictory classification' => [function (array $response): array {
        $response['outcome'] = 'created';

        return $response;
    }],
]);

it('reports only allowlisted stale-winner diagnostics', function () {
    $response = [
        'worker_action' => 'DB_PASSWORD=do-not-expose',
        'connection_id' => 42,
        'outcome' => 'API_TOKEN=do-not-expose',
        'exception' => 'SecretException-do-not-expose',
        'driver_code' => 'SECRET-CODE-do-not-expose',
        'index_name' => 'secret_index_do_not_expose',
        'credentials_do_not_expose' => 'secret-value-do-not-expose',
    ];

    try {
        HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
        $this->fail('The malformed stale-winner response was accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('expected_worker_action=create_with_collision_barrier')
            ->toContain('actual_worker_action=[unexpected]')
            ->toContain('expected_outcome=expected_stale_winner_conflict')
            ->toContain('actual_outcome=[unexpected]')
            ->toContain('expected_exception='.UniqueConstraintViolationException::class)
            ->toContain('actual_exception=[unexpected]')
            ->toContain('expected_driver_code=1062')
            ->toContain('actual_driver_code=[non-integer]')
            ->toContain('expected_index_name='.HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX)
            ->toContain('actual_index_name=[unexpected]')
            ->toContain('response_fields=[worker_action,connection_id,outcome,exception,driver_code,index_name];unexpected_field_count=1')
            ->not->toContain('do-not-expose')
            ->not->toContain('secret-value')
            ->not->toContain('credentials_do_not_expose');
    }
});

it('uses only finite diagnostic tokens for invalid scalar and compound values', function () {
    $baseResponse = [
        'worker_action' => 'create_with_collision_barrier',
        'connection_id' => 42,
        'outcome' => 'expected_stale_winner_conflict',
        'exception' => UniqueConstraintViolationException::class,
        'driver_code' => 1062,
        'index_name' => HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX,
    ];

    foreach ([null, false, 123, 12.5, ['unsafe']] as $value) {
        $response = $baseResponse;
        $response['worker_action'] = $value;

        try {
            HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
            $this->fail('The malformed stale-winner response was accepted.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('actual_worker_action=[non-string]');
        }

    }

    foreach ([null, false, '1062', 12.5, ['unsafe']] as $value) {
        $response = $baseResponse;
        $response['driver_code'] = $value;

        try {
            HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
            $this->fail('The malformed stale-winner response was accepted.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toContain('actual_driver_code=[non-integer]');
        }
    }
});

it('redacts named and anonymous objects from stale-winner diagnostics', function () {
    $baseResponse = [
        'worker_action' => 'create_with_collision_barrier',
        'connection_id' => 42,
        'outcome' => 'expected_stale_winner_conflict',
        'exception' => UniqueConstraintViolationException::class,
        'driver_code' => 1062,
        'index_name' => HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX,
    ];
    $secretMarker = 'AH1-OBJECT-SECRET-MARKER';
    $namedObject = new stdClass;
    $namedObject->secretMarker = $secretMarker;
    $anonymousObject = new class($secretMarker)
    {
        public function __construct(public readonly string $secretMarker) {}
    };

    foreach ([$namedObject, $anonymousObject] as $object) {
        foreach (['worker_action', 'outcome', 'exception', 'index_name', 'driver_code'] as $field) {
            $response = $baseResponse;
            $response[$field] = $object;

            try {
                HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
                $this->fail('The malformed stale-winner response was accepted.');
            } catch (RuntimeException $exception) {
                $expectedToken = $field === 'driver_code' ? '[non-integer]' : '[non-string]';

                expect($exception->getMessage())
                    ->toContain($expectedToken)
                    ->toContain('unexpected stale-winner outcome')
                    ->not->toContain($secretMarker)
                    ->not->toContain('stdClass')
                    ->not->toContain('class@anonymous');
            }
        }
    }
});

it('does not stringify secret-bearing objects while diagnosing stale-winner responses', function () {
    $baseResponse = [
        'worker_action' => 'create_with_collision_barrier',
        'connection_id' => 42,
        'outcome' => 'expected_stale_winner_conflict',
        'exception' => UniqueConstraintViolationException::class,
        'driver_code' => 1062,
        'index_name' => HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX,
    ];
    $secretMarker = 'AH1-STRINGABLE-SECRET-MARKER';

    foreach (['worker_action', 'connection_id', 'outcome', 'exception', 'index_name', 'driver_code'] as $field) {
        $stringable = new HarnessDiagnosticStringableFixture($secretMarker);
        $response = $baseResponse;
        $response[$field] = $stringable;

        try {
            HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
            $this->fail('The malformed stale-winner response was accepted.');
        } catch (RuntimeException $exception) {
            expect($stringable->wasInvoked)->toBeFalse()
                ->and($exception->getMessage())
                ->toContain('unexpected stale-winner outcome')
                ->not->toContain($secretMarker)
                ->not->toContain(HarnessDiagnosticStringableFixture::class);
        }
    }
});

it('does not inspect nested objects or disclose unexpected field names', function () {
    $secretMarker = 'AH1-NESTED-OBJECT-SECRET-MARKER';
    $stringable = new HarnessDiagnosticStringableFixture($secretMarker);
    $unexpectedField = 'credentials_with_internal_name';
    $response = [
        'worker_action' => 'create_with_collision_barrier',
        'connection_id' => 42,
        'outcome' => 'expected_stale_winner_conflict',
        'exception' => UniqueConstraintViolationException::class,
        'driver_code' => 1062,
        'index_name' => HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX,
        $unexpectedField => ['nested' => $stringable],
    ];

    try {
        HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
        $this->fail('The malformed stale-winner response was accepted.');
    } catch (RuntimeException $exception) {
        expect($stringable->wasInvoked)->toBeFalse()
            ->and($exception->getMessage())
            ->toContain('unexpected_field_count=1')
            ->not->toContain($unexpectedField)
            ->not->toContain($secretMarker)
            ->not->toContain(HarnessDiagnosticStringableFixture::class)
            ->not->toContain('nested');
    }
});

it('reports known stale-winner mismatch values without exposing raw response data', function () {
    $response = [
        'worker_action' => 'create',
        'connection_id' => 42,
        'outcome' => 'failed_closed',
        'exception' => LogicException::class,
        'driver_code' => 1213,
        'index_name' => 'cc_unrelated_unique_index',
    ];

    try {
        HarnessSafetyPolicy::assertExpectedStaleWinnerResponse($response);
        $this->fail('The contradictory stale-winner response was accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())
            ->toContain('actual_worker_action=create')
            ->toContain('actual_outcome=failed_closed')
            ->toContain('actual_exception='.LogicException::class)
            ->toContain('actual_driver_code=[unexpected]')
            ->toContain('actual_index_name=cc_unrelated_unique_index')
            ->toContain('unexpected_field_count=0');
    }
});

it('enforces exact lifecycle flags for baseline and occurrence child processes', function () {
    HarnessSafetyPolicy::assertLifecycleFlags([
        'occurrences' => false,
        'after_commit' => false,
        'scheduled_recovery' => false,
        'historical' => false,
    ], false);
    HarnessSafetyPolicy::assertLifecycleFlags([
        'occurrences' => true,
        'after_commit' => false,
        'scheduled_recovery' => false,
        'historical' => false,
    ], true);

    expect(fn () => HarnessSafetyPolicy::assertLifecycleFlags([
        'occurrences' => true,
        'after_commit' => true,
        'scheduled_recovery' => false,
        'historical' => false,
    ], true))->toThrow(RuntimeException::class);
});

it('accepts only the exact baseline and active-only notification index contracts', function () {
    HarnessSafetyPolicy::assertNotificationIndexes(notificationIndexRows(), true);
    HarnessSafetyPolicy::assertNotificationIndexes(notificationIndexRows(false), false);

    expect(true)->toBeTrue();
});

it('keeps harness index constants aligned with the committed migrations', function () {
    $projectRoot = dirname(__DIR__, 2);
    $legacyMigration = file_get_contents($projectRoot.'/database/migrations/2026_08_23_131214_add_event_key_to_fleet_notifications_table.php');
    $activeMigration = file_get_contents($projectRoot.'/database/migrations/2026_10_30_061626_add_active_event_key_to_fleet_notifications_table.php');

    expect($legacyMigration)->toContain("'user_id',\n                'event_key'")
        ->and(HarnessSafetyPolicy::LEGACY_EVENT_UNIQUE_INDEX)->toBe('fleet_notifications_user_id_event_key_unique')
        ->and($activeMigration)->toContain("'fleet_notifications_user_active_event_unique'")
        ->and($activeMigration)->toContain("'active_event_key'");
});

it('rejects missing renamed reordered and cross-schema notification indexes', function (array $rows, bool $legacyExpected) {
    expect(fn () => HarnessSafetyPolicy::assertNotificationIndexes($rows, $legacyExpected))
        ->toThrow(RuntimeException::class);
})->with(function (): array {
    $missingActive = collect(notificationIndexRows(false))
        ->reject(fn (object $row): bool => $row->index_name === HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX)
        ->values()->all();
    $renamed = notificationIndexRows(false);
    foreach ($renamed as $row) {
        if ($row->index_name === HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX) {
            $row->index_name = 'renamed_active_unique';
        }
    }
    $reordered = notificationIndexRows(false);
    foreach ($reordered as $row) {
        if ($row->index_name === HarnessSafetyPolicy::ACTIVE_EVENT_UNIQUE_INDEX) {
            $row->sequence_number = $row->sequence_number === 1 ? 2 : 1;
        }
    }
    $crossSchema = notificationIndexRows(false);
    $crossSchema[0]->schema_name = 'normal_development';

    return [
        'missing active unique' => [$missingActive, false],
        'renamed active unique' => [$renamed, false],
        'reordered active unique' => [$reordered, false],
        'cross schema metadata' => [$crossSchema, false],
        'active-only passed as baseline' => [notificationIndexRows(false), true],
    ];
});

it('preserves all occurrence execution cleanup and release outcomes', function (
    bool $executionFails,
    bool $cleanupFails,
    bool $releaseFails,
    array $expectedLabels
) {
    $events = [];

    try {
        (new OccurrenceRunCoordinator)->run(
            execute: function () use (&$events, $executionFails): void {
                $events[] = 'execute';

                if ($executionFails) {
                    throw new LogicException('execution failed');
                }
            },
            cleanup: function () use (&$events, $cleanupFails): void {
                $events[] = 'cleanup';

                if ($cleanupFails) {
                    throw new LogicException('cleanup failed');
                }
            },
            release: function () use (&$events, $releaseFails): void {
                $events[] = 'release';

                if ($releaseFails) {
                    throw new LogicException('release failed');
                }
            }
        );

        expect($expectedLabels)->toBe([])
            ->and($events)->toBe(['execute', 'cleanup', 'release']);
    } catch (RuntimeException $exception) {
        expect($expectedLabels)->not->toBe([])
            ->and($events)->toBe(['execute', 'cleanup', 'release']);

        foreach (['Occurrence execution failed', 'Disposable schema cleanup failed', 'Advisory lock release failed'] as $label) {
            expect(str_contains($exception->getMessage(), $label))->toBe(in_array($label, $expectedLabels, true));
        }
    }
})->with([
    'all stages succeed' => [false, false, false, []],
    'execution fails cleanup succeeds' => [true, false, false, ['Occurrence execution failed']],
    'execution and cleanup fail' => [true, true, false, ['Occurrence execution failed', 'Disposable schema cleanup failed']],
    'execution and release fail' => [true, false, true, ['Occurrence execution failed', 'Advisory lock release failed']],
    'all stages fail' => [true, true, true, ['Occurrence execution failed', 'Disposable schema cleanup failed', 'Advisory lock release failed']],
    'successful tests cleanup fails' => [false, true, false, ['Disposable schema cleanup failed']],
    'successful tests release fails' => [false, false, true, ['Advisory lock release failed']],
]);

it('redacts credentials while retaining all coordinated failure labels', function () {
    $secret = 'coordinator-secret-that-must-not-leak';
    $previous = getenv('MYSQL_CONCURRENCY_PASSWORD');
    putenv("MYSQL_CONCURRENCY_PASSWORD={$secret}");
    $_ENV['MYSQL_CONCURRENCY_PASSWORD'] = $secret;
    $_SERVER['MYSQL_CONCURRENCY_PASSWORD'] = $secret;

    try {
        (new OccurrenceRunCoordinator)->run(
            execute: fn () => throw new LogicException("execution {$secret}"),
            cleanup: fn () => throw new LogicException("cleanup {$secret}"),
            release: fn () => throw new LogicException("release {$secret}")
        );
        $this->fail('The coordinated failures were not propagated.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('Occurrence execution failed')
            ->and($exception->getMessage())->toContain('Disposable schema cleanup failed')
            ->and($exception->getMessage())->toContain('Advisory lock release failed')
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
    $completionSpecifications = file_get_contents($projectRoot.'/tests/MySqlConcurrency/PreventiveMaintenanceCompletionConcurrencyTest.php');
    $worker = file_get_contents($projectRoot.'/tests/MySqlConcurrency/bin/worker.php');
    $businessWorker = file_get_contents($projectRoot.'/tests/MySqlConcurrency/Support/BusinessConcurrencyWorker.php');
    $runner = file_get_contents($projectRoot.'/tests/MySqlConcurrency/bin/run.php');
    $auditFailureConstraint = file_get_contents($projectRoot.'/tests/MySqlConcurrency/Support/AuditFailureConstraint.php');
    $occurrenceConfiguration = file_get_contents($projectRoot.'/phpunit.mysql-occurrence.xml');
    $occurrenceSpecifications = file_get_contents($projectRoot.'/tests/MySqlConcurrency/NotificationOccurrenceConcurrencyTest.php');
    $occurrenceWorker = file_get_contents($projectRoot.'/tests/MySqlConcurrency/bin/occurrence-worker.php');
    $occurrenceWorkerService = file_get_contents($projectRoot.'/tests/MySqlConcurrency/Support/OccurrenceConcurrencyWorker.php');
    $authorityInstrumentation = file_get_contents($projectRoot.'/tests/MySqlConcurrency/Support/BarrierMaintenanceNotificationAuthorityEvaluator.php');

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
        ->and(substr_count($completionSpecifications, "it('"))->toBe(3)
        ->and($businessWorker)->toContain('"/maintenance/{$maintenanceId}/transition"')
        ->and($completionSpecifications)->toContain("MaintenanceController::class.'@transition'")
        ->and($completionSpecifications)->not->toContain('->todo(')
        ->and($worker)->toContain('IsolatedMySqlEnvironment::assertExternalLockOwner();')
        ->and($worker)->toContain('IsolatedMySqlEnvironment::assertSafe();')
        ->and(strpos($businessWorker, "\$this->wait(\$payload, 'start');", strpos($businessWorker, 'private function oppositeVehicleReassignment')))
        ->toBeLessThan(strpos($businessWorker, 'app(MySqlDeadlockRetryService::class)->run', strpos($businessWorker, 'private function oppositeVehicleReassignment')))
        ->and($businessWorker)->toContain('}, attempts: 1);')
        ->and($runner)->toContain("'--business-only'")
        ->and($runner)->toContain("\$mode === '--business-only'")
        ->and($runner)->toContain("'--prepare-and-occurrence'")
        ->and($runner)->toContain('OccurrenceRunCoordinator')
        ->and($occurrenceConfiguration)->toContain('value="true" force="true"')
        ->and($occurrenceConfiguration)->toContain('MYSQL_CONCURRENCY_OCCURRENCE_MODE')
        ->and(substr_count($occurrenceSpecifications, "it('"))->toBe(15)
        ->and($occurrenceSpecifications)->not->toContain('->todo(')
        ->and($occurrenceSpecifications)->toContain("->group('known-production-gap')")
        ->and($occurrenceSpecifications)->toContain("->and(\$creator['outcome'])->toBe('source_vehicle_retry_succeeded');")
        ->and($occurrenceSpecifications)->not->toContain("->and(\$creator['outcome'])->toBeIn([")
        ->and($occurrenceSpecifications)->toContain("\$creator['notification_insert_authority_histories']")
        ->and($occurrenceWorker)->toContain('IsolatedMySqlEnvironment::assertExternalLockOwner();')
        ->and($occurrenceWorker)->toContain('HarnessEnvironment::occurrenceWorkerEnvironment()')
        ->and($occurrenceWorkerService)->toContain('BarrierMaintenanceNotificationAuthorityEvaluator')
        ->and($authorityInstrumentation)->toContain('parent::evaluateEventKeyForUpdate')
        ->and($authorityInstrumentation)->toContain('DatabaseBarrier::arriveAndWait')
        ->and($occurrenceWorkerService)->not->toContain('app()->bind')
        ->and($occurrenceWorkerService)->not->toContain('app()->instance')
        ->and($businessSpecifications)->not->toContain('CREATE TRIGGER')
        ->and($businessSpecifications)->toContain('new AuditFailureConstraint')
        ->and($auditFailureConstraint)->toContain("->where('CONSTRAINT_SCHEMA', HarnessSafetyPolicy::DATABASE)")
        ->and($auditFailureConstraint)->toContain("->where('TABLE_NAME', 'audit_logs')")
        ->and($auditFailureConstraint)->toContain('DROP CHECK');
});
