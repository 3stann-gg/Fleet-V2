<?php

use App\Services\RouteEstimateProvenanceReadinessService;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

pest()->extend(TestCase::class);

function rprCondition(string $condition, bool $mysqlMetadata = false): array
{
    return (new ReflectionMethod(RouteEstimateProvenanceReadinessService::class, 'condition'))->invoke(new RouteEstimateProvenanceReadinessService, $condition, $mysqlMetadata);
}

/** Captured MySQL 8.4.13 schema metadata, reduced to readiness fields; no record data. */
function rprMysqlFixture(): array
{
    return json_decode(<<<'JSON'
{
    "checks": {
        "rep_current_state_ck": "(((`verification_status` = _utf8mb4\\'current\\') and (`current_marker` is not null) and (`current_marker` = 1) and (`ended_at_utc` is null) and (`end_reason` is null)) or ((`verification_status` <> _utf8mb4\\'current\\') and (`current_marker` is null) and (`ended_at_utc` is not null) and (`end_reason` is not null)))",
        "rep_marker_ck": "((`current_marker` is null) or (`current_marker` = 1))",
        "rep_metrics_ck": "(((`distance_meters` is null) or (`distance_meters` >= 0)) and ((`duration_seconds` is null) or (`duration_seconds` >= 0)) and ((`estimated_distance_km` is null) or (`estimated_distance_km` >= 0)) and ((`estimated_duration_minutes` is null) or (`estimated_duration_minutes` >= 0)))",
        "rep_mode_ck": "(`calculation_mode` in (_utf8mb4\\'manual\\',_utf8mb4\\'planned\\'))",
        "rep_mode_fields_ck": "(((`calculation_mode` = _utf8mb4\\'manual\\') and (`calculation_uuid` is null) and (`proof_version` is null) and (`departure_at_utc` is null) and (`hospital_timezone` is null) and (`schedule_fingerprint` is null) and (`provider` is null) and (`routing_strategy` is null) and (`routing_options` is null) and (`traffic_model_status` is null) and (`verification_scope` is null) and (`calculated_at_utc` is null) and (`distance_meters` is null) and (`duration_seconds` is null) and (`proof_issued_at_utc` is null) and (`proof_expires_at_utc` is null)) or ((`calculation_mode` = _utf8mb4\\'planned\\') and (`calculation_uuid` is not null) and (`proof_version` is not null) and (`departure_at_utc` is not null) and (`hospital_timezone` is not null) and (`schedule_fingerprint` is not null) and (`provider` is not null) and (`routing_strategy` is not null) and (`routing_options` is not null) and (`traffic_model_status` is not null) and (`verification_scope` is not null) and (`calculated_at_utc` is not null) and (`distance_meters` is not null) and (`duration_seconds` is not null) and (`proof_issued_at_utc` is not null) and (`proof_expires_at_utc` is not null) and (`proof_version` = 2) and (`hospital_timezone` = _utf8mb4\\'Asia/Manila\\') and (`provider` = _utf8mb4\\'TomTom\\') and (`verification_scope` = _utf8mb4\\'requested_context_only\\') and (`traffic_model_status` in (_utf8mb4\\'unknown\\',_utf8mb4\\'returned\\',_utf8mb4\\'unavailable\\')) and (`estimated_distance_km` is not null) and (`estimated_duration_minutes` is not null)))",
        "rep_options_json_ck": "((`routing_options` is null) or json_valid(`routing_options`))",
        "rep_revisions_ck": "((`source_route_revision` >= 0) and (`saved_route_revision` > `source_route_revision`) and (`snapshot_version` > 0))",
        "rep_snapshot_json_ck": "json_valid(`route_snapshot`)",
        "rep_status_ck": "(`verification_status` in (_utf8mb4\\'current\\',_utf8mb4\\'superseded\\',_utf8mb4\\'invalidated\\'))"
    },
    "columns": {
        "id": ["bigint","bigint unsigned",false,null],
        "route_plan_id": ["bigint","bigint unsigned",false,null],
        "reservation_id": ["bigint","bigint unsigned",false,null],
        "calculation_mode": ["varchar","varchar(16)",false,null],
        "calculation_uuid": ["char","char(36)",true,null],
        "proof_version": ["smallint","smallint unsigned",true,null],
        "snapshot_version": ["smallint","smallint unsigned",false,"1"],
        "departure_at_utc": ["datetime","datetime(6)",true,null],
        "hospital_timezone": ["varchar","varchar(64)",true,null],
        "schedule_fingerprint": ["char","char(64)",true,null],
        "provider": ["varchar","varchar(32)",true,null],
        "routing_strategy": ["varchar","varchar(100)",true,null],
        "routing_options": ["json","json",true,null],
        "traffic_model_status": ["varchar","varchar(24)",true,null],
        "verification_scope": ["varchar","varchar(32)",true,null],
        "calculated_at_utc": ["datetime","datetime(6)",true,null],
        "distance_meters": ["decimal","decimal(20,6)",true,null],
        "duration_seconds": ["decimal","decimal(20,6)",true,null],
        "estimated_distance_km": ["decimal","decimal(10,2)",true,null],
        "estimated_duration_minutes": ["int","int unsigned",true,null],
        "route_snapshot": ["json","json",false,null],
        "route_snapshot_hash": ["char","char(64)",false,null],
        "source_route_revision": ["bigint","bigint unsigned",false,null],
        "saved_route_revision": ["bigint","bigint unsigned",false,null],
        "verification_status": ["varchar","varchar(16)",false,null],
        "current_marker": ["tinyint","tinyint unsigned",true,null],
        "accepted_by_user_id": ["bigint","bigint unsigned",true,null],
        "accepted_at_utc": ["datetime","datetime(6)",false,null],
        "proof_issued_at_utc": ["datetime","datetime(6)",true,null],
        "proof_expires_at_utc": ["datetime","datetime(6)",true,null],
        "ended_at_utc": ["datetime","datetime(6)",true,null],
        "end_reason": ["varchar","varchar(64)",true,null],
        "created_at": ["datetime","datetime(6)",false,null],
        "updated_at": ["datetime","datetime(6)",false,null]
    },
    "indexes": {
        "primary": [["id"],true,true],
        "rep_actor_idx": [["accepted_by_user_id"],false,false],
        "rep_calculation_uq": [["calculation_uuid"],true,false],
        "rep_current_uq": [["route_plan_id","current_marker"],true,false],
        "rep_reservation_idx": [["reservation_id"],false,false],
        "rep_route_history_idx": [["route_plan_id","id"],false,false]
    },
    "foreign": {
        "accepted_by_user_id": ["users","set null"],
        "reservation_id": ["reservations","restrict"],
        "route_plan_id": ["route_plans","restrict"]
    }
}
JSON, true, 64, JSON_THROW_ON_ERROR);
}

