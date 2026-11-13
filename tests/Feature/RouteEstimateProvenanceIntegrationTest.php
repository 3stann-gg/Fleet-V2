<?php

use App\Models\AuditLog;
use App\Models\Dispatch;
use App\Models\Driver;
use App\Models\DriverVehicleQualification;
use App\Models\Reservation;
use App\Models\RouteEstimateProvenance;
use App\Models\RoutePlan;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PlannedRouteProofService;
use App\Services\RouteEstimateProvenanceReadinessService;
use App\Services\RouteEstimateProvenanceService;
use App\Services\TomTomRoutingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

function rpiRoute(): RoutePlan
{
    $reservation = Reservation::query()->create([
        'reservation_number' => 'SYNTHETIC-INTEGRATION-'.Str::uuid(), 'patient_name' => null,
        'request_type' => 'staff_transport', 'pickup_location' => 'Synthetic Origin', 'destination' => 'Synthetic Destination',
        'schedule_date' => '2026-11-20', 'schedule_time' => '09:00:00', 'priority' => 'routine', 'status' => 'Approved',
    ]);
    $route = RoutePlan::query()->create([
        'route_number' => 'SYNTHETIC-'.Str::uuid(), 'reservation_id' => $reservation->id,
        'origin' => 'Synthetic Origin', 'destination' => 'Synthetic Destination', 'department' => 'Operations',
        'origin_latitude' => '14.6000000', 'origin_longitude' => '121.0000000',
        'destination_latitude' => '14.7000000', 'destination_longitude' => '121.1000000',
        'priority' => 'routine', 'status' => 'Draft', 'departure_date' => '2026-11-20', 'departure_time' => '09:00:00',
        'estimated_distance' => '12.35', 'estimated_time' => 16, 'optimization_strategy' => 'TomTom Traffic-Aware Optimized',
    ]);
    foreach ([1, 2] as $position) {
        $route->stops()->create(['stop_order' => $position, 'location' => 'Synthetic Repeated Stop', 'latitude' => '14.6100000', 'longitude' => '121.0100000']);
    }

    return $route->refresh()->load(['stops', 'reservation']);
}

/** @return array<string, mixed> */
function rpiPayload(RoutePlan $route, array $overrides = []): array
{
    return [...$route->only(['origin', 'destination', 'department', 'status', 'origin_latitude', 'origin_longitude',
        'destination_latitude', 'destination_longitude', 'estimated_distance', 'estimated_time', 'optimization_strategy',
        'optimization_score', 'purpose', 'notes']),
        'stops' => $route->stops->map(fn ($stop): array => $stop->only(['location', 'latitude', 'longitude']))->all(),
        'expected_estimate_revision' => $route->estimate_revision, ...$overrides];
}

/** @return array<string, string> */
function rpiState(): array
{
    $state = [];
    foreach (['route_plans', 'route_stops', 'reservations', 'audit_logs', 'dispatch', 'route_estimate_provenances'] as $table) {
        if (Schema::hasTable($table)) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
    }

    return $state;
}

