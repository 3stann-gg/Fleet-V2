<?php

use App\Models\Reservation;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\PlannedRouteProofService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const REP_SCHEMA_MIGRATION = '2026_11_12_180121_create_route_estimate_provenances_table.php';
const REP_REVISION_MIGRATION = '2026_11_12_180122_add_estimate_revision_to_route_plans_table.php';

function repSchemaMigration(bool $revision = false): Migration
{
    return require database_path('migrations/'.($revision ? REP_REVISION_MIGRATION : REP_SCHEMA_MIGRATION));
}

function repSchemaRoute(): RoutePlan
{
    $reservation = Reservation::query()->create([
        'reservation_number' => 'SYNTHETIC-SCHEMA-'.Str::uuid(),
        'patient_name' => null,
        'request_type' => 'Staff Transport',
        'pickup_location' => 'Synthetic Origin',
        'destination' => 'Synthetic Destination',
        'schedule_date' => '2026-11-20',
        'schedule_time' => '09:00:00',
        'priority' => 'Normal',
        'status' => 'Approved',
    ]);

    return RoutePlan::query()->create([
        'route_number' => 'SYNTHETIC-'.Str::uuid(),
        'reservation_id' => $reservation->id,
        'origin' => 'Synthetic Origin', 'destination' => 'Synthetic Destination',
        'department' => 'Operations', 'priority' => 'routine', 'status' => 'Draft',
        'departure_date' => '2026-11-20', 'departure_time' => '09:00:00',
    ])->refresh();
}

/** @return array<string, mixed> */
function repSchemaRecord(RoutePlan $route, bool $planned = false, array $overrides = []): array
{
    $snapshot = json_encode([
        'version' => 1,
        'origin' => ['latitude' => '14.6000000', 'longitude' => '121.0000000'],
        'destination' => ['latitude' => '14.7000000', 'longitude' => '121.1000000'],
        'stops' => [
            ['position' => 1, 'source_occurrence' => 0, 'latitude' => '14.6100000', 'longitude' => '121.0100000'],
            ['position' => 2, 'source_occurrence' => 1, 'latitude' => '14.6100000', 'longitude' => '121.0100000'],
        ],
    ], JSON_THROW_ON_ERROR);
    $record = [
        'route_plan_id' => $route->id, 'reservation_id' => $route->reservation_id,
        'calculation_mode' => $planned ? 'planned' : 'manual', 'snapshot_version' => 1,
        'estimated_distance_km' => '12.35', 'estimated_duration_minutes' => 16,
        'route_snapshot' => $snapshot, 'route_snapshot_hash' => hash('sha256', $snapshot),
        'source_route_revision' => 0, 'saved_route_revision' => 1,
        'verification_status' => 'current', 'current_marker' => 1,
        'accepted_at_utc' => '2026-11-12 00:00:00.000000',
        'created_at' => '2026-11-12 00:00:00.000000', 'updated_at' => '2026-11-12 00:00:00.000000',
    ];
    if ($planned) {
        $record = [...$record,
            'calculation_uuid' => (string) Str::uuid(), 'proof_version' => 2,
            'departure_at_utc' => '2026-11-20 01:00:00.000000', 'hospital_timezone' => 'Asia/Manila',
            'schedule_fingerprint' => hash('sha256', 'synthetic-schedule'),
            'provider' => 'TomTom', 'routing_strategy' => 'TomTom Traffic-Aware Optimized',
            'routing_options' => json_encode(['route_type' => 'fastest', 'traffic' => true], JSON_THROW_ON_ERROR),
            'traffic_model_status' => 'unknown', 'verification_scope' => 'requested_context_only',
            'calculated_at_utc' => '2026-11-12 00:00:00.000000',
            'distance_meters' => '12345.678901', 'duration_seconds' => '901.123456',
            'proof_issued_at_utc' => '2026-11-12 00:00:00.000000',
            'proof_expires_at_utc' => '2026-11-12 00:30:00.000000',
        ];
    }

    return [...$record, ...$overrides];
}

