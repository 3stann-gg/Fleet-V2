<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\Process\Process;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\InnoDbDeadlockDiagnostic;
use Tests\MySqlConcurrency\Support\RouteProvenanceConcurrencyWorker;
use Tests\MySqlConcurrency\Support\RouteProvenanceConnection;
use Tests\MySqlConcurrency\Support\RouteProvenanceRunCoordinator;

/** Sanitized representation of the actual stopped Docker inspection. */
function rpsStorage(): array
{
    return ['HostConfig' => ['Tmpfs' => ['/var/lib/mysql' => ''],
        'Binds' => ['D:\\synthetic\\init.sh:/docker-entrypoint-initdb.d/01-concurrency-guard.sh:ro'],
        'Mounts' => null, 'VolumesFrom' => null],
        'Mounts' => [['Type' => 'bind', 'Destination' => '/docker-entrypoint-initdb.d/01-concurrency-guard.sh', 'RW' => false]],
        'Config' => ['Cmd' => ['--default-storage-engine=InnoDB'], 'Entrypoint' => ['docker-entrypoint.sh'],
            'Volumes' => ['/var/lib/mysql' => []]]];
}

it('accepts actual HostConfig tmpfs even when Mounts contains only the readonly initializer', function (): void {
    $container = rpsStorage();
    HarnessSafetyPolicy::assertDisposableMySqlStorage($container);
    expect($container['HostConfig']['Tmpfs']['/var/lib/mysql'])->toBe('')
        ->and(array_column($container['Mounts'], 'Type'))->toBe(['bind']);
});

it('accepts explicit tmpfs reporting and writable options without requiring a volume mount', function (): void {
    $container = rpsStorage();
    $container['HostConfig']['Tmpfs']['/var/lib/mysql'] = 'rw,nosuid,nodev,size=1073741824';
    $container['Mounts'][] = ['Type' => 'tmpfs', 'Destination' => '/var/lib/mysql', 'RW' => true];
    HarnessSafetyPolicy::assertDisposableMySqlStorage($container);
    expect($container['Mounts'])->toHaveCount(2);
});

it('fails closed on missing malformed persistent overlapping or redirected MySQL storage', function (string $path, mixed $value): void {
    $container = rpsStorage();
    data_set($container, $path, $value);
    expect(fn () => HarnessSafetyPolicy::assertDisposableMySqlStorage($container))->toThrow(RuntimeException::class);
})->with([
    ['HostConfig', null], ['HostConfig.Tmpfs', null], ['HostConfig.Tmpfs', []],
    ['HostConfig.Tmpfs', ['/var/lib/mysql' => null]], ['HostConfig.Tmpfs', ['/var/lib/mysql' => false]],
    ['HostConfig.Tmpfs', ['/var/lib/mysql' => 'ro,size=1024']],
    ['HostConfig.Tmpfs', ['/var/lib/mysql' => '', '/var' => '']],
    ['HostConfig.Tmpfs', ['/var/lib/mysql' => '', '/var/lib/mysql/data' => '']],
    ['HostConfig.Tmpfs', ['//var/lib/mysql' => '']], ['Mounts', null], ['Mounts', 'invalid'],
    ['Mounts', [['Type' => 'volume', 'Destination' => '/var/lib/mysql', 'RW' => true]]],
    ['Mounts', [['Type' => 'bind', 'Destination' => '/var/lib/mysql', 'RW' => true]]],
    ['Mounts', [['Type' => 'bind', 'Destination' => '/var', 'RW' => true]]],
    ['Mounts', [['Type' => 'bind', 'Destination' => '/', 'RW' => true]]],
    ['Mounts', [['Type' => 'volume', 'Destination' => '/var/lib/mysql/data', 'RW' => true]]],
    ['Mounts', [['Type' => 'volume', 'Destination' => '/persistent', 'RW' => true]]],
    ['Mounts', [['Type' => 'bind', 'Destination' => '/etc/my.cnf', 'RW' => false]]],
    ['Mounts', [['Type' => 'bind', 'Destination' => '/var/lib/../lib/mysql', 'RW' => true]]],
    ['Mounts', [['Type' => 'bind', 'Destination' => '/docker-entrypoint-initdb.d/01-concurrency-guard.sh', 'RW' => true]]],
    ['Mounts', [['Type' => 'unknown', 'Destination' => '/var/lib/mysql', 'RW' => true]]],
    ['Mounts', [['Type' => 'tmpfs', 'Destination' => '/var/lib/mysql', 'RW' => false]]],
    ['Mounts', [['Type' => 'tmpfs', 'RW' => true]]],
    ['Mounts', [['Type' => 'tmpfs', 'Destination' => '/var/lib/mysql', 'RW' => true],
        ['Type' => 'tmpfs', 'Destination' => '/var/lib/mysql', 'RW' => true]]],
    ['Mounts', ['malformed']], ['HostConfig.VolumesFrom', ['another-container']],
    ['HostConfig.Mounts', [['Type' => 'bind', 'Target' => '/var/lib/mysql', 'Source' => '/persistent']]],
    ['HostConfig.Binds', ['/persistent:/var/lib/mysql:rw']], ['HostConfig.Binds', 'invalid'],
    ['HostConfig.Binds', ['D:\\synthetic\\init.sh:/docker-entrypoint-initdb.d/01-concurrency-guard.sh:rw']],
    ['Config.Cmd', ['--datadir=/persistent']], ['Config.Cmd', ['--defaults-file=/persistent/my.cnf']],
    ['Config.Entrypoint', ['custom-mysqld']],
]);