function rpiEvidence(RoutePlan $route, bool $planned = false): RouteEstimateProvenance
{
    $route->forceFill(['estimate_revision' => 1])->save();
    $route->refresh()->load(['stops', 'reservation']);
    $service = new RouteEstimateProvenanceService;
    $fields = [
        'route_plan_id' => $route->id, 'reservation_id' => $route->reservation_id, 'calculation_mode' => $planned ? 'planned' : 'manual',
        'snapshot_version' => 1, 'route_snapshot' => $service->snapshot($route), 'route_snapshot_hash' => $service->fingerprint($route),
        'estimated_distance_km' => '12.35', 'estimated_duration_minutes' => 16,
        'source_route_revision' => 0, 'saved_route_revision' => 1, 'verification_status' => 'current', 'current_marker' => 1,
        'accepted_at_utc' => '2026-11-12 00:00:00.000000',
    ];
    if ($planned) {
        $fields = [...$fields,
            'calculation_uuid' => (string) Str::uuid(), 'proof_version' => 2,
            'departure_at_utc' => '2026-11-20 01:00:00', 'hospital_timezone' => 'Asia/Manila',
            'schedule_fingerprint' => hash('sha256', json_encode(['reservation_id' => $route->reservation_id, 'schedule_date' => $route->reservation->getRawOriginal('schedule_date'), 'schedule_time' => $route->reservation->getRawOriginal('schedule_time')], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            'provider' => 'TomTom', 'routing_strategy' => $route->optimization_strategy,
            'routing_options' => TomTomRoutingService::routingContext('2026-11-20T09:00:00+08:00'),
            'traffic_model_status' => 'unknown', 'verification_scope' => 'requested_context_only',
            'calculated_at_utc' => '2026-11-12 00:00:00', 'distance_meters' => '12345.67', 'duration_seconds' => '905.5',
            'proof_issued_at_utc' => '2026-11-12 00:00:00', 'proof_expires_at_utc' => '2026-11-12 00:30:00',
        ];
    }
    $record = (new RouteEstimateProvenance)->forceFill($fields);
    $record->save();

    return $record->refresh();
}

function rpiAssign(Reservation $reservation): void
{
    $vehicle = Vehicle::query()->create([
        'plate_number' => 'SYN-'.Str::upper(Str::random(7)), 'vehicle_type' => 'Service Vehicle', 'lto_vehicle_category' => 'M1',
        'department' => 'Operations', 'brand' => 'Synthetic', 'model' => 'Synthetic', 'capacity' => 4,
        'powertrain_type' => 'gasoline', 'fuel_type' => 'Gasoline', 'tank_capacity' => 50, 'current_fuel' => 35,
        'current_odometer' => 100, 'transmission_type' => 'automatic', 'insurance_expiry' => '2027-11-20',
        'status' => 'Available', 'readiness_status' => 'ready',
    ]);
    $driver = Driver::query()->create([
        'first_name' => 'Synthetic', 'last_name' => 'Driver', 'license_number' => 'SYN-'.Str::uuid(),
        'license_class' => 'Professional', 'clutch_authorization' => 'AT', 'license_expiry' => '2027-11-20',
        'contact_number' => '09000000000', 'email' => Str::uuid().'@example.invalid', 'assigned_vehicle_id' => $vehicle->id, 'status' => 'Available',
    ]);
    $driver->licenseCodes()->create(['code' => 'B']);
    DriverVehicleQualification::query()->create(['driver_id' => $driver->id, 'vehicle_type' => $vehicle->vehicle_type]);
    $reservation->update(['vehicle_id' => $vehicle->id, 'driver_id' => $driver->id]);
}

/** Rebuild only the empty table in the independently verified in-memory test connection. */
function rpiAlterProvenanceDefinition(Closure $change): void
{
    expect(DB::connection()->getDatabaseName())->toBe(':memory:')->and(RouteEstimateProvenance::query()->count())->toBe(0);
    $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE name = 'route_estimate_provenances'")->sql;
    $indexes = Schema::getIndexes('route_estimate_provenances');
    $changed = $change($sql);
    expect($changed)->not->toBe($sql);
    Schema::drop('route_estimate_provenances');
    DB::statement($changed);
    Schema::table('route_estimate_provenances', function (Blueprint $table) use ($indexes): void {
        foreach ($indexes as $index) {
            if (! $index['primary']) {
                $index['unique'] ? $table->unique($index['columns'], $index['name']) : $table->index($index['columns'], $index['name']);
            }
        }
    });
}

beforeEach(function (): void {
    expect(app()->environment())->toBe('testing')->and(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')->and(config('database.connections.sqlite.url'))->toBeFalsy();
    $this->previousConnection = DB::getDefaultConnection();
    config(['database.connections.provenance_integration' => config('database.connections.sqlite')]);
    $connection = DB::connection('provenance_integration');
    expect($connection->getDatabaseName())->toBe(':memory:')->and($connection->getPdo())->not->toBe(DB::connection()->getPdo());
    $this->artisan('migrate', ['--database' => 'provenance_integration', '--no-interaction' => true])->assertExitCode(0);
    DB::setDefaultConnection('provenance_integration');
    config(['services.route_estimates.provenance_recording_enabled' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-12T00:10:00Z'));
    $this->user = User::factory()->create(['role' => 'dispatcher']);
    $this->route = rpiRoute();
    $this->actingAs($this->user);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    $connection = DB::connection('provenance_integration');
    expect($connection->transactionLevel())->toBe(0)->and($connection->getPdo()->inTransaction())->toBeFalse();
    DB::setDefaultConnection($this->previousConnection);
    DB::purge('provenance_integration');
});

it('keeps recording and every planned capability strictly disabled by default', function (): void {
    expect((new RouteEstimateProvenanceReadinessService)->recordingEnabled())->toBeFalse()
        ->and(app(PlannedRouteProofService::class)->capabilities())->toBe(['calculation' => false, 'issuance' => false, 'apply' => false, 'save' => false]);
    config(['services.route_estimates.provenance_recording_enabled' => 'true']);
    expect((new RouteEstimateProvenanceReadinessService)->recordingEnabled())->toBeFalse();
});

it('records a manual update and audit in one committed transaction with normalized persisted identity', function (): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $response = $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, [
        'estimated_distance' => '23.456', 'estimated_time' => 30, 'origin_latitude' => '14.60000005', 'verified' => true, 'provider' => 'TomTom',
    ]))->assertSuccessful()->assertJsonPath('routePlan.estimate_revision', 1)
        ->assertJsonPath('estimateProvenance.status', 'manual_unverified')->assertJsonPath('estimateProvenance.save_eligible', false);
    $route = $this->route->fresh()->load(['stops', 'reservation']);
    $record = RouteEstimateProvenance::query()->sole();
    $audit = AuditLog::query()->sole();
    expect($route->estimated_distance)->toBe('23.46')->and($route->estimated_time)->toBe(30)->and($route->origin_latitude)->toBe('14.6000001')
        ->and($record->route_snapshot_hash)->toBe((new RouteEstimateProvenanceService)->fingerprint($route))
        ->and($record->provider)->toBeNull()->and($record->calculation_uuid)->toBeNull()->and($record->proof_version)->toBeNull()
        ->and($audit->user_id)->toBe($this->user->id)->and($audit->new_values['estimate_provenance']['saved_route_revision'])->toBe(1)
        ->and(DB::connection($this->previousConnection)->table('route_estimate_provenances')->count())->toBe(0);
    expect($response->getContent())->not->toContain('route_snapshot', 'proof_token');
});

it('preserves installed but disabled legacy saves without creating history or requiring a revision', function (): void {
    $payload = rpiPayload($this->route, ['notes' => 'Synthetic legacy notes', 'expected_estimate_revision' => 'ignored while untracked']);
    $this->putJson(route('route-planning.update', $this->route), $payload)->assertSuccessful()->assertJsonPath('estimateProvenance.status', 'unrecorded');
    expect($this->route->fresh()->notes)->toBe('Synthetic legacy notes')->and($this->route->fresh()->estimate_revision)->toBe(0)
        ->and(RouteEstimateProvenance::query()->count())->toBe(0);
});

it('preserves schema absent legacy saves without any query against the missing table', function (): void {
    Schema::drop('route_estimate_provenances');
    Schema::table('route_plans', fn (Blueprint $table) => $table->dropColumn('estimate_revision'));
    $this->route->refresh()->load(['stops', 'reservation']);
    DB::enableQueryLog();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))->assertSuccessful();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($this->route->fresh()->estimated_time)->toBe(30);
    foreach ($queries as $query) {
        expect(strtolower($query['query']))->not->toContain('from "route_estimate_provenances"', 'into "route_estimate_provenances"');
    }
});