it('installs precisely the approved provenance columns and nullable definitions', function (): void {
    $columns = collect(Schema::getColumns('route_estimate_provenances'))->keyBy('name');
    $names = [
        'id', 'route_plan_id', 'reservation_id', 'calculation_mode', 'calculation_uuid', 'proof_version',
        'snapshot_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint', 'provider',
        'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope', 'calculated_at_utc',
        'distance_meters', 'duration_seconds', 'estimated_distance_km', 'estimated_duration_minutes',
        'route_snapshot', 'route_snapshot_hash', 'source_route_revision', 'saved_route_revision',
        'verification_status', 'current_marker', 'accepted_by_user_id', 'accepted_at_utc',
        'proof_issued_at_utc', 'proof_expires_at_utc', 'ended_at_utc', 'end_reason', 'created_at', 'updated_at',
    ];
    expect($columns->keys()->all())->toBe($names);
    $nullable = [
        'calculation_uuid', 'proof_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint',
        'provider', 'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope',
        'calculated_at_utc', 'distance_meters', 'duration_seconds', 'estimated_distance_km',
        'estimated_duration_minutes', 'current_marker', 'accepted_by_user_id', 'proof_issued_at_utc',
        'proof_expires_at_utc', 'ended_at_utc', 'end_reason',
    ];
    foreach ($names as $name) {
        if ($name !== 'id') {
            expect($columns[$name]['nullable'])->toBe(in_array($name, $nullable, true));
        }
    }
    expect(trim((string) $columns['snapshot_version']['default'], "'\""))->toBe('1');
});

it('installs exact uniqueness and lookup indexes with restrictive ownership foreign keys', function (): void {
    $indexes = collect(Schema::getIndexes('route_estimate_provenances'))->keyBy('name');
    foreach ([
        'rep_calculation_uq' => [['calculation_uuid'], true],
        'rep_current_uq' => [['route_plan_id', 'current_marker'], true],
        'rep_route_history_idx' => [['route_plan_id', 'id'], false],
        'rep_reservation_idx' => [['reservation_id'], false],
        'rep_actor_idx' => [['accepted_by_user_id'], false],
    ] as $name => [$fields, $unique]) {
        expect($indexes[$name]['columns'])->toBe($fields)->and($indexes[$name]['unique'])->toBe($unique);
    }
    $foreign = collect(Schema::getForeignKeys('route_estimate_provenances'))->keyBy(fn (array $key): string => $key['columns'][0]);
    foreach (['route_plan_id' => ['route_plans', 'restrict'], 'reservation_id' => ['reservations', 'restrict'], 'accepted_by_user_id' => ['users', 'set null']] as $column => [$table, $action]) {
        expect($foreign[$column]['foreign_table'])->toBe($table)
            ->and($foreign[$column]['foreign_columns'])->toBe(['id'])
            ->and(strtolower($foreign[$column]['on_delete']))->toBe($action);
    }
    expect(DB::selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
});

it('defaults legacy and new routes to revision zero without runtime increments or provenance backfill', function (): void {
    $route = repSchemaRoute();
    expect($route->estimate_revision)->toBe(0)->and(DB::table('route_estimate_provenances')->count())->toBe(0);
    $revision = collect(Schema::getColumns('route_plans'))->firstWhere('name', 'estimate_revision');
    expect($revision['nullable'])->toBeFalse()->and(trim((string) $revision['default'], "'\""))->toBe('0');
    $before = $route->getAttributes();
    repSchemaMigration()->down();
    repSchemaMigration(true)->down();
    repSchemaMigration()->up();
    repSchemaMigration(true)->up();
    expect($route->fresh()->getAttributes())->toBe($before)
        ->and(DB::table('route_estimate_provenances')->count())->toBe(0);
});

it('allows manual nullable evidence and complete planned records without implying manual verification', function (bool $planned): void {
    $route = repSchemaRoute();
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord($route, $planned));
    $record = DB::table('route_estimate_provenances')->find($id);
    expect($record->calculation_mode)->toBe($planned ? 'planned' : 'manual')
        ->and($record->provider)->toBe($planned ? 'TomTom' : null)
        ->and($record->proof_version)->toBe($planned ? 2 : null)
        ->and($record->verification_scope)->toBe($planned ? 'requested_context_only' : null)
        ->and($record->current_marker)->toBe(1);
})->with([true, false]);