/** An unconnected metadata-only MySQL double; real PDO access is forbidden. */
function rprMysqlConnection(array $fixture): Connection
{
    $connection = Mockery::mock(Connection::class);
    $schema = Mockery::mock(Builder::class);
    $connection->shouldNotReceive('getPdo');
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getServerVersion')->andReturn('8.4.13');
    $connection->shouldReceive('getTablePrefix')->andReturn('');
    $connection->shouldReceive('getDatabaseName')->andReturn('synthetic_metadata');
    $schema->shouldReceive('hasTable')->with('route_estimate_provenances')->andReturn(true);
    $schema->shouldReceive('hasColumn')->with('route_plans', 'estimate_revision')->andReturn($fixture['has_revision'] ?? true);
    $columns = [];
    foreach ($fixture['columns'] as $name => [$typeName, $type, $nullable, $default]) {
        $columns[] = ['name' => $name, 'type_name' => $typeName, 'type' => $type, 'nullable' => $nullable, 'default' => $default];
    }
    $schema->shouldReceive('getColumns')->with('route_estimate_provenances')->andReturn($columns);
    $schema->shouldReceive('getColumns')->with('route_plans')->andReturn([
        ['name' => 'estimate_revision', 'type_name' => 'bigint', 'type' => 'bigint unsigned', 'nullable' => false, 'default' => $fixture['revision_default'] ?? '0'],
    ]);
    $indexes = [];
    foreach ($fixture['indexes'] as $name => [$fields, $unique, $primary]) {
        $indexes[] = ['name' => $name, 'columns' => $fields, 'unique' => $unique, 'primary' => $primary];
    }
    $schema->shouldReceive('getIndexes')->with('route_estimate_provenances')->andReturn($indexes);
    $foreign = [];
    foreach ($fixture['foreign'] as $name => [$target, $deletion]) {
        $foreign[] = ['columns' => [$name], 'foreign_table' => $target, 'foreign_columns' => ['id'], 'on_delete' => $deletion];
    }
    $schema->shouldReceive('getForeignKeys')->with('route_estimate_provenances')->andReturn($foreign);
    $engines = array_map(fn (string $name): object => (object) ['name' => $name, 'engine' => $fixture['engine'] ?? 'InnoDB'],
        ['route_plans', 'reservations', 'route_stops', 'audit_logs', 'route_estimate_provenances']);
    $connection->shouldReceive('select')->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'information_schema.TABLES') && $bindings[0] === 'synthetic_metadata')->andReturn($engines);
    $checks = [];
    foreach ($fixture['checks'] as $name => $clause) {
        $checks[] = (object) ['name' => $name, 'clause' => $clause, 'enforced' => $fixture['enforced'] ?? 'YES'];
    }
    $connection->shouldReceive('select')->withArgs(fn (string $sql, array $bindings): bool => str_contains($sql, 'information_schema.CHECK_CONSTRAINTS') && $bindings === ['synthetic_metadata', 'route_estimate_provenances', 'CHECK'])->andReturn($checks);

    return $connection;
}