it('fails before mutations when enabled storage is absent or schema is partial', function (string $case, bool $enabled): void {
    config(['services.route_estimates.provenance_recording_enabled' => $enabled]);
    if ($case !== 'table_only') {
        Schema::drop('route_estimate_provenances');
    }
    if ($case !== 'revision_only') {
        Schema::table('route_plans', fn (Blueprint $table) => $table->dropColumn('estimate_revision'));
    }
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))
        ->assertStatus(503)->assertJsonPath('code', 'ROUTE_PROVENANCE_SCHEMA_NOT_READY');
    expect(rpiState())->toBe($before);
})->with([['absent', true], ['table_only', true], ['revision_only', true], ['table_only', false], ['revision_only', false]]);

it('fails closed on missing or incorrectly defined notification-independent provenance indexes', function (string $name): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    DB::statement('DROP INDEX '.$name);
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))->assertStatus(503);
    expect(rpiState())->toBe($before);
})->with(['rep_current_uq', 'rep_calculation_uq', 'rep_route_history_idx', 'rep_reservation_idx', 'rep_actor_idx']);

it('rejects weakened named checks wrong column types missing primary keys and unsafe foreign keys', function (string $case): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    rpiAlterProvenanceDefinition(function (string $sql) use ($case): string {
        if ($case === 'grouping') {
            $checks = (new ReflectionMethod(RouteEstimateProvenanceReadinessService::class, 'expectedChecks'))->invoke(new RouteEstimateProvenanceReadinessService);

            return str_replace('CONSTRAINT rep_metrics_ck CHECK ('.$checks['rep_metrics_ck'].')', 'CONSTRAINT rep_metrics_ck CHECK ('.str_replace(['(', ')'], '', $checks['rep_metrics_ck']).')', $sql);
        }

        return match ($case) {
            'literal' => str_replace("provider = 'TomTom'", "provider = 'tomtom'", $sql),
            'json_type' => str_replace('"route_snapshot" text', '"route_snapshot" integer', $sql),
            'date_type' => str_replace('"accepted_at_utc" datetime', '"accepted_at_utc" text', $sql),
            'string_type' => str_replace('"provider" varchar', '"provider" integer', $sql),
            'primary' => str_replace('"id" integer primary key autoincrement not null', '"id" integer not null', $sql),
            'foreign' => str_replace('on delete restrict', 'on delete cascade', $sql),
        };
    });
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))->assertStatus(503)->assertJsonPath('code', 'ROUTE_PROVENANCE_SCHEMA_NOT_READY');
    expect(rpiState())->toBe($before);
})->with(['grouping', 'literal', 'json_type', 'date_type', 'string_type', 'primary', 'foreign']);

it('requires a real observed revision and never substitutes the freshly loaded revision', function (mixed $revision, int $status, string $code): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['expected_estimate_revision' => $revision, 'estimated_time' => 30]))
        ->assertStatus($status)->assertJsonPath('code', $code);
    expect(rpiState())->toBe($before);
})->with([[null, 422, 'ROUTE_ESTIMATE_REVISION_REQUIRED'], [false, 422, 'ROUTE_ESTIMATE_REVISION_REQUIRED'], ['1.2', 422, 'ROUTE_ESTIMATE_REVISION_REQUIRED'], [-1, 422, 'ROUTE_ESTIMATE_REVISION_REQUIRED'], [1, 409, 'ROUTE_ESTIMATE_REVISION_STALE']]);

it('rejects the second sequential save from one observed base without partial updates', function (): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $payload = rpiPayload($this->route, ['estimated_time' => 30]);
    $this->putJson(route('route-planning.update', $this->route), $payload)->assertSuccessful();
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), [...$payload, 'estimated_time' => 99])->assertConflict()->assertJsonPath('code', 'ROUTE_ESTIMATE_REVISION_STALE');
    expect(rpiState())->toBe($before)->and(RouteEstimateProvenance::query()->count())->toBe(1);
});

it('preserves notes-only and status-only evidence without revision churn', function (bool $statusOnly): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $record = rpiEvidence($this->route, true);
    $before = $record->getAttributes();
    $endpoint = $statusOnly ? 'route-planning.transition' : 'route-planning.update';
    $payload = rpiPayload($this->route, $statusOnly ? ['status' => 'Planned'] : ['notes' => 'Synthetic notes-only correction']);
    $response = $statusOnly ? $this->postJson(route($endpoint, $this->route), $payload) : $this->putJson(route($endpoint, $this->route), $payload);
    $response->assertSuccessful()->assertJsonPath('routePlan.estimate_revision', 1)->assertJsonPath('estimateProvenance.status', 'current_planned');
    expect($record->fresh()->getAttributes())->toBe($before)->and(RouteEstimateProvenance::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(1);
})->with([true, false]);

/** All gates are temporary test-only configuration; defaults remain OFF. */
function rpiEnablePlanned(): void
{
    foreach (['planned_departure_enabled', 'planned_proof_issuance_enabled', 'planned_apply_enabled', 'planned_save_enabled'] as $gate) {
        config(['services.tomtom.'.$gate => true]);
    }
    config(['services.route_estimates.provenance_recording_enabled' => true, 'services.tomtom.key' => 'synthetic-not-a-key']);
}