it('rejects duplicate calculation UUIDs across different routes', function (): void {
    $first = repSchemaRoute();
    $second = repSchemaRoute();
    $record = repSchemaRecord($first, true);
    expect(Str::isUuid($record['calculation_uuid']))->toBeTrue();
    DB::table('route_estimate_provenances')->insert($record);
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($second, true, ['calculation_uuid' => $record['calculation_uuid']])))
        ->toThrow(QueryException::class)
        ->and(DB::table('route_estimate_provenances')->count())->toBe(1);
});

it('permits multiple historical rows but no conflicting current estimate for one route', function (): void {
    $route = repSchemaRoute();
    foreach (['superseded', 'invalidated', 'superseded'] as $state) {
        DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, false, [
            'verification_status' => $state, 'current_marker' => null,
            'ended_at_utc' => '2026-11-12 00:01:00.000000', 'end_reason' => 'synthetic_schema_history',
        ]));
    }
    DB::table('route_estimate_provenances')->insert(repSchemaRecord($route));
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, true)))->toThrow(QueryException::class)
        ->and(DB::table('route_estimate_provenances')->whereNull('current_marker')->count())->toBe(3)
        ->and(DB::table('route_estimate_provenances')->where('current_marker', 1)->count())->toBe(1);
    $other = repSchemaRoute();
    DB::table('route_estimate_provenances')->insert(repSchemaRecord($other));
    expect(DB::table('route_estimate_provenances')->where('current_marker', 1)->count())->toBe(2);
});

it('rejects invalid current markers on inserts and updates', function (mixed $marker): void {
    $route = repSchemaRoute();
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, false, ['current_marker' => $marker])))
        ->toThrow(QueryException::class);
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord($route));
    expect(fn () => DB::table('route_estimate_provenances')->where('id', $id)->update(['current_marker' => $marker]))->toThrow(QueryException::class)
        ->and(DB::table('route_estimate_provenances')->find($id)->current_marker)->toBe(1);
})->with([0, 2, -1, 1.5, 'invalid']);

it('rejects incomplete planned records manual provider claims and inconsistent lifecycle states', function (bool $planned, array $changes): void {
    $route = repSchemaRoute();
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, $planned, $changes)))
        ->toThrow(QueryException::class)
        ->and(DB::table('route_estimate_provenances')->count())->toBe(0);
})->with([
    [true, ['calculation_uuid' => null]], [true, ['proof_version' => 1]],
    [true, ['provider' => null]], [true, ['provider' => 'OSRM']],
    [true, ['departure_at_utc' => null]], [true, ['estimated_duration_minutes' => null]],
    [true, ['verification_scope' => 'provider_verified']], [true, ['routing_options' => null]],
    [false, ['provider' => 'TomTom']], [false, ['proof_version' => 2]],
    [false, ['current_marker' => null]],
    [false, ['verification_status' => 'invalidated']],
    [false, ['verification_status' => 'invalidated', 'current_marker' => null]],
    [false, ['verification_status' => 'unsupported']], [false, ['calculation_mode' => 'legacy']],
    [false, ['distance_meters' => -1]], [true, ['distance_meters' => -1]],
    [false, ['estimated_duration_minutes' => -1]],
    [false, ['source_route_revision' => -1]], [false, ['saved_route_revision' => 0]],
    [false, ['snapshot_version' => 0]],
]);

it('requires valid JSON and preserves repeated snapshot occurrences without private labels', function (): void {
    $route = repSchemaRoute();
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, false, ['route_snapshot' => '{broken'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, true, ['routing_options' => '{broken'])))
        ->toThrow(QueryException::class);
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord($route, true));
    $record = DB::table('route_estimate_provenances')->find($id);
    $snapshot = json_decode($record->route_snapshot, true, flags: JSON_THROW_ON_ERROR);
    expect(count($snapshot['stops']))->toBe(2)
        ->and($snapshot['stops'][0]['latitude'])->toBe($snapshot['stops'][1]['latitude'])
        ->and($snapshot['stops'][0]['source_occurrence'])->toBe(0)
        ->and($snapshot['stops'][1]['source_occurrence'])->toBe(1)
        ->and(json_decode($record->routing_options, true, flags: JSON_THROW_ON_ERROR)['traffic'])->toBeTrue()
        ->and($record->route_snapshot_hash)->toBe(hash('sha256', $record->route_snapshot))
        ->and($record->route_snapshot)->not->toContain('patient_name');
});