it('accepts captured MySQL CHECK metadata through the complete readiness contract without a connection', function (): void {
    $fixture = rprMysqlFixture();
    expect(substr_count($fixture['checks']['rep_mode_ck'], "\\'"))->toBe(4)
        ->and(bin2hex(substr($fixture['checks']['rep_mode_ck'], strpos($fixture['checks']['rep_mode_ck'], "\\'"), 2)))->toBe('5c27');
    $before = count(app('db')->getConnections());
    expect((new RouteEstimateProvenanceReadinessService)->installed(rprMysqlConnection($fixture)))->toBeTrue()
        ->and(count(app('db')->getConnections()))->toBe($before);
});

it('matches all nine captured CHECK clauses while preserving boolean grouping and literal case', function (): void {
    $service = new RouteEstimateProvenanceReadinessService;
    $expected = (new ReflectionMethod($service, 'expectedChecks'))->invoke($service);
    foreach (rprMysqlFixture()['checks'] as $name => $clause) {
        expect(rprCondition($clause, true))->toBe(rprCondition($expected[$name]));
    }
});

it('accepts the existing unescaped MySQL metadata through the same complete contract', function (): void {
    $fixture = rprMysqlFixture();
    $fixture['checks'] = (new ReflectionMethod(RouteEstimateProvenanceReadinessService::class, 'expectedChecks'))->invoke(new RouteEstimateProvenanceReadinessService);
    expect((new RouteEstimateProvenanceReadinessService)->installed(rprMysqlConnection($fixture)))->toBeTrue();
});

it('recognizes supported charset introductions and equivalent literal quoting', function (string $actual, string $expected): void {
    expect(rprCondition($actual, true))->toBe(rprCondition($expected));
})->with([
    ["provider = _utf8mb4\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _UTF8MB4\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _utf8mb3\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _utf8\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _ascii\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _latin1\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _binary\\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _utf8mb4 \\'TomTom\\'", "provider = 'TomTom'"],
    ["provider = _utf8mb4 'TomTom'", "provider = 'TomTom'"],
    ["provider = _utf8mb4\\'O''Brien\\'", "provider = 'O''Brien'"],
    ["provider = _utf8mb4'O''Brien'", "provider = 'O''Brien'"],
    ["hospital_timezone = _utf8mb4\\'Asia/Manila\\'", "hospital_timezone = 'Asia/Manila'"],
]);

it('rejects ambiguous malformed or unsupported escaped metadata', function (string $condition): void {
    expect(fn () => rprCondition($condition, true))->toThrow(HttpResponseException::class);
})->with([
    "mode = _utf8mb4\\'manual'",
    "mode = _utf8mb4'manual\\'",
    "mode = _utf8mb4\\'manual",
    "mode = _utf8mb4\\\\'manual\\\\'",
    "mode = \\'manual\\'",
    "mode = _utf16\\'manual\\'",
    "mode = _unknown\\'manual\\'",
    "mode = _utf8mb4\\'man\\ual\\'",
    "mode = _utf8mb4\\'O\\'Brien\\'",
    "mode = _utf8mb4\\'manual\\'\\",
    "mode = _utf8mb4\\'manual\\';",
    "mode = _utf8mb4\\'manual\\' /* hidden */",
    "mode = _utf8mb4\\'manual\\' AND",
]);

it('does not interpret MySQL metadata delimiter escapes as SQLite SQL', function (): void {
    expect(fn () => rprCondition("mode = _utf8mb4\\'manual\\'"))->toThrow(HttpResponseException::class);
});