/** Exercise the actual issuer and provider adapter with deterministic HTTP fakes. */
function rpiPlannedPayload(TestCase $test, RoutePlan $route, string $target = 'Draft', string $operation = 'transition'): array
{
    $coordinates = [
        ['latitude' => 14.6, 'longitude' => 121.0],
        ['latitude' => 14.61, 'longitude' => 121.01],
        ['latitude' => 14.61, 'longitude' => 121.01],
        ['latitude' => 14.7, 'longitude' => 121.1],
    ];
    Http::fake(['api.tomtom.com/*' => Http::response([
        'routes' => [['summary' => ['lengthInMeters' => 12345.67, 'travelTimeInSeconds' => 905.5], 'legs' => [['points' => $coordinates]]]],
        'optimizedWaypoints' => [['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 0]],
    ])]);
    $issued = $test->postJson(route('route-planning.traffic-route'), [
        'departure_mode' => 'planned', 'coordinates' => $coordinates, 'reservation_id' => $route->reservation_id,
        'route_id' => $route->id, 'expected_estimate_revision' => $route->estimate_revision, 'intended_operation' => $operation,
        'target_status' => $target, 'stop_labels' => ['Synthetic Repeated Stop', 'Synthetic Repeated Stop'],
    ])->assertSuccessful()->assertJsonPath('save_eligible', true)->assertJsonPath('apply_eligible', true);

    return rpiPayload($route, ['status' => $target, 'departure_mode' => 'planned', 'planned_stop_order' => [1, 0],
        'optimization_applied' => true, 'optimization_proof' => $issued->json('optimization_proof')]);
}

it('issues and consumes a revision bound planned proof with repeated stop occurrences and durable audit', function (): void {
    rpiEnablePlanned();
    $payload = rpiPlannedPayload($this, $this->route);
    Http::assertSentCount(1);
    Http::recorded()->each(fn ($pair) => expect($pair[0]['departAt'])->toBe('2026-11-20T09:00:00+08:00'));
    $response = $this->postJson(route('route-planning.transition', $this->route), $payload)
        ->assertSuccessful()->assertJsonPath('routePlan.estimate_revision', 1)->assertJsonPath('estimateProvenance.status', 'current_planned');
    $evidence = RouteEstimateProvenance::query()->sole();
    expect($evidence->calculation_mode)->toBe('planned')->and($evidence->provider)->toBe('TomTom')
        ->and($evidence->verification_scope)->toBe('requested_context_only')->and($evidence->traffic_model_status)->toBe('unknown')
        ->and($evidence->distance_meters)->toBe('12345.670000')->and($evidence->duration_seconds)->toBe('905.500000')
        ->and($evidence->source_route_revision)->toBe(0)->and($evidence->saved_route_revision)->toBe(1)
        ->and($this->route->stops()->count())->toBe(2)->and(AuditLog::query()->sole()->new_values['estimate_provenance']['provenance_id'])->toBe($evidence->id);
    $before = rpiState();
    $this->postJson(route('route-planning.transition', $this->route), $payload)->assertStatus(409);
    expect(rpiState())->toBe($before);
    Http::assertSentCount(1);
    $this->getJson(route('route-planning.show', $this->route))->assertSuccessful()->assertJsonPath('estimateProvenance.status', 'current_planned');
    expect($response->getContent())->not->toContain('proof_token', 'route_snapshot');
});

it('consumes a fresh calculation even when the saved planned values do not change', function (): void {
    rpiEnablePlanned();
    $first = rpiPlannedPayload($this, $this->route);
    $this->postJson(route('route-planning.transition', $this->route), $first)->assertSuccessful();
    $old = RouteEstimateProvenance::query()->sole();
    $second = rpiPlannedPayload($this, $this->route->fresh()->load(['stops', 'reservation']));
    $this->postJson(route('route-planning.transition', $this->route), $second)->assertSuccessful()->assertJsonPath('routePlan.estimate_revision', 2);
    $records = RouteEstimateProvenance::query()->orderBy('id')->get();
    expect($records)->toHaveCount(2)->and($old->fresh()->verification_status)->toBe('superseded')
        ->and($old->fresh()->current_marker)->toBeNull()->and($records[1]->current_marker)->toBe(1)
        ->and($records[1]->route_snapshot_hash)->toBe($old->route_snapshot_hash)
        ->and($records[1]->calculation_uuid)->not->toBe($old->calculation_uuid)->and(AuditLog::count())->toBe(2);
});

it('accepts a proof bound specifically to an ordinary planned update endpoint', function (): void {
    rpiEnablePlanned();
    $payload = rpiPlannedPayload($this, $this->route, 'Draft', 'update');
    $payload['optimization_applied'] = false;
    $this->putJson(route('route-planning.update', $this->route), $payload)->assertSuccessful()
        ->assertJsonPath('estimateProvenance.status', 'current_planned')->assertJsonPath('routePlan.estimate_revision', 1);
});

it('checks changed schema again at planned Save and leaves all records untouched', function (string $missing): void {
    rpiEnablePlanned();
    $payload = rpiPlannedPayload($this, $this->route);
    if ($missing === 'table') {
        Schema::drop('route_estimate_provenances');
    } elseif ($missing === 'revision') {
        Schema::table('route_plans', fn (Blueprint $table) => $table->dropColumn('estimate_revision'));
    } else {
        Schema::table('route_estimate_provenances', fn (Blueprint $table) => $table->dropUnique('rep_calculation_uq'));
    }
    $before = rpiState();
    $this->postJson(route('route-planning.transition', $this->route), $payload)->assertStatus(503)
        ->assertJsonPath('code', 'ROUTE_PROVENANCE_SCHEMA_NOT_READY');
    expect(rpiState())->toBe($before);
    Http::assertSentCount(1);
})->with(['table', 'revision', 'uuid_index']);

it('rejects unauthorized issuance before provider access or response evidence', function (): void {
    rpiEnablePlanned();
    $this->actingAs(User::factory()->create(['role' => 'it_admin']));
    $before = rpiState();
    $this->postJson(route('route-planning.traffic-route'), ['departure_mode' => 'planned',
        'coordinates' => [['latitude' => 14.6, 'longitude' => 121.0], ['latitude' => 14.7, 'longitude' => 121.1]],
        'reservation_id' => $this->route->reservation_id, 'route_id' => $this->route->id, 'expected_estimate_revision' => 0,
        'intended_operation' => 'transition', 'target_status' => 'Draft', 'stop_labels' => [],
    ])->assertForbidden()->assertJsonMissingPath('optimization_proof');
    expect(rpiState())->toBe($before);
    Http::assertNothingSent();
});

it('preserves emergency assignment and readiness rules for authenticated planned saving', function (): void {
    rpiEnablePlanned();
    $this->route->reservation->update(['priority' => 'emergency', 'request_type' => 'emergency_response']);
    rpiAssign($this->route->reservation);
    $payload = rpiPlannedPayload($this, $this->route, 'Planned');
    $this->postJson(route('route-planning.transition', $this->route), $payload)->assertSuccessful()
        ->assertJsonPath('routePlan.status', 'Ready For Dispatch')->assertJsonPath('estimateProvenance.status', 'current_planned');
    expect(Dispatch::count())->toBe(0)->and($this->route->reservation->fresh()->status)->toBe('Approved');
});

it('does not issue planned proofs for invalid provider responses', function (string $case): void {
    rpiEnablePlanned();
    $coordinates = [['latitude' => 14.6, 'longitude' => 121.0], ['latitude' => 14.61, 'longitude' => 121.01],
        ['latitude' => 14.61, 'longitude' => 121.01], ['latitude' => 14.7, 'longitude' => 121.1]];
    $result = ['provider' => 'TomTom', 'distance_meters' => 12000, 'travel_time_seconds' => 900,
        'traffic_delay_seconds' => null, 'traffic_length_meters' => null, 'no_traffic_travel_time_seconds' => null,
        'historic_traffic_travel_time_seconds' => null, 'live_traffic_travel_time_seconds' => null,
        'departure_time' => null, 'arrival_time' => null, 'points' => $coordinates,
        'optimized_waypoints' => [['providedIndex' => 0, 'optimizedIndex' => 0], ['providedIndex' => 1, 'optimizedIndex' => 1]],
        'routing_context' => TomTomRoutingService::routingContext('2026-11-20T09:00:00+08:00'),
        'request_coordinates' => $coordinates, 'waypoint_order_optimized' => true,
        'comparison_metrics' => ['distance_meters' => 12000, 'travel_time_seconds' => 900],
        'calculation_started_at' => now()->toISOString(), 'calculated_at' => now()->toISOString()];
    match ($case) {
        'fallback' => $result['provider'] = 'OSRM',
        'mapping' => $result['optimized_waypoints'] = [],
        'geometry' => $result['points'] = [],
        'metrics' => $result['comparison_metrics']['distance_meters'] = null,
    };
    $provider = Mockery::mock(TomTomRoutingService::class);
    $provider->shouldReceive('calculateRoute')->once()->andReturnUsing(function () use ($result): array {
        expect(DB::transactionLevel())->toBe(0);

        return $result;
    });
    app()->instance(TomTomRoutingService::class, $provider);
    $before = rpiState();
    $this->postJson(route('route-planning.traffic-route'), [
        'departure_mode' => 'planned', 'coordinates' => $coordinates, 'route_id' => $this->route->id,
        'reservation_id' => $this->route->reservation_id, 'expected_estimate_revision' => 0,
        'intended_operation' => 'transition', 'target_status' => 'Draft', 'stop_labels' => ['Synthetic Repeated Stop', 'Synthetic Repeated Stop'],
    ])->assertStatus(502)->assertJsonMissingPath('optimization_proof')->assertJsonPath('success', false);
    expect(rpiState())->toBe($before);
    Http::assertNothingSent();
})->with(['fallback', 'mapping', 'geometry', 'metrics']);

it('rejects altered planned submissions without any persistent partial writes', function (string $change): void {
    rpiEnablePlanned();
    $payload = rpiPlannedPayload($this, $this->route);
    match ($change) {
        'revision' => $this->route->forceFill(['estimate_revision' => 1])->save(),
        'schedule' => $this->route->reservation->update(['schedule_time' => '10:00:00']),
        'expired' => $this->travel(31)->minutes(),
        'tampered' => $payload['optimization_proof'] = substr($payload['optimization_proof'], 0, -8).'tampered',
        'missing' => $payload['optimization_proof'] = null,
        'coordinates' => $payload['stops'][0]['latitude'] = 14.69,
        'labels' => $payload['stops'][0]['location'] = 'Changed synthetic stop',
        'order' => $payload['planned_stop_order'] = [0, 1],
        'metrics' => $payload['estimated_distance'] = 15,
        'operation' => null,
        'status' => $payload['status'] = 'Planned',
        'actor' => $this->actingAs(User::factory()->create(['role' => 'fleet_manager'])),
        'rbac' => $this->actingAs(User::factory()->create(['role' => 'it_admin'])),
        'route_state' => $this->route->update(['status' => 'Ready For Dispatch']),
        'eligible_state' => $this->route->update(['status' => 'Planned']),
        default => throw new RuntimeException('Unknown test variation'),
    };
    if ($change === 'expired') {
        $this->withSession(['last_user_activity' => now()->timestamp]);
    }
    $before = rpiState();
    $response = $change === 'operation'
        ? $this->putJson(route('route-planning.update', $this->route), $payload)
        : $this->postJson(route('route-planning.transition', $this->route), $payload);
    expect($response->status())->toBeIn([403, 409, 422]);
    expect(rpiState())->toBe($before);
    Http::assertSentCount(1);
})->with(['revision', 'schedule', 'expired', 'tampered', 'missing', 'coordinates', 'labels', 'order', 'metrics', 'operation', 'status', 'actor', 'rbac', 'route_state', 'eligible_state']);

it('requires every capability and recording gate at planned acceptance', function (string $gate): void {
    rpiEnablePlanned();
    $payload = rpiPlannedPayload($this, $this->route);
    config([$gate => false]);
    $before = rpiState();
    $this->postJson(route('route-planning.transition', $this->route), $payload)->assertStatus(422);
    expect(rpiState())->toBe($before);
})->with(['services.tomtom.planned_departure_enabled', 'services.tomtom.planned_proof_issuance_enabled',
    'services.tomtom.planned_apply_enabled', 'services.tomtom.planned_save_enabled', 'services.route_estimates.provenance_recording_enabled']);

it('rolls back planned route stops revision evidence and audit as one unit', function (string $failure): void {
    rpiEnablePlanned();
    $payload = rpiPlannedPayload($this, $this->route);
    $before = rpiState();
    $model = $failure === 'audit' ? AuditLog::class : RouteEstimateProvenance::class;
    $model::creating(fn () => throw new RuntimeException('Synthetic planned insertion failure'));
    try {
        $this->postJson(route('route-planning.transition', $this->route), $payload)->assertStatus(422);
    } finally {
        $model::flushEventListeners();
    }
    expect(rpiState())->toBe($before);
})->with(['audit', 'provenance']);

it('rejects missing planned schema before issuing proofs or changing a route', function (string $missing): void {
    rpiEnablePlanned();
    if ($missing === 'table') {
        Schema::drop('route_estimate_provenances');
    } else {
        Schema::table('route_plans', fn (Blueprint $table) => $table->dropColumn('estimate_revision'));
    }
    $before = rpiState();
    $this->postJson(route('route-planning.traffic-route'), ['departure_mode' => 'planned',
        'reservation_id' => $this->route->reservation_id, 'route_id' => $this->route->id, 'expected_estimate_revision' => 0,
        'intended_operation' => 'transition', 'target_status' => 'Draft', 'stop_labels' => [],
        'coordinates' => [['latitude' => 14.6, 'longitude' => 121.0], ['latitude' => 14.7, 'longitude' => 121.1]],
    ])->assertStatus(503)->assertJsonMissingPath('optimization_proof');
    expect(rpiState())->toBe($before);
    Http::assertNothingSent();
})->with(['table', 'revision']);

it('retains protection after recording is disabled without issuing replacement evidence', function (): void {
    $record = rpiEvidence($this->route, true);
    $hash = $record->route_snapshot_hash;
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))
        ->assertSuccessful()->assertJsonPath('routePlan.estimate_revision', 2)->assertJsonPath('estimateProvenance.status', 'unrecorded');
    expect($record->fresh()->verification_status)->toBe('invalidated')->and($record->fresh()->current_marker)->toBeNull()
        ->and($record->fresh()->route_snapshot_hash)->toBe($hash)->and(RouteEstimateProvenance::query()->count())->toBe(1);
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['expected_estimate_revision' => null, 'estimated_time' => 99]))->assertUnprocessable();
    expect(rpiState())->toBe($before);
});