it('requires every planned evidence field while permitting its absence on manual records', function (string $field): void {
    $route = repSchemaRoute();
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord($route, true, [$field => null])))
        ->toThrow(QueryException::class);
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord($route));
    expect(DB::table('route_estimate_provenances')->find($id)->{$field})->toBeNull();
})->with([
    'calculation_uuid', 'proof_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint',
    'provider', 'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope',
    'calculated_at_utc', 'distance_meters', 'duration_seconds', 'proof_issued_at_utc', 'proof_expires_at_utc',
]);

it('allows manual estimates to remain unavailable without fabricating calculation metadata', function (): void {
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord(repSchemaRoute(), false, [
        'estimated_distance_km' => null, 'estimated_duration_minutes' => null,
    ]));
    $record = DB::table('route_estimate_provenances')->find($id);
    expect($record->estimated_distance_km)->toBeNull()->and($record->estimated_duration_minutes)->toBeNull()
        ->and($record->calculation_uuid)->toBeNull()->and($record->calculated_at_utc)->toBeNull();
});

it('preserves UTC text and non-integer metrics without claiming SQLite enforces decimal scale', function (): void {
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord(repSchemaRoute(), true));
    $record = DB::table('route_estimate_provenances')->find($id);
    expect((float) $record->distance_meters)->toEqualWithDelta(12345.678901, 0.000001)
        ->and((float) $record->duration_seconds)->toEqualWithDelta(901.123456, 0.000001)
        ->and((float) $record->estimated_distance_km)->toEqualWithDelta(12.35, 0.000001)
        ->and($record->departure_at_utc)->toBe('2026-11-20 01:00:00.000000')
        ->and($record->accepted_at_utc)->toBe('2026-11-12 00:00:00.000000');
});

it('restricts route and reservation deletion rather than cascading retained history', function (string $target): void {
    $route = repSchemaRoute();
    $route->stops()->create(['stop_order' => 1, 'location' => 'Synthetic stop']);
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord($route));
    $table = $target === 'route' ? 'route_plans' : 'reservations';
    $targetId = $target === 'route' ? $route->id : $route->reservation_id;
    expect(fn () => DB::table($table)->where('id', $targetId)->delete())->toThrow(QueryException::class)
        ->and(DB::table('route_estimate_provenances')->find($id))->not->toBeNull()
        ->and($route->fresh())->not->toBeNull()
        ->and($route->fresh()->stops()->count())->toBe(1)
        ->and(Reservation::query()->whereKey($route->reservation_id)->exists())->toBeTrue();
})->with(['route', 'reservation']);

it('sets only the actor reference to null on account deletion', function (): void {
    $user = User::factory()->create();
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord(repSchemaRoute(), false, ['accepted_by_user_id' => $user->id]));
    $before = (array) DB::table('route_estimate_provenances')->find($id);
    DB::table('users')->where('id', $user->id)->delete();
    $before['accepted_by_user_id'] = null;
    expect((array) DB::table('route_estimate_provenances')->find($id))->toBe($before);
});

it('preserves the existing reservation cascade when no provenance history exists', function (): void {
    $route = repSchemaRoute();
    $route->stops()->create(['stop_order' => 1, 'location' => 'Synthetic legacy stop']);
    DB::table('reservations')->where('id', $route->reservation_id)->delete();
    expect($route->fresh())->toBeNull()
        ->and(DB::table('route_stops')->where('route_plan_id', $route->id)->count())->toBe(0)
        ->and(DB::table('route_estimate_provenances')->count())->toBe(0);
});