/** Restore every environment source even when an assertion throws. */
function rpsEnvironment(array $values, Closure $operation): mixed
{
    $before = [];
    foreach ($values as $key => $value) {
        $before[$key] = [getenv($key), array_key_exists($key, $_ENV), $_ENV[$key] ?? null,
            array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }
    try {
        return $operation();
    } finally {
        foreach ($before as $key => [$process, $inEnv, $env, $inServer, $server]) {
            $process === false ? putenv($key) : putenv($key.'='.$process);
            if ($inEnv) {
                $_ENV[$key] = $env;
            } else {
                unset($_ENV[$key]);
            }
            if ($inServer) {
                $_SERVER[$key] = $server;
            } else {
                unset($_SERVER[$key]);
            }
        }
    }
}

function rpsPayload(array $overrides = []): array
{
    return array_replace(['route_id' => 11, 'user_id' => 22, 'barrier' => 'rp-synthetic-test',
        'participant' => 'a', 'coordination' => 'simultaneous', 'request' => [], 'audit_failure' => false], $overrides);
}

it('clamps inherited route gates and dedicated modes off in ordinary harness environments', function (): void {
    $names = ['FLEET_ROUTE_PLANNED_DEPARTURE_ENABLED', 'FLEET_ROUTE_PLANNED_PROOF_ISSUANCE_ENABLED',
        'FLEET_ROUTE_PLANNED_APPLY_ENABLED', 'FLEET_ROUTE_PLANNED_SAVE_ENABLED', 'FLEET_ROUTE_PROVENANCE_RECORDING_ENABLED'];
    rpsEnvironment(array_fill_keys($names, 'true') + ['MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE' => '1'], function () use ($names): void {
        $environment = HarnessEnvironment::processEnvironment();
        foreach ($names as $name) {
            expect($environment[$name])->toBe('false');
        }
        expect($environment['MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE'])->toBe('0')
            ->and($environment['MYSQL_CONCURRENCY_OCCURRENCE_MODE'])->toBe('0')->and($environment['DB_URL'])->toBe('');
    });
});

it('requires a separate valid encryption key for the dedicated mode', function (?string $key): void {
    rpsEnvironment(['MYSQL_CONCURRENCY_ROUTE_PROOF_KEY' => null], function () use ($key): void {
        expect(fn () => HarnessEnvironment::routeProvenanceProcessEnvironment($key))->toThrow(RuntimeException::class);
    });
})->with([null, '', 'not-a-key', 'base64:invalid!', 'base64:'.base64_encode(str_repeat('x', 31)), 'base64:'.base64_encode(str_repeat('x', 33))]);

it('preserves parent handoff and ephemeral key while leaving feature gates off', function (): void {
    $key = 'base64:'.base64_encode(str_repeat('x', 32));
    rpsEnvironment(['MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE' => '1', 'MYSQL_CONCURRENCY_OCCURRENCE_MODE' => '0',
        'MYSQL_CONCURRENCY_ROUTE_PROOF_KEY' => $key, 'MYSQL_CONCURRENCY_PARENT_LOCK_NAME' => 'synthetic-lock',
        'MYSQL_CONCURRENCY_PARENT_LOCK_OWNER' => '123'], function () use ($key): void {
            $environment = HarnessEnvironment::routeProvenanceWorkerEnvironment();
            expect($environment['APP_KEY'])->toBe($key)->and($environment['MYSQL_CONCURRENCY_ROUTE_PROOF_KEY'])->toBe($key)
                ->and($environment['MYSQL_CONCURRENCY_PARENT_LOCK_NAME'])->toBe('synthetic-lock')
                ->and($environment['MYSQL_CONCURRENCY_PARENT_LOCK_OWNER'])->toBe('123')
                ->and($environment['MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE'])->toBe('1')
                ->and($environment['FLEET_ROUTE_PLANNED_SAVE_ENABLED'])->toBe('false')
                ->and($environment['FLEET_ROUTE_PROVENANCE_RECORDING_ENABLED'])->toBe('false')
                ->and($environment['CACHE_STORE'])->toBe('array')->and($environment['SESSION_DRIVER'])->toBe('array')
                ->and($environment['LOG_CHANNEL'])->toBe('null')
                ->and(HarnessEnvironment::sanitizeDiagnostic('key='.$key))->toBe('key=[REDACTED]');
        });
});

it('refuses workers missing exclusive mode or parent handoff', function (array $overrides): void {
    rpsEnvironment(array_replace(['MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE' => '1', 'MYSQL_CONCURRENCY_OCCURRENCE_MODE' => '0',
        'MYSQL_CONCURRENCY_ROUTE_PROOF_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
        'MYSQL_CONCURRENCY_PARENT_LOCK_NAME' => 'synthetic', 'MYSQL_CONCURRENCY_PARENT_LOCK_OWNER' => '123'], $overrides), function (): void {
            expect(fn () => HarnessEnvironment::routeProvenanceWorkerEnvironment())->toThrow(RuntimeException::class);
        });
})->with([
    [['MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE' => '0']], [['MYSQL_CONCURRENCY_OCCURRENCE_MODE' => '1']],
    [['MYSQL_CONCURRENCY_PARENT_LOCK_NAME' => null]], [['MYSQL_CONCURRENCY_PARENT_LOCK_OWNER' => null]],
]);

it('selects only its dedicated suite with schema valid configuration', function (): void {
    HarnessSafetyPolicy::assertRunnerMode('--prepare-and-route-provenance');
    expect(HarnessEnvironment::routeProvenanceTestCommand('C:/synthetic'))->toContain(
        '--configuration=C:/synthetic/phpunit.mysql-route-provenance.xml', '--fail-on-risky',
        'C:/synthetic/tests/MySqlConcurrency/RouteProvenanceConcurrencyTest.php');
    $document = new DOMDocument;
    expect($document->load(__DIR__.'/../../phpunit.mysql-route-provenance.xml'))->toBeTrue()
        ->and($document->schemaValidate(__DIR__.'/../../vendor/phpunit/phpunit/phpunit.xsd'))->toBeTrue();
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('string(//env[@name="MYSQL_CONCURRENCY_ROUTE_PROVENANCE_MODE"]/@value)'))->toBe('1')
        ->and($xpath->evaluate('string(//env[@name="DB_CONNECTION"]/@value)'))->toBe('mysql_concurrency')
        ->and($xpath->evaluate('string(//env[@name="DB_URL"]/@value)'))->toBe('')
        ->and($xpath->evaluate('string(//env[@name="MYSQL_CONCURRENCY_OCCURRENCE_MODE"]/@value)'))->toBe('0');
    $ordinary = new DOMDocument;
    $ordinary->load(__DIR__.'/../../phpunit.mysql-concurrency.xml');
    expect((new DOMXPath($ordinary))->evaluate('string(//exclude)'))->toBe('tests/MySqlConcurrency/RouteProvenanceConcurrencyTest.php');
});

it('rejects every nonzero suite exit', function (int $exit): void {
    expect(fn () => HarnessSafetyPolicy::assertSuccessfulRouteProvenanceExitCode($exit))->toThrow(RuntimeException::class, (string) $exit);
})->with([-1, 1, 2, 137]);