it('binds reordered repeated occurrences and supersedes prior manual evidence', function (): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $old = rpiEvidence($this->route);
    $stops = [
        ['location' => 'Synthetic third', 'latitude' => '14.6100000', 'longitude' => '121.0100000'],
        ['location' => 'Synthetic repeated', 'latitude' => '14.62000001', 'longitude' => '121.02000001'],
        ['location' => 'Synthetic repeated', 'latitude' => '14.6100000', 'longitude' => '121.0100000'],
    ];
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['stops' => $stops]))->assertSuccessful();
    $route = $this->route->fresh()->load(['stops', 'reservation']);
    expect($old->fresh()->verification_status)->toBe('superseded')->and($old->fresh()->current_marker)->toBeNull()
        ->and($route->estimate_revision)->toBe(2)->and($route->stops->pluck('location')->all())->toBe(array_column($stops, 'location'))
        ->and($route->stops->pluck('latitude')->all())->toBe(['14.6100000', '14.6200000', '14.6100000'])
        ->and(RouteEstimateProvenance::query()->where('current_marker', 1)->sole()->route_snapshot_hash)->toBe((new RouteEstimateProvenanceService)->fingerprint($route));
});

it('archives and restores atomically without reviving historical verification', function (bool $enabled): void {
    config(['services.route_estimates.provenance_recording_enabled' => $enabled]);
    $old = rpiEvidence($this->route, true);
    $this->postJson(route('route-planning.archive', $this->route), ['expected_estimate_revision' => 1])
        ->assertSuccessful()->assertJsonPath('routePlan.status', 'Archived')->assertJsonPath('routePlan.estimate_revision', 2);
    expect($old->fresh()->verification_status)->toBe('invalidated')->and($old->fresh()->end_reason)->toBe('route_archived')
        ->and($old->fresh()->current_marker)->toBeNull();
    $beforeRestore = $old->fresh()->getAttributes();
    $this->postJson(route('route-planning.restore', $this->route), ['expected_estimate_revision' => 2])
        ->assertSuccessful()->assertJsonPath('routePlan.status', 'Draft')->assertJsonPath('routePlan.estimate_revision', 3)
        ->assertJsonPath('estimateProvenance.status', 'unrecorded');
    expect($old->fresh()->getAttributes())->toBe($beforeRestore)->and(RouteEstimateProvenance::query()->count())->toBe(1)
        ->and(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe(['Archived', 'Restored']);
})->with([true, false]);

it('preserves unrecorded legacy archive restore behavior with the gate off', function (): void {
    $this->postJson(route('route-planning.archive', $this->route))->assertSuccessful();
    $this->postJson(route('route-planning.restore', $this->route))->assertSuccessful()->assertJsonPath('routePlan.status', 'Draft');
    expect($this->route->fresh()->estimate_revision)->toBe(0)->and(RouteEstimateProvenance::query()->count())->toBe(0);
});

it('rejects corrupt current evidence and orphaned archived current evidence without erasing history', function (bool $orphan): void {
    $old = rpiEvidence($this->route, true);
    if ($orphan) {
        $this->route->update(['status' => 'Archived']);
    } else {
        $old->forceFill(['route_snapshot_hash' => str_repeat('0', 64)])->save();
    }
    $before = rpiState();
    $response = $orphan
        ? $this->postJson(route('route-planning.restore', $this->route), ['expected_estimate_revision' => 1])
        : $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]));
    $response->assertUnprocessable();
    expect(rpiState())->toBe($before);
})->with([true, false]);