it('keeps invalid schemas blocked even when valid metadata quoting is recognized', function (string $change): void {
    $fixture = rprMysqlFixture();
    switch ($change) {
        case 'missing check':
            unset($fixture['checks']['rep_mode_ck']);
            break;
        case 'wrong enum':
            $fixture['checks']['rep_mode_ck'] = str_replace('planned', 'other', $fixture['checks']['rep_mode_ck']);
            break;
        case 'extra enum':
            $fixture['checks']['rep_mode_ck'] = "calculation_mode in (_utf8mb4\\'manual\\',_utf8mb4\\'planned\\',_utf8mb4\\'other\\')";
            break;
        case 'wrong literal case':
            $fixture['checks']['rep_mode_fields_ck'] = str_replace('TomTom', 'tomtom', $fixture['checks']['rep_mode_fields_ck']);
            break;
        case 'wrong grouping':
            $fixture['checks']['rep_metrics_ck'] = str_replace(['(', ')'], '', $fixture['checks']['rep_metrics_ck']);
            break;
        case 'malformed':
            $fixture['checks']['rep_mode_ck'] = "calculation_mode in (_utf8mb4\\'manual')";
            break;
        case 'not enforced':
            $fixture['enforced'] = 'NO';
            break;
        case 'wrong column type':
            $fixture['columns']['calculation_uuid'][1] = 'varchar(36)';
            break;
        case 'wrong nullability':
            $fixture['columns']['current_marker'][2] = false;
            break;
        case 'missing column':
            unset($fixture['columns']['proof_version']);
            break;
        case 'missing unique index':
            unset($fixture['indexes']['rep_current_uq']);
            break;
        case 'not unique':
            $fixture['indexes']['rep_calculation_uq'][1] = false;
            break;
        case 'wrong foreign key':
            $fixture['foreign']['route_plan_id'][1] = 'cascade';
            break;
        case 'wrong engine':
            $fixture['engine'] = 'MyISAM';
            break;
        case 'missing revision':
            $fixture['has_revision'] = false;
            break;
        case 'wrong revision default':
            $fixture['revision_default'] = '1';
            break;
    }
    try {
        (new RouteEstimateProvenanceReadinessService)->installed(rprMysqlConnection($fixture));
        test()->fail('Invalid schema was accepted.');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(503)
            ->and(json_decode($exception->getResponse()->getContent(), true)['code'])->toBe('ROUTE_PROVENANCE_SCHEMA_NOT_READY');
    }
})->with(['missing check', 'wrong enum', 'extra enum', 'wrong literal case', 'wrong grouping', 'malformed', 'not enforced',
    'wrong column type', 'wrong nullability', 'missing column', 'missing unique index', 'not unique', 'wrong foreign key',
    'wrong engine', 'missing revision', 'wrong revision default']);

it('accepts redundant provider-rendered parentheses identifier quotes and literal charset prefixes', function (string $first, string $second): void {
    expect(rprCondition($first))->toBe(rprCondition($second));
})->with([
    ['current_marker IS NULL OR current_marker = 1', '((`current_marker` is null) OR (`current_marker` = 1))'],
    ["provider = 'TomTom' AND hospital_timezone = 'Asia/Manila'", "((`provider` = _utf8mb4'TomTom') and (`hospital_timezone` = _utf8mb4'Asia/Manila'))"],
    ['a IS NOT NULL AND b > a AND c >= 0', '((a is not null AND b > a) AND c >= 0)'],
    ["mode IN ('manual','planned')", "(`mode` in (_utf8mb4'manual',_utf8mb4'planned'))"],
    ['JSON_VALID(route_snapshot)', '(json_valid(`route_snapshot`))'],
]);

it('rejects semantic drift even when the same condition names and tokens remain', function (string $first, string $second): void {
    expect(rprCondition($first))->not->toBe(rprCondition($second));
})->with([
    ['(a IS NULL OR a >= 0) AND (b IS NULL OR b >= 0)', 'a IS NULL OR a >= 0 AND b IS NULL OR b >= 0'],
    ['a = 1 AND (b = 2 OR c = 3)', '(a = 1 AND b = 2) OR c = 3'],
    ["provider = 'TomTom'", "provider = 'tomtom'"],
    ["hospital_timezone = 'Asia/Manila'", "hospital_timezone = 'asia/manila'"],
    ['current_marker IS NULL OR current_marker = 1', 'current_marker IS NULL OR current_marker = 2'],
    ["mode IN ('manual','planned')", "mode IN ('manual','planned','other')"],
]);

it('fails closed for malformed or unsupported constraint syntax', function (string $condition): void {
    expect(fn () => rprCondition($condition))->toThrow(HttpResponseException::class);
})->with(['', '(a = 1', 'a = 1)', 'a = 1;', "provider = 'unterminated", 'a = 1 AND', '/* hidden */ a=1']);

it('parses all approved conditions without database access or ignoring boolean grouping', function (): void {
    $service = new RouteEstimateProvenanceReadinessService;
    $checks = (new ReflectionMethod($service, 'expectedChecks'))->invoke($service);
    $before = count(app('db')->getConnections());
    foreach ($checks as $condition) {
        expect(rprCondition($condition))->toBe(rprCondition('((('.$condition.')))'));
    }
    expect($checks)->toHaveCount(9)->and(count(app('db')->getConnections()))->toBe($before);
});