it('preserves execution verification and release failures without skipping later phases', function (array $failedPhases): void {
    $visited = [];
    $operation = function (string $phase) use (&$visited, $failedPhases): Closure {
        return function () use ($phase, &$visited, $failedPhases): void {
            $visited[] = $phase;
            if (in_array($phase, $failedPhases, true)) {
                throw new RuntimeException('synthetic-'.$phase);
            }
        };
    };
    try {
        (new RouteProvenanceRunCoordinator)->run($operation('execution'), $operation('verification'), $operation('release'));
        expect($failedPhases)->toBe([]);
    } catch (RuntimeException $exception) {
        foreach ($failedPhases as $phase) {
            expect($exception->getMessage())->toContain('synthetic-'.$phase);
        }
        expect($exception->getPrevious()?->getMessage())->toBe('synthetic-'.$failedPhases[0]);
    }
    expect($visited)->toBe(['execution', 'verification', 'release']);
})->with([[[]], [['execution']], [['verification']], [['release']], [['execution', 'verification', 'release']]]);

it('accepts well formed worker roles before bootstrap', function (string $action, array $overrides): void {
    RouteProvenanceConcurrencyWorker::assertPayload($action, rpsPayload($overrides));
    expect(true)->toBeTrue();
})->with([
    ['planned', []], ['manual', []], ['archive', ['participant' => 'holder', 'coordination' => 'holder']],
    ['restore', ['participant' => 'contender', 'coordination' => 'contender']],
    ['probe', ['participant' => 'probe', 'coordination' => 'probe']], ['planned', ['audit_failure' => true]],
]);

it('rejects malformed identities or synchronization roles before database access', function (string $action, array $overrides): void {
    expect(fn () => RouteProvenanceConcurrencyWorker::assertPayload($action, rpsPayload($overrides)))->toThrow(RuntimeException::class);
})->with([
    ['delete', []], ['planned', ['route_id' => 0]], ['planned', ['route_id' => '11']], ['planned', ['user_id' => -1]],
    ['planned', ['barrier' => '../outside']], ['planned', ['barrier' => 'rp-'.str_repeat('a', 66)]],
    ['planned', ['participant' => 'probe']], ['planned', ['coordination' => 'probe']], ['probe', []],
    ['planned', ['request' => null]], ['manual', ['audit_failure' => true]], ['planned', ['audit_failure' => 'false']],
    ['archive', ['participant' => 'a', 'coordination' => 'holder']],
]);

it('synchronizes exactly the production route lock query', function (string $query, array $bindings, bool $matches): void {
    expect(RouteProvenanceConcurrencyWorker::matchesRouteLock($query, $bindings, 11))->toBe($matches);
})->with([
    ['select * from `route_plans` where `route_plans`.`id` = ? limit 1 for update', [11], true],
    ['select * from `route_plans` where `id` = ? limit 1 for update', ['11'], true],
    ['select * from `route_plans` where `route_plans`.`id` = ? limit 1 for update', [12], false],
    ['select * from `route_plans` where `id` = ? limit 1', [11], false],
    ['select * from `reservations` where `id` = ? limit 1 for update', [11], false],
    ['select * from `route_plans` where `reservation_id` = ? limit 1 for update', [11], false],
    ['select * from `route_plans` where `id` = ? limit 1 for update', [11, 12], false],
]);

it('accepts only explicit success stale revision or injected audit failure', function (int $status, array $body, bool $injected, string $expected): void {
    expect(RouteProvenanceConcurrencyWorker::classify($status, $body, [], $injected))->toBe($expected);
})->with([
    [200, ['success' => true, 'routePlan' => []], false, 'accepted'],
    [409, ['code' => 'ROUTE_ESTIMATE_REVISION_STALE'], false, 'revision_conflict'],
    [422, ['message' => RouteProvenanceConcurrencyWorker::AUDIT_FAILURE], true, 'audit_rolled_back'],
    [422, ['message' => RouteProvenanceConcurrencyWorker::AUDIT_FAILURE], false, 'unexpected_response'],
    [422, ['message' => 'Unrelated failure'], true, 'unexpected_response'], [403, [], false, 'unexpected_response'],
    [419, [], false, 'unexpected_response'], [500, [], false, 'unexpected_response'],
    [200, ['success' => false, 'routePlan' => []], false, 'unexpected_response'],
    [409, ['code' => 'OTHER_CONFLICT'], false, 'unexpected_response'],
]);

it('never treats deadlocks timeouts or other constraints as expected stale responses', function (string $state, int $code, string $constraint): void {
    $failure = [['state' => $state, 'code' => $code, 'constraint' => $constraint]];
    expect(RouteProvenanceConcurrencyWorker::classify(409, ['code' => 'ROUTE_ESTIMATE_REVISION_STALE'], $failure, false))->toBe('unexpected_response')
        ->and(RouteProvenanceConcurrencyWorker::classify(422, ['message' => 'Route changes could not be saved.'], $failure, false))->toBe('unexpected_response');
})->with([['40001', 1213, 'other'], ['HY000', 1205, 'other'], ['23000', 1062, 'other'], ['HY000', 3819, 'other']]);

it('recognizes only the exact calculation constraint and sanitized response together', function (): void {
    $failure = [['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']];
    expect(RouteProvenanceConcurrencyWorker::classify(422, ['message' => 'Route changes could not be saved.'], $failure, false))->toBe('uuid_conflict')
        ->and(RouteProvenanceConcurrencyWorker::classify(500, [], $failure, false))->toBe('unexpected_response')
        ->and(RouteProvenanceConcurrencyWorker::classify(422, ['message' => 'Other failure'], $failure, false))->toBe('unexpected_response')
        ->and(RouteProvenanceConcurrencyWorker::classify(422, ['message' => 'Route changes could not be saved.'], [...$failure, ...$failure], false))->toBe('unexpected_response');
});

