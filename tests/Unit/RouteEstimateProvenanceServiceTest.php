<?php

use App\Models\RouteEstimateProvenance;
use App\Models\RoutePlan;
use App\Models\RouteStop;
use App\Services\RouteEstimateProvenanceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

pest()->extend(TestCase::class);

/** Real in-memory transactions with only the driver identification doubled for the MySQL retry branch. */
function repRetryConnection(string $driver = 'mysql'): SQLiteConnection
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), 'isolated', '', ['driver' => $driver]);
    expect($connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME))->toBe('sqlite')
        ->and($connection->getDriverName())->toBe($driver);
    $connection->statement('CREATE TABLE retry_ledger (component TEXT PRIMARY KEY, value INTEGER NOT NULL)');
    foreach (['route', 'stops', 'revision', 'provenance', 'audit'] as $component) {
        $connection->insert('INSERT INTO retry_ledger VALUES (?, 0)', [$component]);
    }

    return $connection;
}

function repRetryFailure(string $state = '40001', int $code = 1213): QueryException
{
    $previous = new PDOException($code === 1213 ? 'Deadlock found when trying to get lock; try restarting transaction' : 'Synthetic terminal SQL failure');
    $previous->errorInfo = [$state, $code, 'Synthetic safe metadata'];

    return new QueryException('isolated', 'synthetic statement', [], $previous);
}