it('rejects missing referenced resources at the database level', function (string $column): void {
    expect(fn () => DB::table('route_estimate_provenances')->insert(repSchemaRecord(repSchemaRoute(), false, [$column => 999999])))
        ->toThrow(QueryException::class);
})->with(['route_plan_id', 'reservation_id', 'accepted_by_user_id']);

it('refuses populated provenance rollback before dropping any history or revision column', function (): void {
    $route = repSchemaRoute();
    $id = DB::table('route_estimate_provenances')->insertGetId(repSchemaRecord($route));
    $before = (array) DB::table('route_estimate_provenances')->find($id);
    expect(fn () => repSchemaMigration()->down())->toThrow(RuntimeException::class, 'retained')
        ->and(fn () => repSchemaMigration(true)->down())->toThrow(RuntimeException::class, 'retained')
        ->and(Schema::hasTable('route_estimate_provenances'))->toBeTrue()
        ->and(Schema::hasColumn('route_plans', 'estimate_revision'))->toBeTrue()
        ->and((array) DB::table('route_estimate_provenances')->find($id))->toBe($before);
});

it('refuses revision rollback once a route counter has advanced even without provenance', function (): void {
    $route = repSchemaRoute();
    DB::table('route_plans')->where('id', $route->id)->update(['estimate_revision' => 1]);
    expect(fn () => repSchemaMigration(true)->down())->toThrow(RuntimeException::class, 'advanced')
        ->and(Schema::hasColumn('route_plans', 'estimate_revision'))->toBeTrue()
        ->and($route->fresh()->estimate_revision)->toBe(1);
});

it('rejects negative route revisions in the isolated SQLite schema', function (): void {
    $route = repSchemaRoute();
    expect(fn () => DB::table('route_plans')->where('id', $route->id)->update(['estimate_revision' => -1]))->toThrow(QueryException::class)
        ->and($route->fresh()->estimate_revision)->toBe(0);
});

it('keeps schema installation passive and all planned capabilities disabled', function (): void {
    repSchemaRoute();
    expect(DB::table('route_estimate_provenances')->count())->toBe(0)
        ->and(app(PlannedRouteProofService::class)->capabilities())->toBe([
            'calculation' => false, 'issuance' => false, 'apply' => false, 'save' => false,
        ]);
});

it('compiles MySQL 8 compatible CHECK JSON precision and retention DDL without a MySQL connection', function (): void {
    $connected = false;
    $mysql = new MySqlConnection(function () use (&$connected): never {
        $connected = true;
        throw new RuntimeException('MySQL access is forbidden during SQL compilation.');
    }, 'synthetic_compile_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
    $mysql->setSchemaGrammar(new MySqlGrammar($mysql));
    $migration = repSchemaMigration();
    $statements = (new ReflectionMethod($migration, 'statements'))->invoke($migration, $mysql);
    $sql = implode("\n", $statements);
    expect($connected)->toBeFalse()
        ->and($statements[0])->toContain('CONSTRAINT rep_marker_ck CHECK (current_marker IS NULL OR current_marker = 1)')
        ->and($statements[0])->toContain(") default character set utf8mb4 collate 'utf8mb4_unicode_ci' engine = InnoDB")
        ->and($sql)->toContain('`distance_meters` decimal(20, 6)', '`duration_seconds` decimal(20, 6)', '`estimated_distance_km` decimal(10, 2)')
        ->and($sql)->toContain('`departure_at_utc` datetime(6)', '`route_snapshot` json', '`routing_options` json')
        ->and($sql)->toContain('`calculation_uuid` char(36)', '`schedule_fingerprint` char(64)', '`route_snapshot_hash` char(64)')
        ->and($sql)->toContain('`source_route_revision` bigint unsigned', '`saved_route_revision` bigint unsigned')
        ->and($sql)->toContain('rep_current_uq', 'rep_calculation_uq', 'rep_route_fk', 'rep_reservation_fk', 'rep_actor_fk')
        ->and($sql)->toContain('on delete restrict', 'on delete set null')
        ->and(array_filter($statements, fn (string $statement): bool => str_contains($statement, 'ADD CONSTRAINT rep_marker_ck')))->toBe([]);
});