it('captures exact sanitized MySQL telemetry without opening a connection', function (string $key, string $expected): void {
    $connection = new RouteProvenanceConnection(fn () => throw new RuntimeException('A connection must not be opened.'), 'synthetic', '', ['name' => 'synthetic']);
    $exception = new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', 1062);
    $exception->errorInfo = ['23000', 1062, "Duplicate entry 'synthetic-value' for key '{$key}'"];
    $method = new ReflectionMethod($connection, 'runQueryCallback');
    expect(fn () => $method->invoke($connection, 'insert into synthetic values (?)', [1], fn () => throw $exception))->toThrow(QueryException::class)
        ->and($connection->queryFailures)->toBe([['state' => '23000', 'code' => 1062, 'constraint' => $expected]])
        ->and($connection->queryExceptionClasses)->toBe([['exception_class' => UniqueConstraintViolationException::class, 'previous_exception_class' => PDOException::class]]);
})->with([
    ['route_estimate_provenances.rep_calculation_uq', 'rep_calculation_uq'], ['rep_calculation_uq', 'rep_calculation_uq'],
    ['route_estimate_provenances.rep_calculation_uq_other', 'other'], ['route_estimate_provenances.other_unique', 'other'],
]);

/** Exercise the same result guard as real workers, without booting Laravel or connecting PDO. */
function rpsResult(array $overrides = []): array
{
    return RouteProvenanceConcurrencyWorker::assertResult(...array_replace([
        'status' => 422,
        'content' => json_encode(['success' => false, 'message' => 'Route changes could not be saved.'], JSON_THROW_ON_ERROR),
        'payload' => rpsPayload(['request' => ['expected_estimate_revision' => 0]]),
        'sqlFailures' => [['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']],
        'sqlExceptionClasses' => [['exception_class' => UniqueConstraintViolationException::class, 'previous_exception_class' => PDOException::class]],
        'auditFailure' => false, 'attempted' => true, 'locked' => true,
        'transactionLevel' => 0, 'pdoInTransaction' => false,
    ], $overrides));
}

function rpsDiagnostic(array $overrides): array
{
    try {
        rpsResult($overrides);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toStartWith('Unexpected route worker result, SQL failure, missing lock attempt or open transaction (HTTP ');
        $parts = explode(' Diagnostics: ', $exception->getMessage(), 2);
        expect($parts)->toHaveCount(2);

        return json_decode($parts[1], true, 64, JSON_THROW_ON_ERROR);
    }
    throw new RuntimeException('Expected a fail-closed diagnostic.');
}

it('preserves the exact UUID conflict requirements through the real result guard', function (): void {
    expect(rpsResult()['outcome'])->toBe('uuid_conflict');
});

it('preserves ordinary accepted stale and injected audit outcomes with closed transactions', function (int $status, array $body, bool $injected, string $outcome): void {
    expect(rpsResult(['status' => $status, 'content' => json_encode($body, JSON_THROW_ON_ERROR),
        'sqlFailures' => [], 'sqlExceptionClasses' => [], 'auditFailure' => $injected])['outcome'])->toBe($outcome);
})->with([
    [200, ['success' => true, 'routePlan' => []], false, 'accepted'],
    [409, ['code' => 'ROUTE_ESTIMATE_REVISION_STALE'], false, 'revision_conflict'],
    [422, ['message' => RouteProvenanceConcurrencyWorker::AUDIT_FAILURE], true, 'audit_rolled_back'],
]);

it('keeps unrelated constraints deadlocks timeouts and multiple SQL failures fatal with safe evidence', function (array $failures): void {
    $diagnostic = rpsDiagnostic(['sqlFailures' => $failures]);
    expect($diagnostic['failure_conditions']['unrecognized_response_or_sql_failure'])->toBeTrue()
        ->and($diagnostic['sql_failure_count'])->toBe(count($failures))->and($diagnostic['sql_failures'])->toBe($failures)
        ->and($diagnostic['response']['message_category'])->toBe('persistence_error');
})->with([
    [[['state' => '23000', 'code' => 1062, 'constraint' => 'other']]],
    [[['state' => '40001', 'code' => 1213, 'constraint' => 'other']]],
    [[['state' => 'HY000', 'code' => 1205, 'constraint' => 'other']]],
    [[['state' => 'HY000', 'code' => 3819, 'constraint' => 'other']]],
    [[['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq'],
        ['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']]],
]);

it('does not accept incomplete UUID conflict evidence', function (array $overrides): void {
    expect(rpsDiagnostic($overrides)['failure_conditions']['unrecognized_response_or_sql_failure'])->toBeTrue();
})->with([
    [['sqlFailures' => [], 'sqlExceptionClasses' => []]],
    [['status' => 200]],
    [['content' => '{"message":"Different persistence failure"}']],
    [['sqlFailures' => [['state' => 'HY000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']]]],
    [['sqlFailures' => [['state' => '23000', 'code' => 1213, 'constraint' => 'rep_calculation_uq']]]],
]);

it('records malformed and non-object responses without retaining their contents', function (string $content): void {
    $diagnostic = rpsDiagnostic(['content' => $content, 'sqlFailures' => [], 'sqlExceptionClasses' => []]);
    expect($diagnostic['failure_conditions']['malformed_response'])->toBeTrue()
        ->and($diagnostic['failure_conditions']['unrecognized_response_or_sql_failure'])->toBeTrue()
        ->and(json_encode($diagnostic))->not->toContain('private-token');
})->with(['<html>private-token</html>', '{"message":"private-token"', 'null', '"private-token"', 'true']);

it('records only approved validation field names and application error codes', function (): void {
    $body = ['code' => 'ROUTE_PLANNED_PROOF_INVALID', 'message' => 'Please check the route information.',
        'errors' => ['origin' => ['private-input'], 'stops.1.latitude' => ['private-coordinate'],
            'private-token' => ['private-value'], 'stops.1.private-token' => ['private-value']]];
    $diagnostic = rpsDiagnostic(['content' => json_encode($body), 'sqlFailures' => [], 'sqlExceptionClasses' => []]);
    expect($diagnostic['response'])->toBe(['application_code' => 'ROUTE_PLANNED_PROOF_INVALID',
        'message_category' => 'validation_error', 'validation_fields' => ['origin', 'stops.1.latitude'], 'omitted_validation_fields' => 2])
        ->and(json_encode($diagnostic))->not->toContain('private-');
});