it('rolls back routes stops revisions history and audit on failure at every persistence stage', function (string $stage): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    rpiEvidence($this->route, true);
    $trigger = match ($stage) {
        'revision' => "CREATE TRIGGER rpi_failure BEFORE UPDATE OF estimate_revision ON route_plans BEGIN SELECT RAISE(ABORT, 'synthetic revision failure'); END",
        'closure' => "CREATE TRIGGER rpi_failure BEFORE UPDATE OF current_marker ON route_estimate_provenances BEGIN SELECT RAISE(ABORT, 'synthetic closure failure'); END",
        'insertion' => "CREATE TRIGGER rpi_failure BEFORE INSERT ON route_estimate_provenances BEGIN SELECT RAISE(ABORT, 'synthetic insertion failure'); END",
        'audit' => "CREATE TRIGGER rpi_failure BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END",
        'route_ignored' => 'CREATE TRIGGER rpi_failure BEFORE UPDATE OF estimated_time ON route_plans BEGIN SELECT RAISE(IGNORE); END',
        'stop_ignored' => 'CREATE TRIGGER rpi_failure BEFORE INSERT ON route_stops WHEN NEW.stop_order = 2 BEGIN SELECT RAISE(IGNORE); END',
    };
    DB::statement($trigger);
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))->assertUnprocessable();
    expect(rpiState())->toBe($before);
})->with(['revision', 'closure', 'insertion', 'audit', 'route_ignored', 'stop_ignored']);

