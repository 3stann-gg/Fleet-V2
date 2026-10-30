<?php

use Illuminate\Support\Facades\DB;
use Tests\MySqlConcurrency\Support\DatabaseBarrier;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;

it('connects only to the guarded MySQL 8 InnoDB test schema', function () {
    $identity = IsolatedMySqlEnvironment::assertSafe();

    expect($identity['database'])->toBe('fleet_concurrency_test')
        ->and($identity['version'])->toStartWith('8.')
        ->and($identity['storage_engine'])->toBe('InnoDB')
        ->and($identity['isolation_level'])->not->toBeEmpty();
});

it('uses truly independent primary secondary and control sessions', function () {
    $identities = collect(IsolatedMySqlEnvironment::independentConnectionNames())
        ->mapWithKeys(fn (string $connectionName): array => [
            $connectionName => IsolatedMySqlEnvironment::assertSafe($connectionName),
        ]);

    expect($identities->pluck('connection_id')->unique())->toHaveCount(3)
        ->and($identities->pluck('database')->unique()->all())
        ->toBe(['fleet_concurrency_test']);
});

it('has the migrated application tables on InnoDB', function () {
    IsolatedMySqlEnvironment::assertAllBaseTablesUseInnoDb();

    expect(true)->toBeTrue();
});

it('coordinates participants through an independent control connection', function () {
    $barrierName = 'smoke-'.str()->uuid();

    DatabaseBarrier::reset($barrierName);
    DatabaseBarrier::arriveAndWait($barrierName, 'parent', 1);

    expect(DB::connection(IsolatedMySqlEnvironment::CONTROL_CONNECTION)
        ->table('concurrency_test_barriers')
        ->where('barrier_name', $barrierName)
        ->count())->toBe(1);
});