it('reports each lock or transaction failure independently without accepting a valid UUID rejection', function (array $overrides, string $condition): void {
    $diagnostic = rpsDiagnostic($overrides);
    expect($diagnostic['failure_conditions'][$condition])->toBeTrue()
        ->and($diagnostic['failure_conditions']['unrecognized_response_or_sql_failure'])->toBeFalse()
        ->and(count(array_filter($diagnostic['failure_conditions'])))->toBe(1);
})->with([
    [['attempted' => false, 'locked' => false], 'missing_route_lock_attempt'],
    [['transactionLevel' => 1], 'open_laravel_transaction'],
    [['pdoInTransaction' => true], 'open_pdo_transaction'],
]);

it('keeps all simultaneous rejection reasons and correlation facts in the emitted failure', function (): void {
    $diagnostic = rpsDiagnostic(['content' => '{"code":"ROUTE_ESTIMATE_REVISION_REQUIRED"}',
        'sqlFailures' => [], 'sqlExceptionClasses' => [], 'attempted' => false, 'locked' => false,
        'transactionLevel' => 2, 'pdoInTransaction' => true]);
    expect($diagnostic)->toMatchArray(['worker' => 'a', 'barrier' => 'rp-synthetic-test', 'http_status' => 422,
        'expected_estimate_revision' => 0, 'route_lock_attempted' => false, 'route_lock_acquired' => false,
        'laravel_transaction_level' => 2, 'pdo_in_transaction' => true, 'worker_exception_class' => RuntimeException::class])
        ->and($diagnostic['response']['application_code'])->toBe('ROUTE_ESTIMATE_REVISION_REQUIRED')
        ->and($diagnostic['failure_conditions'])->toBe(['malformed_response' => false,
            'unrecognized_response_or_sql_failure' => true, 'missing_route_lock_attempt' => true,
            'open_laravel_transaction' => true, 'open_pdo_transaction' => true]);
});

it('retains safe SQL exception classes separately from unchanged conflict telemetry', function (): void {
    $diagnostic = rpsDiagnostic(['attempted' => false]);
    expect($diagnostic['sql_failures'])->toBe([['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']])
        ->and($diagnostic['sql_exceptions'])->toBe([['exception_class' => UniqueConstraintViolationException::class, 'previous_exception_class' => PDOException::class]])
        ->and($diagnostic['sql_exception_count'])->toBe(1);
});

it('redacts seeded credentials and proofs before serializing even approved correlation fields', function (): void {
    $password = 'seeded-password-123';
    $rootPassword = 'seeded-root-password-456';
    $proof = 'base64:seeded-proof"/\\token';
    rpsEnvironment(['MYSQL_CONCURRENCY_PASSWORD' => $password, 'MYSQL_CONCURRENCY_ROOT_PASSWORD' => $rootPassword,
        'MYSQL_CONCURRENCY_ROUTE_PROOF_KEY' => $proof], function () use ($password, $rootPassword, $proof): void {
            $diagnostic = rpsDiagnostic(['payload' => rpsPayload(['barrier' => 'rp-'.$password,
                'request' => ['expected_estimate_revision' => $proof, 'optimization_proof' => $proof, 'password' => $password]]),
                'content' => json_encode(['code' => $password, 'message' => $rootPassword,
                    'proof' => $proof, 'errors' => [$password => [$rootPassword], 'origin' => [$proof]]]),
                'sqlFailures' => [['state' => $password, 'code' => $rootPassword, 'constraint' => $proof, 'sql' => $password, 'bindings' => [$proof]]],
                'sqlExceptionClasses' => [['exception_class' => $password, 'previous_exception_class' => $proof, 'message' => $rootPassword]]]);
            $json = json_encode($diagnostic, JSON_THROW_ON_ERROR);
            expect($diagnostic['barrier'])->toBe('rp-[REDACTED]')
                ->and($diagnostic['expected_estimate_revision'])->toBeNull()
                ->and($diagnostic['response']['application_code'])->toBeNull()
                ->and($diagnostic['sql_failures'])->toBe([['state' => null, 'code' => null, 'constraint' => 'other']])
                ->and($diagnostic['sql_exceptions'])->toBe([['exception_class' => null, 'previous_exception_class' => null]])
                ->and($json)->not->toContain($password, $rootPassword, $proof, 'bindings', 'optimization_proof', 'previous_exception_message');
        });
});

it('bounds diagnostic lists and drops hostile unknown keys and raw exception details', function (): void {
    $errors = [];
    foreach (range(0, 99) as $index) {
        $errors['stops.'.$index.'.location'] = ['private-value'];
    }
    $diagnostic = rpsDiagnostic(['payload' => rpsPayload(['participant' => "private-token\n", 'barrier' => str_repeat('private-token', 1000)]),
        'content' => json_encode(['code' => 'private-token', 'message' => str_repeat('private-token', 1000), 'errors' => $errors]),
        'sqlFailures' => array_fill(0, 30, ['state' => '23000', 'code' => 1062, 'constraint' => 'other']),
        'sqlExceptionClasses' => array_fill(0, 30, ['exception_class' => 'private-token', 'message' => 'private-token'])]);
    expect($diagnostic['worker'])->toBeNull()->and($diagnostic['barrier'])->toBeNull()
        ->and($diagnostic['response']['validation_fields'])->toHaveCount(64)
        ->and($diagnostic['response']['omitted_validation_fields'])->toBe(36)
        ->and($diagnostic['sql_failure_count'])->toBe(30)->and($diagnostic['sql_failures'])->toHaveCount(16)
        ->and($diagnostic['sql_exception_count'])->toBe(30)->and($diagnostic['sql_exceptions'])->toHaveCount(16)
        ->and(json_encode($diagnostic))->not->toContain('private-');
});

it('selects only the fixed duplicate group while preserving the exact full-suite command', function (): void {
    $full = HarnessEnvironment::routeProvenanceTestCommand('C:/synthetic');
    expect($full)->toBe([PHP_BINARY, 'C:/synthetic/vendor/bin/pest', '--configuration=C:/synthetic/phpunit.mysql-route-provenance.xml',
        '--display-all-issues', '--fail-on-risky', 'C:/synthetic/tests/MySqlConcurrency/RouteProvenanceConcurrencyTest.php'])
        ->and(HarnessEnvironment::routeProvenanceTestCommand('C:/synthetic', '--prepare-and-route-provenance'))->toBe($full)
        ->and(HarnessEnvironment::routeProvenanceTestCommand('C:/synthetic', HarnessSafetyPolicy::ROUTE_DUPLICATE_MODE))
        ->toBe([...$full, '--group='.HarnessSafetyPolicy::ROUTE_DUPLICATE_GROUP]);
    $source = file_get_contents(__DIR__.'/../MySqlConcurrency/RouteProvenanceConcurrencyTest.php');
    $group = '->group(HarnessSafetyPolicy::ROUTE_DUPLICATE_GROUP)';
    $parts = explode("it('enforces duplicate calculation UUID uniqueness across genuinely parallel route Saves'", $source);
    expect($parts)->toHaveCount(2)->and($parts[0])->not->toContain($group)
        ->and(substr_count($source, $group))->toBe(1)->and(trim($parts[1]))->toEndWith($group.';');
});