it('rolls back archive and restore if their audit cannot be written', function (bool $restore): void {
    rpiEvidence($this->route, true);
    if ($restore) {
        $this->postJson(route('route-planning.archive', $this->route), ['expected_estimate_revision' => 1])->assertSuccessful();
    }
    DB::statement("CREATE TRIGGER rpi_audit_failure BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'synthetic audit failure'); END");
    $before = rpiState();
    $this->postJson(route($restore ? 'route-planning.restore' : 'route-planning.archive', $this->route), ['expected_estimate_revision' => $restore ? 2 : 1])->assertUnprocessable();
    expect(rpiState())->toBe($before);
})->with([true, false]);

it('preserves ordinary Ready For Dispatch edits and blocks Completed or dispatched routes', function (string $case): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    if ($case === 'dispatch') {
        Dispatch::query()->create(['dispatch_number' => 'SYN-'.Str::uuid(), 'reservation_id' => $this->route->reservation_id, 'dispatch_date' => '2026-11-20', 'departure_time' => '09:00:00', 'trip_status' => 'Pending']);
    } else {
        $this->route->update(['status' => $case]);
    }
    $before = rpiState();
    $response = $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]));
    if ($case === 'Ready For Dispatch') {
        $response->assertSuccessful()->assertJsonPath('routePlan.status', 'Ready For Dispatch')->assertJsonPath('estimateProvenance.status', 'manual_unverified');
    } else {
        $response->assertUnprocessable();
        expect(rpiState())->toBe($before);
    }
})->with(['Ready For Dispatch', 'Completed', 'dispatch']);

it('does not weaken authorization for updates archive or restore', function (string $event): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $this->actingAs(User::factory()->create(['role' => 'it_admin']));
    $before = rpiState();
    $response = $event === 'update'
        ? $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))
        : $this->postJson(route('route-planning.'.$event, $this->route), ['expected_estimate_revision' => 0]);
    $response->assertForbidden();
    expect(rpiState())->toBe($before);
})->with(['update', 'archive', 'restore']);

it('keeps planned Save rejected without any route mutation or provider calls', function (): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $before = rpiState();
    $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['departure_mode' => 'planned', 'estimated_time' => 30]))
        ->assertUnprocessable()->assertJsonPath('code', 'ROUTE_PLANNED_MODE_DISABLED');
    expect(rpiState())->toBe($before);
    Http::assertNothingSent();
});

it('preserves emergency readiness only after an actual backend-issued legacy proof is saved', function (bool $recording): void {
    config(['services.route_estimates.provenance_recording_enabled' => $recording]);
    $reservation = $this->route->reservation;
    $reservation->update(['priority' => 'emergency', 'request_type' => 'emergency_response']);
    rpiAssign($reservation);
    $tomTom = Mockery::mock(TomTomRoutingService::class);
    $tomTom->shouldReceive('calculateRoute')->once()->andReturn([
        'provider' => 'tomtom', 'distance_meters' => 12500, 'travel_time_seconds' => 1680,
        'traffic_delay_seconds' => 120, 'traffic_length_meters' => 500,
        'no_traffic_travel_time_seconds' => 1560, 'historic_traffic_travel_time_seconds' => 1620, 'live_traffic_travel_time_seconds' => 1680,
        'departure_time' => now()->toIso8601String(), 'arrival_time' => now()->addMinutes(28)->toIso8601String(),
        'optimized_waypoints' => [], 'instructions' => [],
        'points' => [['latitude' => 14.6, 'longitude' => 121.0], ['latitude' => 14.7, 'longitude' => 121.1]],
    ]);
    app()->instance(TomTomRoutingService::class, $tomTom);
    $optimization = $this->postJson(route('route-planning.traffic-route'), [
        'reservation_id' => $reservation->id,
        'coordinates' => [['latitude' => 14.6, 'longitude' => 121.0], ['latitude' => 14.7, 'longitude' => 121.1]],
    ])->assertSuccessful();
    $this->postJson(route('route-planning.transition', $this->route), rpiPayload($this->route, [
        'status' => 'Planned', 'estimated_distance' => 12.5, 'estimated_time' => 28,
        'optimization_applied' => true, 'optimization_strategy' => 'TomTom Traffic-Aware Fastest',
        'optimization_proof' => $optimization->json('optimization_proof'), 'stops' => [],
    ]))->assertSuccessful()->assertJsonPath('routePlan.status', 'Ready For Dispatch');
    expect(Dispatch::query()->count())->toBe(0)->and($reservation->fresh()->status)->toBe('Approved')
        ->and($reservation->fresh()->vehicle_id)->toBe($reservation->vehicle_id)->and($reservation->fresh()->driver_id)->toBe($reservation->driver_id)
        ->and(RouteEstimateProvenance::query()->count())->toBe($recording ? 1 : 0);
    if ($recording) {
        expect(RouteEstimateProvenance::query()->sole()->calculation_mode)->toBe('manual')
            ->and(RouteEstimateProvenance::query()->sole()->provider)->toBeNull();
    }
})->with([true, false]);