it('replays the complete rolled-back top-level transaction at most three times on MySQL deadlocks', function (): void {
    $connection = repRetryConnection();
    $calls = 0;
    $method = new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction');
    $result = $method->invoke(new RouteEstimateProvenanceService, $connection, function () use ($connection, &$calls): string {
        $calls++;
        expect($connection->select('SELECT value FROM retry_ledger WHERE value <> 0'))->toBeEmpty()
            ->and($connection->transactionLevel())->toBe(1)->and($connection->getPdo()->inTransaction())->toBeTrue();
        $connection->update('UPDATE retry_ledger SET value = 1');
        if ($calls < 3) {
            throw repRetryFailure();
        }

        return 'committed';
    });
    expect($result)->toBe('committed')->and($calls)->toBe(3)
        ->and($connection->select('SELECT value FROM retry_ledger WHERE value = 1'))->toHaveCount(5)
        ->and($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse();
});

it('exhausts the deadlock budget without partial writes or open transactions', function (): void {
    $connection = repRetryConnection();
    $calls = 0;
    $method = new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction');
    $operation = function () use ($connection, &$calls): void {
        $calls++;
        $connection->update('UPDATE retry_ledger SET value = 1');
        throw repRetryFailure();
    };
    expect(fn () => $method->invoke(new RouteEstimateProvenanceService, $connection, $operation))->toThrow(QueryException::class)
        ->and($calls)->toBe(3)->and($connection->select('SELECT value FROM retry_ledger WHERE value <> 0'))->toBeEmpty()
        ->and($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse();
});

it('does not retry timeouts unique violations non-MySQL failures or application errors', function (string $state, int $code, string $driver, bool $applicationError): void {
    $connection = repRetryConnection($driver);
    $calls = 0;
    $method = new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction');
    $operation = function () use ($connection, &$calls, $state, $code, $applicationError): void {
        $calls++;
        $connection->update('UPDATE retry_ledger SET value = 1');
        throw $applicationError ? new InvalidArgumentException('Synthetic validation failure') : repRetryFailure($state, $code);
    };
    expect(fn () => $method->invoke(new RouteEstimateProvenanceService, $connection, $operation))->toThrow($applicationError ? InvalidArgumentException::class : QueryException::class)
        ->and($calls)->toBe(1)->and($connection->select('SELECT value FROM retry_ledger WHERE value <> 0'))->toBeEmpty()
        ->and($connection->getPdo()->inTransaction())->toBeFalse();
})->with([['HY000', 1205, 'mysql', false], ['23000', 1062, 'mysql', false], ['40001', 1213, 'sqlite', false], ['40001', 1213, 'mysql', true]]);

it('preserves a terminal duplicate rejection after a fully rolled-back deadlock retry', function (): void {
    $connection = repRetryConnection();
    $calls = 0;
    $terminal = repRetryFailure('23000', 1062);
    $operation = function () use ($connection, &$calls, $terminal): void {
        $connection->update('UPDATE retry_ledger SET value = 1');
        throw ++$calls === 1 ? repRetryFailure() : $terminal;
    };
    try {
        (new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction'))->invoke(new RouteEstimateProvenanceService, $connection, $operation);
        $this->fail('The duplicate conflict must not be swallowed.');
    } catch (QueryException $exception) {
        expect($exception)->toBe($terminal);
    }
    expect($calls)->toBe(2)->and($connection->select('SELECT value FROM retry_ledger WHERE value <> 0'))->toBeEmpty()
        ->and($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse();
});

it('never replays a deadlock inside an owner-managed outer transaction', function (): void {
    $connection = repRetryConnection();
    $calls = 0;
    $connection->beginTransaction();
    try {
        $operation = function () use (&$calls): void {
            $calls++;
            throw repRetryFailure();
        };
        expect(fn () => (new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction'))->invoke(new RouteEstimateProvenanceService, $connection, $operation))
            ->toThrow(DeadlockException::class)->and($calls)->toBe(1);
    } finally {
        $connection->rollBack(0);
    }
    expect($connection->getPdo()->inTransaction())->toBeFalse();
});

it('emits retry monitoring only after rollback and recovery only after a committed attempt', function (bool $terminalDuplicate): void {
    $connection = repRetryConnection();
    $events = [];
    Log::shouldReceive('log')->times($terminalDuplicate ? 1 : 2)->andReturnUsing(function (string $level, string $event, array $context) use ($connection, &$events): void {
        $events[] = ['level' => $level, 'event' => $event, 'context' => $context,
            'transaction_level' => $connection->transactionLevel(), 'pdo_open' => $connection->getPdo()->inTransaction(),
            'ledger' => array_column($connection->select('SELECT value FROM retry_ledger'), 'value')];
    });
    $calls = 0;
    $terminal = repRetryFailure('23000', 1062);
    $operation = function () use ($connection, &$calls, $terminalDuplicate, $terminal): string {
        $calls++;
        $connection->update('UPDATE retry_ledger SET value = 1');
        if ($calls === 1) {
            throw repRetryFailure();
        }
        if ($terminalDuplicate) {
            throw $terminal;
        }

        return 'saved';
    };
    $execute = fn () => (new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction'))->invoke(new RouteEstimateProvenanceService, $connection, $operation);
    if ($terminalDuplicate) {
        try {
            $execute();
            $this->fail('A terminal duplicate must propagate.');
        } catch (QueryException $exception) {
            expect($exception)->toBe($terminal);
        }
    } else {
        expect($execute())->toBe('saved');
    }
    expect($calls)->toBe(2)->and($events)->toHaveCount($terminalDuplicate ? 1 : 2);
    foreach ($events as $event) {
        expect($event['level'])->toBe('notice')->and($event['transaction_level'])->toBe(0)->and($event['pdo_open'])->toBeFalse();
    }
    expect($events[0]['event'])->toBe('fleet.route_save.deadlock_retry')->and($events[0]['ledger'])->toBe([0, 0, 0, 0, 0])
        ->and($events[0]['context'])->toMatchArray(['outcome' => 'retry_scheduled', 'attempt' => 1, 'max_attempts' => 3, 'transaction_clean' => true]);
    if (! $terminalDuplicate) {
        expect($events[1]['event'])->toBe('fleet.route_save.deadlock_recovered')->and($events[1]['ledger'])->toBe([1, 1, 1, 1, 1])
            ->and($events[1]['context'])->toMatchArray(['outcome' => 'committed_after_retry', 'attempt' => 2]);
    }
})->with([false, true]);

it('keeps retry results and terminal exceptions unchanged when the logger throws', function (bool $terminalDuplicate): void {
    $connection = repRetryConnection();
    Log::shouldReceive('log')->times($terminalDuplicate ? 1 : 2)->andThrow(new RuntimeException('Synthetic logger failure'));
    $calls = 0;
    $terminal = repRetryFailure('23000', 1062);
    $operation = function () use ($connection, &$calls, $terminal, $terminalDuplicate): string {
        $connection->update('UPDATE retry_ledger SET value = 1');
        if (++$calls === 1) {
            throw repRetryFailure();
        }
        if ($terminalDuplicate) {
            throw $terminal;
        }

        return 'saved';
    };
    $execute = fn () => (new ReflectionMethod(RouteEstimateProvenanceService::class, 'writerTransaction'))->invoke(new RouteEstimateProvenanceService, $connection, $operation);
    if ($terminalDuplicate) {
        try {
            $execute();
            $this->fail('The original terminal failure must propagate.');
        } catch (QueryException $exception) {
            expect($exception)->toBe($terminal);
        }
    } else {
        expect($execute())->toBe('saved');
    }
    expect($calls)->toBe(2)->and($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse()
        ->and($connection->select('SELECT value FROM retry_ledger WHERE value <> 0'))->toHaveCount($terminalDuplicate ? 0 : 5);
})->with([false, true]);

function repDomainRoute(): RoutePlan
{
    $route = (new RoutePlan)->forceFill([
        'id' => 41, 'reservation_id' => 12, 'status' => 'Draft', 'estimate_revision' => 0,
        'origin' => 'Synthetic Origin', 'origin_latitude' => '14.60000001', 'origin_longitude' => '121.00000004',
        'destination' => 'Synthetic Destination', 'destination_latitude' => '14.70000005', 'destination_longitude' => '121.10000006',
        'departure_date' => '2026-11-20', 'departure_time' => '09:00',
        'estimated_distance' => '12.345', 'estimated_time' => '16',
        'optimization_strategy' => 'TomTom Traffic-Aware Optimized',
        'notes' => 'Not fingerprinted', 'optimization_score' => '88.00',
    ]);
    $route->setRelation('stops', new Collection([
        (new RouteStop)->forceFill(['id' => 101, 'route_plan_id' => 41, 'stop_order' => 1, 'location' => 'Repeated synthetic label', 'latitude' => '14.6100000', 'longitude' => '121.0100000']),
        (new RouteStop)->forceFill(['id' => 102, 'route_plan_id' => 41, 'stop_order' => 2, 'location' => 'Repeated synthetic label', 'latitude' => '14.6100000', 'longitude' => '121.0100000']),
    ]));

    return $route;
}

it('builds exact canonical storage values without copying labels or free text', function (): void {
    $service = new RouteEstimateProvenanceService;
    $snapshot = $service->snapshot(repDomainRoute());
    expect($snapshot['version'])->toBe(1)
        ->and($snapshot['origin'])->toBe(['label_hash' => hash('sha256', 'Synthetic Origin'), 'latitude' => '14.6000000', 'longitude' => '121.0000000'])
        ->and($snapshot['destination']['latitude'])->toBe('14.7000001')
        ->and($snapshot['estimated_distance_km'])->toBe('12.35')
        ->and($snapshot['estimated_duration_minutes'])->toBe(16)
        ->and($snapshot['departure_time'])->toBe('09:00:00')
        ->and($snapshot['stops'])->toHaveCount(2)
        ->and(array_column($snapshot['stops'], 'position'))->toBe([1, 2])
        ->and($snapshot['stops'][0]['label_hash'])->toBe($snapshot['stops'][1]['label_hash'])
        ->and($service->canonicalJson($snapshot))->not->toContain('Synthetic Origin', 'Repeated synthetic label', 'Not fingerprinted', 'optimization_score', '101');
});

it('keeps deterministic fingerprints across storage representations and replacement stop IDs', function (): void {
    $service = new RouteEstimateProvenanceService;
    $route = repDomainRoute();
    $first = $service->fingerprint($route);
    $route->forceFill(['origin_latitude' => '14.6000000', 'estimated_distance' => '12.35', 'departure_time' => '09:00:00']);
    $route->stops[0]->id = 900;
    $route->stops[1]->id = 901;
    $route->setRelation('stops', $route->stops->reverse());
    expect($service->fingerprint($route))->toBe($first)
        ->and($service->canonicalJson(['z' => ['b' => 2, 'a' => 1], 'a' => [2, 1]]))->toBe('{"a":[2,1],"z":{"a":1,"b":2}}');
});

it('excludes unrelated notes assessment and lifecycle fields from the fingerprint', function (string $field, mixed $value): void {
    $service = new RouteEstimateProvenanceService;
    $route = repDomainRoute();
    $hash = $service->fingerprint($route);
    $route->forceFill([$field => $value]);
    expect($service->fingerprint($route))->toBe($hash);
})->with(['notes' => ['notes', 'Changed note'], 'score' => ['optimization_score', '1.00'], 'status' => ['status', 'Completed'], 'purpose' => ['purpose', 'Unrelated synthetic purpose'], 'revision' => ['estimate_revision', 5]]);

it('changes the hash for every estimate-bound route field', function (string $field, mixed $value): void {
    $service = new RouteEstimateProvenanceService;
    $route = repDomainRoute();
    $hash = $service->fingerprint($route);
    $route->forceFill([$field => $value]);
    expect($service->fingerprint($route))->not->toBe($hash);
})->with([
    ['origin', 'Another synthetic origin'], ['destination', 'Another synthetic destination'],
    ['origin_latitude', '14.6000001'], ['destination_longitude', '121.2000000'],
    ['estimated_distance', '12.36'], ['estimated_time', 17],
    ['optimization_strategy', 'Manual'], ['departure_date', '2026-11-21'], ['departure_time', '09:01:00'],
]);

it('binds distinct stop occurrences and their order without deduplication', function (): void {
    $service = new RouteEstimateProvenanceService;
    $route = repDomainRoute();
    $original = $service->fingerprint($route);
    $route->stops[0]->location = 'Different synthetic label';
    $differentLabel = $service->fingerprint($route);
    $route->stops[0]->latitude = '14.6200000';
    $differentCoordinate = $service->fingerprint($route);
    $route->stops[0]->stop_order = 2;
    $route->stops[1]->stop_order = 1;
    $reordered = $service->fingerprint($route);
    expect(array_unique([$original, $differentLabel, $differentCoordinate, $reordered]))->toHaveCount(4)
        ->and($service->snapshot($route)['stops'][1]['label_hash'])->toBe(hash('sha256', 'Different synthetic label'));
});

it('distinguishes explicit null metrics and coordinates from genuine zero', function (): void {
    $service = new RouteEstimateProvenanceService;
    $route = repDomainRoute()->forceFill(['estimated_distance' => null, 'estimated_time' => null, 'origin_latitude' => null, 'origin_longitude' => null]);
    $null = $service->snapshot($route);
    $hash = $service->fingerprint($route);
    $route->forceFill(['estimated_distance' => 0, 'estimated_time' => 0, 'origin_latitude' => 0, 'origin_longitude' => 0]);
    expect($null['estimated_distance_km'])->toBeNull()->and($null['estimated_duration_minutes'])->toBeNull()
        ->and($null['origin']['latitude'])->toBeNull()
        ->and($service->snapshot($route)['estimated_distance_km'])->toBe('0.00')
        ->and($service->snapshot($route)['estimated_duration_minutes'])->toBe(0)
        ->and($service->fingerprint($route))->not->toBe($hash);
});

it('rejects malformed route values rather than coercing them into estimates', function (string $field, mixed $value): void {
    $route = repDomainRoute();
    $attributes = $route->getAttributes();
    $attributes[$field] = $value;
    $route->setRawAttributes($attributes);
    expect(fn () => (new RouteEstimateProvenanceService)->snapshot($route))->toThrow(InvalidArgumentException::class);
})->with([
    ['origin_latitude', '91'], ['origin_longitude', '-181'], ['origin_latitude', null],
    ['estimated_distance', '-1'], ['estimated_distance', 'NaN'], ['estimated_distance', INF],
    ['estimated_distance', true], ['estimated_distance', '100000000.00'],
    ['estimated_time', '16.1'], ['estimated_time', true], ['estimated_time', '4294967296'],
    ['departure_date', '2026-02-30'], ['departure_time', '24:00:00'], ['origin', ''],
    ['optimization_strategy', false], ['id', '9223372036854775808'],
]);

it('rejects missing route fields unloaded relations and incomplete or foreign stops', function (string $case): void {
    $route = repDomainRoute();
    if ($case === 'unloaded') {
        $route->unsetRelation('stops');
    } elseif ($case === 'missing') {
        $route->offsetUnset('estimated_time');
    } elseif ($case === 'duplicate') {
        $route->stops[1]->stop_order = 1;
    } elseif ($case === 'gap') {
        $route->stops[1]->stop_order = 3;
    } elseif ($case === 'foreign') {
        $route->stops[1]->route_plan_id = 99;
    } else {
        $route->stops[1]->offsetUnset('longitude');
    }
    expect(fn () => (new RouteEstimateProvenanceService)->snapshot($route))->toThrow(InvalidArgumentException::class);
})->with(['unloaded', 'missing', 'duplicate', 'gap', 'foreign', 'partial']);

it('uses immutable UTC casts and preserves decimal precision independently of the host timezone', function (): void {
    $timezone = date_default_timezone_get();
    try {
        date_default_timezone_set('Asia/Manila');
        $record = (new RouteEstimateProvenance)->forceFill([
            'departure_at_utc' => '2026-11-20 01:00:00.123456',
            'distance_meters' => '99999999999999.123456', 'duration_seconds' => '900.000001',
            'estimated_distance_km' => '12.30', 'route_snapshot' => ['version' => 1],
        ]);
        expect($record->departure_at_utc)->toBeInstanceOf(CarbonImmutable::class)
            ->and($record->departure_at_utc->format('Y-m-d H:i:s.u e'))->toBe('2026-11-20 01:00:00.123456 UTC')
            ->and($record->distance_meters)->toBe('99999999999999.123456')
            ->and($record->duration_seconds)->toBe('900.000001')
            ->and($record->estimated_distance_km)->toBe('12.30')
            ->and($record->route_snapshot)->toBe(['version' => 1]);
        $record->accepted_at_utc = CarbonImmutable::parse('2026-11-20T09:00:00+08:00');
        expect($record->getAttributes()['accepted_at_utc'])->toBe('2026-11-20 01:00:00.000000');
    } finally {
        date_default_timezone_set($timezone);
    }
});

it('never grants eligibility from a client verification label or an unpersisted evidence object', function (): void {
    $route = repDomainRoute()->forceFill(['verified' => true, 'verification_status' => 'current_planned']);
    $service = new RouteEstimateProvenanceService;
    expect($service->summary($route)['status'])->toBe('unrecorded')
        ->and($service->summary($route, (new RouteEstimateProvenance)->forceFill(['verification_status' => 'current']))['status'])->toBe('stale_unverified')
        ->and($service->summary($route)['save_eligible'])->toBeFalse();
});

it('plans first acceptance and estimate changes without mutating either model', function (): void {
    $service = new RouteEstimateProvenanceService;
    $before = repDomainRoute();
    $after = clone $before;
    $after->notes = 'Unrelated change';
    $attributes = $before->getAttributes();
    $at = CarbonImmutable::parse('2026-11-12T00:00:00Z');
    $notes = $service->planTransition($before, $after, null, 'manual_edit', 0, $at);
    $planned = $service->planTransition($before, $after, null, 'accept_planned', 0, $at);
    $after->estimated_time = 20;
    $manual = $service->planTransition($before, $after, null, 'manual_edit', 0, $at);
    expect($notes['changed'])->toBeFalse()->and($notes['saved_route_revision'])->toBe(0)->and($notes['next_mode'])->toBeNull()
        ->and($planned['saved_route_revision'])->toBe(1)->and($planned['next_mode'])->toBe('planned')
        ->and($planned['persistence_allowed'])->toBeFalse()
        ->and($planned['requires'])->toContain('authenticated_fresh_revision_bound_proof', 'route_then_reservation_locks')
        ->and($manual['saved_route_revision'])->toBe(1)->and($manual['next_mode'])->toBe('manual')
        ->and($before->getAttributes())->toBe($attributes);
});

it('rejects stale revision and illegal transition intentions', function (string $case): void {
    $before = repDomainRoute();
    $after = clone $before;
    $event = 'manual_edit';
    $revision = 0;
    if ($case === 'stale') {
        $revision = 1;
    } elseif ($case === 'revision_changed') {
        $after->forceFill(['estimate_revision' => 1]);
    } elseif ($case === 'identity') {
        $after->id = 99;
    } elseif ($case === 'lifecycle') {
        $after->status = 'Completed';
    } elseif ($case === 'planned_completed') {
        $before->status = 'Completed';
        $event = 'accept_planned';
    } elseif ($case === 'missing_status') {
        $before->offsetUnset('status');
        $after->offsetUnset('status');
    } elseif ($case === 'unknown_status') {
        $before->status = 'Unknown';
        $after->status = 'Unknown';
    } else {
        $event = 'unknown';
    }
    expect(fn () => (new RouteEstimateProvenanceService)->planTransition($before, $after, null, $event, $revision, CarbonImmutable::now('UTC')))->toThrow(InvalidArgumentException::class);
})->with(['stale', 'revision_changed', 'identity', 'lifecycle', 'planned_completed', 'missing_status', 'unknown_status', 'unknown']);

it('plans archive restore and duplication without reviving or inheriting evidence', function (): void {
    $service = new RouteEstimateProvenanceService;
    $before = repDomainRoute();
    $archived = clone $before;
    $archived->status = 'Archived';
    $restored = clone $before;
    $duplicate = clone $before;
    $duplicate->id = 99;
    $duplicate->setRelation('stops', $before->stops->map(function (RouteStop $stop): RouteStop {
        $copy = clone $stop;
        $copy->route_plan_id = 99;

        return $copy;
    }));
    $at = CarbonImmutable::now('UTC');
    foreach ([$service->planTransition($before, $archived, null, 'archive', 0, $at), $service->planTransition($archived, $restored, null, 'restore', 0, $at)] as $plan) {
        expect($plan['saved_route_revision'])->toBe(1)->and($plan['next_mode'])->toBeNull()->and($plan['inherit_provenance'])->toBeFalse();
    }
    $plan = $service->planTransition($before, $duplicate, null, 'duplicate', 0, $at);
    expect($plan['saved_route_revision'])->toBe(0)->and($plan['close'])->toBeNull()->and($plan['inherit_provenance'])->toBeFalse();
});

it('rejects invalid writer identities before opening a database connection', function (int $routeId, int $reservationId, int $revision, int $actorId): void {
    $before = count(app('db')->getConnections());
    expect(fn () => (new RouteEstimateProvenanceService)->writeManualEstimate('unused_writer_connection', $routeId, $reservationId, $revision, $actorId, [], fn () => true, fn () => null))->toThrow(InvalidArgumentException::class)
        ->and(count(app('db')->getConnections()))->toBe($before);
})->with([[0, 1, 0, 1], [1, 0, 0, 1], [1, 1, -1, 1], [1, 1, 0, 0]]);