it('accepts exactly one allowlisted guarded CLI mode', function (string $mode): void {
    expect(HarnessSafetyPolicy::runnerMode(['run.php', $mode]))->toBe($mode);
})->with(HarnessSafetyPolicy::RUNNER_MODES);

it('rejects missing unknown ambiguous conflicting and extra CLI selections', function (array $arguments): void {
    expect(fn () => HarnessSafetyPolicy::runnerMode($arguments))->toThrow(RuntimeException::class);
})->with([
    [['run.php']], [['run.php', '--unknown']], [['run.php', '--prepare-and-route-provenance', '--filter=uuid']],
    [['run.php', HarnessSafetyPolicy::ROUTE_DUPLICATE_MODE, '--prepare-and-route-provenance']],
    [['run.php', HarnessSafetyPolicy::ROUTE_DUPLICATE_MODE, '--group=anything']],
    [['run.php', HarnessSafetyPolicy::ROUTE_DUPLICATE_MODE, '']], [['run.php', null]],
]);

it('refuses unknown command selection and keeps duplicate mode inside the existing launcher guards', function (): void {
    expect(fn () => HarnessEnvironment::routeProvenanceTestCommand('C:/synthetic', 'other'))->toThrow(RuntimeException::class);
    $source = file_get_contents(__DIR__.'/../MySqlConcurrency/bin/run.php');
    expect(strpos($source, 'HarnessSafetyPolicy::runnerMode($argv)'))->toBeLessThan(strpos($source, 'HarnessEnvironment::load'))
        ->and($source)->toContain('IsolatedMySqlEnvironment::assertSafe();', '$ownerId = $lock->acquire();',
            '(new DisposableSchemaPreparer)->prepare($connection, $lock);', '$transition->assertBaseline();',
            'HarnessEnvironment::routeProvenanceTestCommand($projectRoot, $mode)', '$lock->release();');
});

/** Construct the exact successful or rejected worker protocol without a live application. */
function rpsWorkerProtocol(string $worker, bool $failed = false): array
{
    $payload = rpsPayload(['participant' => $worker, 'request' => ['expected_estimate_revision' => 0]]);
    if ($failed) {
        $diagnostic = rpsDiagnostic(['payload' => $payload, 'sqlFailures' => [['state' => '40001', 'code' => 1213, 'constraint' => 'other']]]);

        return ['exit_code' => 1, 'output' => '', 'error_output' => 'Route provenance worker refused or failed: Unexpected route worker result, SQL failure, missing lock attempt or open transaction (HTTP 422). Diagnostics: '.json_encode($diagnostic)];
    }
    $result = rpsResult(['payload' => $payload]);

    return ['exit_code' => 0, 'error_output' => '', 'output' => json_encode(['outcome' => $result['outcome'], 'http_status' => 422,
        'pid' => $result['diagnostic']['worker_pid'], 'connection_id' => $worker === 'a' ? 11 : 12,
        'control_connection_id' => 20, 'diagnostic' => $result['diagnostic']])];
}

it('records every independent worker result before propagating either worker failure', function (bool $failA, bool $failB): void {
    $events = [];
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    $replies = ['a' => rpsWorkerProtocol('a', $failA), 'b' => rpsWorkerProtocol('b', $failB)];
    $processes = [];
    foreach ($replies as $name => $reply) {
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('start')->once();
        $process->shouldReceive('getPid')->once()->andReturn($name === 'a' ? 101 : 102);
        $process->shouldReceive('wait')->once();
        $process->shouldReceive('isRunning')->once()->andReturnFalse();
        $process->shouldReceive('getExitCode')->andReturn($reply['exit_code']);
        $process->shouldReceive('getOutput')->andReturn($reply['output']);
        $process->shouldReceive('getErrorOutput')->andReturn($reply['error_output']);
        $processes[] = $process;
    }
    $runner = new ConcurrentProcessRunner(processFactory: function () use (&$processes): Process {
        return array_shift($processes);
    }, evidenceRecorder: function (string $name, array $reply, ?int $pid) use ($coordinator): void {
        $coordinator->recordWorker($name, $reply, $pid, ['barrier' => 'rp-synthetic-test', 'expected_estimate_revision' => 0]);
    });
    if ($failA || $failB) {
        expect(fn () => $runner->run(['a' => ['synthetic'], 'b' => ['synthetic']]))->toThrow(RuntimeException::class);
    } else {
        expect($runner->run(['a' => ['synthetic'], 'b' => ['synthetic']]))->toHaveCount(2);
    }
    expect(array_column($events, 'worker'))->toBe(['a', 'b'])->and(array_column($events, 'launcher_pid'))->toBe([101, 102])
        ->and(array_column($events, 'exit_code'))->toBe([$failA ? 1 : 0, $failB ? 1 : 0])
        ->and(array_column($events, 'evidence_complete'))->toBe([true, true]);
    foreach ($events as $event) {
        expect($event['diagnostic']['worker'])->toBe($event['worker'])
            ->and($event['outcome'])->toBe($event['exit_code'] === 0 ? 'uuid_conflict' : 'unexpected_response');
    }
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('fails closed with bounded incomplete events for missing malformed or misattributed worker evidence', function (array $overrides, ?int $pid): void {
    $events = [];
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    expect(fn () => $coordinator->recordWorker('a', array_replace(rpsWorkerProtocol('a'), $overrides), $pid,
        ['barrier' => 'rp-synthetic-test', 'expected_estimate_revision' => 0]))->toThrow(RuntimeException::class)
        ->and($events)->toHaveCount(1)->and($events[0]['evidence_complete'])->toBeFalse()
        ->and(json_encode($events))->not->toContain('private-token');
})->with([
    [['output' => ''], 100], [['output' => '{private-token'], 100], [['output' => 'null'], 100],
    [['output' => str_repeat('private-token', 4000)], 100], [['error_output' => 'private-token'], 100],
    [['output' => '{"outcome":"accepted"}'], 100], [[], null],
]);

it('resanitizes successful child JSON and ignores unrestricted response keys before emitting', function (): void {
    $secret = 'seeded-child-secret';
    rpsEnvironment(['MYSQL_CONCURRENCY_PASSWORD' => $secret], function () use ($secret): void {
        $reply = rpsWorkerProtocol('a');
        $data = json_decode($reply['output'], true);
        $data['proof'] = $secret;
        $data['diagnostic']['response']['message'] = $secret;
        $data['diagnostic']['sql_exceptions'][0]['message'] = $secret;
        $reply['output'] = json_encode($data);
        $events = [];
        (new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
            $events[] = $event;
        }))->recordWorker('a', $reply, 100, ['barrier' => 'rp-synthetic-test', 'expected_estimate_revision' => 0]);
        expect($events[0]['evidence_complete'])->toBeTrue()->and(json_encode($events))->not->toContain($secret, '"proof"', '"message"');
    });
});