it('rejects missing emergency proofs without changing readiness route or provenance', function (): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $this->route->reservation->update(['priority' => 'emergency']);
    rpiAssign($this->route->reservation);
    $before = rpiState();
    $this->postJson(route('route-planning.transition', $this->route), rpiPayload($this->route, [
        'status' => 'Planned', 'optimization_applied' => true, 'optimization_proof' => null,
    ]))->assertUnprocessable();
    expect(rpiState())->toBe($before);
    Http::assertNothingSent();
});

it('composes within a managed outer transaction without committing it independently', function (): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    $before = rpiState();
    DB::beginTransaction();
    try {
        $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))->assertSuccessful();
        expect(DB::transactionLevel())->toBe(1)->and(RouteEstimateProvenance::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(1);
    } finally {
        DB::rollBack();
    }
    expect(rpiState())->toBe($before);
});

it('rejects unmanaged PDO transactions without attempting an independent commit', function (): void {
    $before = rpiState();
    DB::connection()->getPdo()->beginTransaction();
    try {
        $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30]))->assertUnprocessable();
        expect(DB::connection()->getPdo()->inTransaction())->toBeTrue();
    } finally {
        DB::connection()->getPdo()->rollBack();
    }
    expect(rpiState())->toBe($before);
});

it('rolls back callbacks that alter saved fields or current evidence during audit', function (string $field): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    rpiEvidence($this->route, true);
    $before = rpiState();
    $service = new RouteEstimateProvenanceService;
    $audit = function ($connection, RoutePlan $old, RoutePlan $after, array $context) use ($field): AuditLog {
        $record = AuditLog::on($connection->getName())->create([
            'user_id' => $this->user->id, 'module' => 'Route Planning', 'action' => 'Updated', 'description' => 'Synthetic callback regression',
            'auditable_type' => RoutePlan::class, 'auditable_id' => $after->id,
        ]);
        if ($field === 'evidence') {
            RouteEstimateProvenance::query()->where('current_marker', 1)->update(['route_snapshot_hash' => str_repeat('0', 64)]);
        } else {
            $after->update([$field => $field === 'optimization_score' ? 1 : 'Synthetic unexpected audit mutation']);
        }

        return $record;
    };
    expect(fn () => $service->executeRouteOperation($this->route->id, 'update', 1, $this->user->id, fn () => true,
        fn () => null, $audit))->toThrow(InvalidArgumentException::class);
    expect(rpiState())->toBe($before);
})->with(['evidence', 'notes', 'purpose', 'department', 'optimization_score']);

it('keeps failed Save responses and all protected state unchanged while emitting sanitized diagnostics', function (string $operation, bool $loggerFails): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    rpiEvidence($this->route, true);
    $reservation = null;
    if (in_array($operation, ['create', 'duplicate'], true)) {
        $target = rpiRoute();
        $reservation = $target->reservation;
        $target->stops()->delete();
        $target->delete();
        rpiAssign($reservation);
    } elseif ($operation === 'restore') {
        $this->postJson(route('route-planning.archive', $this->route), ['expected_estimate_revision' => 1])->assertSuccessful();
    }
    DB::statement("CREATE TRIGGER rpi_monitor_failure BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'seeded-private-proof-location'); END");
    $before = rpiState();
    $events = [];
    Log::shouldReceive('log')->once()->andReturnUsing(function (string $level, string $event, array $context) use (&$events, $loggerFails): void {
        $events[] = compact('level', 'event', 'context');
        if ($loggerFails) {
            throw new RuntimeException('Synthetic logger transport failure');
        }
    });
    $response = match ($operation) {
        'create' => $this->postJson(route('route-planning.store'), ['reservation_id' => $reservation->id, 'department' => 'Operations']),
        'duplicate' => $this->postJson(route('route-planning.duplicate', $this->route), ['reservation_id' => $reservation->id]),
        'archive', 'restore' => $this->postJson(route('route-planning.'.$operation, $this->route), ['expected_estimate_revision' => $operation === 'restore' ? 2 : 1]),
        default => $this->putJson(route('route-planning.update', $this->route), rpiPayload($this->route, ['estimated_time' => 30])),
    };
    $response->assertUnprocessable()->assertExactJson(['success' => false, 'message' => 'Route changes could not be saved.']);
    expect($events)->toHaveCount(1)->and($events[0]['level'])->toBe('warning')->and($events[0]['event'])->toBe('fleet.route_save.failed')
        ->and($events[0]['context']['operation'])->toBe($operation)->and(Str::isUuid($events[0]['context']['correlation_id']))->toBeTrue()
        ->and(json_encode($events))->not->toContain('seeded', 'Synthetic', 'proof', 'binding', 'patient', 'exception');
    expect(rpiState())->toBe($before)->and(DB::transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    Http::assertNothingSent();
})->with(['create', 'duplicate', 'update', 'archive', 'restore'])->with([false, true]);

it('preserves creation and duplication as unrecorded draft routes without copying history', function (bool $duplicate): void {
    config(['services.route_estimates.provenance_recording_enabled' => true]);
    rpiEvidence($this->route, true);
    $target = rpiRoute();
    $reservation = $target->reservation;
    $target->stops()->delete();
    $target->delete();
    rpiAssign($reservation);
    $response = $duplicate
        ? $this->postJson(route('route-planning.duplicate', $this->route), ['reservation_id' => $reservation->id])
        : $this->postJson(route('route-planning.store'), ['reservation_id' => $reservation->id, 'department' => 'Operations']);
    $response->assertCreated()->assertJsonPath('routePlan.status', 'Draft');
    $copy = RoutePlan::query()->findOrFail($response->json('routePlan.id'));
    expect($copy->estimate_revision)->toBe(0)->and(RouteEstimateProvenance::query()->where('route_plan_id', $copy->id)->exists())->toBeFalse()
        ->and(RouteEstimateProvenance::query()->where('route_plan_id', $this->route->id)->sole()->verification_status)->toBe('current');
})->with([true, false]);