function rpsProtectedStates(): array
{
    $state = ['route' => ['private-note' => 'private-token'], 'stops' => [['id' => 1]],
        'reservation' => ['id' => 1], 'provenance' => [], 'audits' => []];

    return [1 => $state, 2 => $state];
}

it('captures baseline and post-state even after failed execution without claiming verified success', function (bool $failExecution): void {
    $events = [];
    $inspections = 0;
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    $run = function () use ($coordinator, &$inspections, $failExecution): void {
        $coordinator->observeDuplicate(fn (): bool => true,
            function () use (&$inspections): array {
                $inspections++;

                return rpsProtectedStates();
            }, function (array $before) use ($failExecution): bool {
                expect($before)->toBe(rpsProtectedStates());
                if ($failExecution) {
                    throw new RuntimeException('Synthetic worker failed.');
                }

                return true;
            });
    };
    if ($failExecution) {
        expect($run)->toThrow(RuntimeException::class, 'Synthetic worker failed.');
    } else {
        $run();
    }
    expect($inspections)->toBe(2)->and(array_column($events, 'event'))->toBe(['route_provenance_baseline', 'route_provenance_post_state'])
        ->and($events[1]['verification_passed'])->toBe(! $failExecution)->and($events[1]['complete_state_equal'])->toBe([1 => true, 2 => true])
        ->and($events[0]['states'][1]['route']['sha256'])->toBe(hash('sha256', json_encode(rpsProtectedStates()[1]['route'])))
        ->and(json_encode($events))->not->toContain('private-token', 'private-note');
})->with([false, true]);

it('does not inspect after losing authorization or turn unavailable post-state into a pass', function (bool $guardFails): void {
    $events = [];
    $guards = 0;
    $inspections = 0;
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    $run = function () use ($coordinator, &$guards, &$inspections, $guardFails): void {
        $coordinator->observeDuplicate(function () use (&$guards, $guardFails): bool {
            return ++$guards === 1 || ! $guardFails;
        }, function () use (&$inspections): array {
            if (++$inspections === 2) {
                throw new RuntimeException('private-token');
            }

            return rpsProtectedStates();
        }, fn (): bool => true);
    };
    expect($run)->toThrow(RuntimeException::class)
        ->and($inspections)->toBe($guardFails ? 1 : 2)
        ->and($events[1])->toBe(['event' => 'route_provenance_post_state', 'available' => false, 'verification_passed' => false])
        ->and(json_encode($events))->not->toContain('private-token');
})->with([false, true]);

it('keeps the full-suite NOWAIT probe evidence without inventing an HTTP response', function (): void {
    $events = [];
    (new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    }))->recordWorker('probe', ['exit_code' => 0, 'error_output' => '', 'output' => json_encode([
        'outcome' => 'row_lock_confirmed', 'mysql_error' => 3572, 'probe_connection_id' => 30,
        'connection_id' => 31, 'control_connection_id' => 32, 'pid' => 200])], 100, ['barrier' => 'rp-synthetic-test']);
    expect($events[0])->toMatchArray(['evidence_complete' => true, 'worker' => 'probe', 'barrier' => 'rp-synthetic-test',
        'probe_connection_id' => 30, 'connection_id' => 31, 'control_connection_id' => 32, 'mysql_error' => 3572])
        ->and($events[0])->not->toHaveKey('http_status');
});

it('still records the second worker and fails when the first evidence payload is missing', function (): void {
    $events = [];
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    $processes = [];
    foreach (['a', 'b'] as $name) {
        $reply = $name === 'a' ? ['exit_code' => 0, 'output' => '', 'error_output' => ''] : rpsWorkerProtocol($name);
        $process = Mockery::mock(Process::class);
        $process->shouldReceive('start')->once();
        $process->shouldReceive('getPid')->once()->andReturn($name === 'a' ? 101 : 102);
        $process->shouldReceive('wait')->once();
        $process->shouldReceive('isRunning')->once()->andReturnFalse();
        $process->shouldReceive('getExitCode')->andReturn($reply['exit_code']);
        $process->shouldReceive('getOutput')->andReturn($reply['output']);
        $process->shouldReceive('getErrorOutput')->andReturn($reply['error_output']);
        $processes[] = $process;
    }
    $runner = new ConcurrentProcessRunner(processFactory: function () use (&$processes): Process {
        return array_shift($processes);
    }, evidenceRecorder: function (string $name, array $reply, ?int $pid) use ($coordinator): void {
        $coordinator->recordWorker($name, $reply, $pid, ['barrier' => 'rp-synthetic-test', 'expected_estimate_revision' => 0]);
    });
    expect(fn () => $runner->run(['a' => ['synthetic'], 'b' => ['synthetic']]))->toThrow(RuntimeException::class)
        ->and(array_column($events, 'worker'))->toBe(['a', 'b'])
        ->and(array_column($events, 'evidence_complete'))->toBe([false, true]);
});

it('reports changed protected components after failed execution without a passing verdict', function (): void {
    $events = [];
    $calls = 0;
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    $inspect = function () use (&$calls): array {
        $state = rpsProtectedStates();
        if (++$calls === 2) {
            $state[1]['stops'][0]['id'] = 999;
        }

        return $state;
    };
    expect(fn () => $coordinator->observeDuplicate(fn (): bool => true, $inspect,
        fn () => throw new RuntimeException('Synthetic worker failed.')))->toThrow(RuntimeException::class)
        ->and($events[1]['complete_state_equal'])->toBe([1 => false, 2 => true])
        ->and($events[1]['verification_passed'])->toBeFalse()
        ->and($events[1]['states'][1]['stops']['sha256'])->not->toBe($events[0]['states'][1]['stops']['sha256']);
});

it('requires an explicit completed invariant verification even when all state is available', function (): void {
    $events = [];
    $coordinator = new RouteProvenanceRunCoordinator(function (array $event) use (&$events): void {
        $events[] = $event;
    });
    expect(fn () => $coordinator->observeDuplicate(fn (): bool => true, fn (): array => rpsProtectedStates(), fn (): bool => false))
        ->toThrow(RuntimeException::class)->and($events[1]['verification_passed'])->toBeFalse();
});

function rpsDeadlockReport(): string
{
    $report = "LATEST DETECTED DEADLOCK\n";
    foreach ([1, 2] as $number) {
        $report .= "*** ({$number}) TRANSACTION:\nTRANSACTION 123{$number}, ACTIVE 0 sec inserting\nMySQL thread id 9{$number}, OS thread\n";
        $report .= "insert into `route_estimate_provenances` values ('private-proof-and-password')\n";
        foreach (['HOLDS THE LOCK(S)', 'WAITING FOR THIS LOCK TO BE GRANTED'] as $kind) {
            $report .= "*** ({$number}) {$kind}:\nRECORD LOCKS space id 1 page no 4 n bits 72 index PRIMARY of table `fleet_concurrency_test`.`route_estimate_provenances` trx id 123{$number} lock_mode X";
            $report .= str_starts_with($kind, 'WAITING') ? " insert intention waiting\n" : "\n";
            $report .= "Record lock, heap no 1 PHYSICAL RECORD\n 0: len 8; hex 73757072656d756d; asc supremum;;\nprivate-record-bytes\n";
        }
    }

    return $report."*** WE ROLL BACK TRANSACTION (1)\n";
}

it('extracts only allowlisted deadlock cycle facts without retaining SQL or record contents', function (): void {
    $facts = InnoDbDeadlockDiagnostic::parse(rpsDeadlockReport());
    expect($facts['transactions'])->toHaveCount(2)->and($facts['victim_ordinal'])->toBe(1)
        ->and($facts['transactions'][0]['thread_id'])->toBe(91)
        ->and($facts['transactions'][0]['locks'][0])->toMatchArray(['kind' => 'held', 'index' => 'PRIMARY', 'mode' => 'X', 'supremum' => true])
        ->and($facts['transactions'][0]['locks'][1])->toMatchArray(['kind' => 'waiting', 'insert_intention' => true, 'supremum' => true])
        ->and(json_encode($facts))->not->toContain('private-', 'values', 'PHYSICAL RECORD', '737570');
});

it('rejects absent incomplete oversized or foreign-schema deadlock reports', function (string $report): void {
    expect(fn () => InnoDbDeadlockDiagnostic::parse($report))->toThrow(RuntimeException::class);
})->with(['', 'LATEST DETECTED DEADLOCK', str_repeat('x', 1048577), str_replace('fleet_concurrency_test', 'unapproved_schema', rpsDeadlockReport())]);

it('archives a recovered deadlock only after rollback and successful fresh transaction start', function (): void {
    $connection = new RouteProvenanceConnection(new PDO('sqlite::memory:'), 'isolated', '', ['driver' => 'mysql']);
    $connection->beginTransaction();
    $connection->queryFailures = [['state' => '40001', 'code' => 1213, 'constraint' => 'other']];
    $connection->rollBack();
    $connection->beginTransaction();
    try {
        expect($connection->recoveredDeadlocks)->toBe([['attempt' => 1, 'state' => '40001', 'code' => 1213,
            'rolled_back' => true, 'pdo_closed_before_restart' => true]])->and($connection->queryFailures)->toBe([]);
        $connection->queryFailures[] = ['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq'];
    } finally {
        $connection->rollBack();
    }
    $result = rpsResult(['sqlFailures' => $connection->queryFailures, 'recoveredDeadlocks' => $connection->recoveredDeadlocks]);
    expect($result['outcome'])->toBe('uuid_conflict')
        ->and($result['diagnostic']['sql_failures'])->toBe([['state' => '23000', 'code' => 1062, 'constraint' => 'rep_calculation_uq']])
        ->and($result['diagnostic']['recovered_deadlocks'][0]['code'])->toBe(1213);
});

it('does not erase unrelated SQL failures when a new transaction is attempted', function (): void {
    $connection = new RouteProvenanceConnection(new PDO('sqlite::memory:'), 'isolated', '', ['driver' => 'mysql']);
    $failure = [['state' => 'HY000', 'code' => 1205, 'constraint' => 'other']];
    $connection->queryFailures = $failure;
    expect(fn () => $connection->beginTransaction())->toThrow(RuntimeException::class)
        ->and($connection->queryFailures)->toBe($failure)->and($connection->recoveredDeadlocks)->toBe([])
        ->and($connection->getPdo()->inTransaction())->toBeFalse();
});

it('rejects invented unclean or excessive deadlock recovery claims', function (array $recovery): void {
    expect(fn () => rpsResult(['recoveredDeadlocks' => $recovery]))->toThrow(RuntimeException::class);
})->with([
    [[['attempt' => 1, 'state' => '40001', 'code' => 1213, 'rolled_back' => false, 'pdo_closed_before_restart' => true]]],
    [[['attempt' => 1, 'state' => '40001', 'code' => 1213, 'rolled_back' => true, 'pdo_closed_before_restart' => false]]],
    [[['attempt' => 1, 'state' => 'HY000', 'code' => 1205, 'rolled_back' => true, 'pdo_closed_before_restart' => true]]],
    [[['attempt' => 2, 'state' => '40001', 'code' => 1213, 'rolled_back' => true, 'pdo_closed_before_restart' => true]]],
    [[[], [], []]],
]);
