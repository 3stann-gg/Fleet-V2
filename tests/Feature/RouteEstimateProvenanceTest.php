<?php

use App\Models\AuditLog;
use App\Models\Dispatch;
use App\Models\Reservation;
use App\Models\RouteEstimateProvenance;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\PlannedRouteProofService;
use App\Services\RouteEstimateProvenanceService;
use App\Services\TomTomRoutingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function repModelRoute(?string $connectionName = null): RoutePlan
{
    $reservation = Reservation::on($connectionName)->create([
        'reservation_number' => 'SYNTHETIC-PROVENANCE-'.Str::uuid(), 'patient_name' => null,
        'request_type' => 'Staff Transport', 'pickup_location' => 'Synthetic Origin', 'destination' => 'Synthetic Destination',
        'schedule_date' => '2026-11-20', 'schedule_time' => '09:00:00', 'priority' => 'Normal', 'status' => 'Approved',
    ]);
    $route = RoutePlan::on($connectionName)->create([
        'route_number' => 'SYNTHETIC-'.Str::uuid(), 'reservation_id' => $reservation->id,
        'origin' => 'Synthetic Origin', 'destination' => 'Synthetic Destination',
        'origin_latitude' => '14.6000000', 'origin_longitude' => '121.0000000',
        'destination_latitude' => '14.7000000', 'destination_longitude' => '121.1000000',
        'department' => 'Operations', 'priority' => 'routine', 'status' => 'Draft',
        'departure_date' => '2026-11-20', 'departure_time' => '09:00:00',
        'estimated_distance' => '12.35', 'estimated_time' => 16, 'optimization_strategy' => 'TomTom Traffic-Aware Optimized',
    ]);
    foreach ([1, 2] as $position) {
        $route->stops()->create(['stop_order' => $position, 'location' => 'Synthetic Repeated Stop', 'latitude' => '14.6100000', 'longitude' => '121.0100000']);
    }
    $route->forceFill(['estimate_revision' => 1])->save();

    return $route->refresh()->load(['stops', 'reservation']);
}

/** Fixtures model already-accepted server evidence; there is deliberately no production writer. */
function repModelRecord(RoutePlan $route, bool $planned = true, array $overrides = []): RouteEstimateProvenance
{
    $service = new RouteEstimateProvenanceService;
    $record = [
        'route_plan_id' => $route->id, 'reservation_id' => $route->reservation_id,
        'calculation_mode' => $planned ? 'planned' : 'manual', 'snapshot_version' => 1,
        'estimated_distance_km' => '12.35', 'estimated_duration_minutes' => 16,
        'route_snapshot' => $service->snapshot($route), 'route_snapshot_hash' => $service->fingerprint($route),
        'source_route_revision' => 0, 'saved_route_revision' => 1, 'verification_status' => 'current', 'current_marker' => 1,
        'accepted_at_utc' => '2026-11-12 00:00:00.000000',
        'created_at' => '2026-11-12 00:00:00.000000', 'updated_at' => '2026-11-12 00:00:00.000000',
    ];
    if ($planned) {
        $record = [...$record,
            'calculation_uuid' => (string) Str::uuid(), 'proof_version' => 2,
            'departure_at_utc' => '2026-11-20 01:00:00.000000', 'hospital_timezone' => 'Asia/Manila',
            'schedule_fingerprint' => hash('sha256', json_encode(['reservation_id' => $route->reservation_id, 'schedule_date' => $route->reservation->getAttributes()['schedule_date'], 'schedule_time' => $route->reservation->getAttributes()['schedule_time']], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
            'provider' => 'TomTom', 'routing_strategy' => 'TomTom Traffic-Aware Optimized',
            'routing_options' => TomTomRoutingService::routingContext('2026-11-20T09:00:00+08:00'),
            'traffic_model_status' => 'unknown', 'verification_scope' => 'requested_context_only',
            'calculated_at_utc' => '2026-11-12 00:00:00.000000', 'distance_meters' => '12345.670000', 'duration_seconds' => '905.500000',
            'proof_issued_at_utc' => '2026-11-12 00:00:00.000000', 'proof_expires_at_utc' => '2026-11-12 00:30:00.000000',
        ];
    }
    $model = (new RouteEstimateProvenance)->setConnection($route->getConnectionName())->forceFill([...$record, ...$overrides]);
    $model->save();

    return $model->refresh();
}

it('maps guarded private evidence and typed relationships without automatic snapshot serialization', function (): void {
    $route = repModelRoute();
    $actor = User::factory()->create();
    $record = repModelRecord($route, true, ['accepted_by_user_id' => $actor->id]);
    $record->load(['routePlan', 'reservation', 'actor']);
    expect($record->getTable())->toBe('route_estimate_provenances')
        ->and($record->getGuarded())->toBe(['*'])
        ->and($record->routePlan->is($route))->toBeTrue()
        ->and($record->reservation->is($route->reservation))->toBeTrue()
        ->and($record->actor->is($actor))->toBeTrue()
        ->and($record->toArray())->toBe(['id' => $record->id, 'route_plan_id' => $route->id, 'reservation_id' => $route->reservation_id])
        ->and($record->distance_meters)->toBe('12345.670000')
        ->and($record->departure_at_utc)->toBeInstanceOf(CarbonImmutable::class)
        ->and(fn () => (new RouteEstimateProvenance)->fill(['verification_status' => 'current', 'current_marker' => 1]))->toThrow(MassAssignmentException::class);
});

it('classifies matching server evidence without issuing tokens querying writing or granting Save', function (): void {
    $route = repModelRoute();
    $record = repModelRecord($route);
    Http::preventStrayRequests();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $before = $record->getAttributes();
    $summary = (new RouteEstimateProvenanceService)->summary($route, $record);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($summary)->toBe([
        'status' => 'current_planned', 'label' => 'Recorded planned calculation — requested context only',
        'reason' => 'matching_server_evidence', 'provider' => 'TomTom', 'traffic_model_status' => 'unknown',
        'verification_scope' => 'requested_context_only', 'save_eligible' => false,
    ])->and($queries)->toBe([])->and($record->getAttributes())->toBe($before)
        ->and(app(PlannedRouteProofService::class)->capabilities())->toBe(['calculation' => false, 'issuance' => false, 'apply' => false, 'save' => false]);
});

it('never treats matching manual metrics or request labels as provider verification', function (): void {
    $route = repModelRoute();
    $record = repModelRecord($route, false);
    $service = new RouteEstimateProvenanceService;
    expect($service->summary($route, $record)['status'])->toBe('manual_unverified')
        ->and($service->summary($route, $record)['provider'])->toBeNull();
    $route->forceFill(['verified' => true, 'verification_status' => 'current_planned']);
    expect($service->summary($route, $record)['status'])->toBe('stale_unverified')
        ->and($service->summary($route, $record)['save_eligible'])->toBeFalse();
});

it('preserves accepted evidence after token expiry without claiming traffic accuracy', function (string $traffic): void {
    $route = repModelRoute();
    $record = repModelRecord($route, true, ['traffic_model_status' => $traffic]);
    $this->travelTo(CarbonImmutable::parse('2027-01-01T00:00:00Z'));
    $summary = (new RouteEstimateProvenanceService)->summary($route, $record);
    expect($summary['status'])->toBe('current_planned')->and($summary['traffic_model_status'])->toBe($traffic)
        ->and($summary['verification_scope'])->toBe('requested_context_only')
        ->and($summary['label'])->not->toContain('guaranteed', 'savings', 'traffic verified');
})->with(['unknown', 'returned', 'unavailable']);

it('interprets retained closed history without matching the latest route snapshot or reviving on restore', function (string $status): void {
    $route = repModelRoute();
    $record = repModelRecord($route, true, ['verification_status' => $status, 'current_marker' => null, 'ended_at_utc' => '2026-11-12 00:10:00', 'end_reason' => 'route_archived']);
    $route->forceFill(['status' => 'Archived', 'estimated_distance' => '99.00', 'estimate_revision' => 2])->save();
    $route->refresh()->load(['stops', 'reservation']);
    $service = new RouteEstimateProvenanceService;
    expect($service->summary($route, $record)['status'])->toBe($status);
    $route->status = 'Draft';
    $route->save();
    $route->refresh()->load(['stops', 'reservation']);
    expect($service->summary($route, $record)['status'])->toBe($status)
        ->and($service->summary($route, $record)['provider'])->toBeNull()
        ->and($record->fresh()->verification_status)->toBe($status);
})->with(['superseded', 'invalidated']);

it('fails closed for corrupt stale or incomplete evidence', function (string $case): void {
    $route = repModelRoute();
    $record = repModelRecord($route);
    if ($case === 'route_revision') {
        $route->forceFill(['estimate_revision' => 2])->save();
        $route->refresh()->load(['stops', 'reservation']);
    } elseif ($case === 'archive') {
        $route->status = 'Archived';
        $route->save();
        $route->refresh()->load(['stops', 'reservation']);
    } elseif ($case === 'coordinate') {
        $route->stops()->first()->update(['latitude' => '14.6200000']);
        $route->load('stops');
    } elseif ($case === 'reservation_schedule') {
        $route->reservation->update(['schedule_time' => '10:00:00']);
        $route->load('reservation');
    } elseif ($case === 'unloaded_stops') {
        $route->unsetRelation('stops');
    } elseif ($case === 'unloaded_reservation') {
        $route->unsetRelation('reservation');
    } elseif ($case === 'dirty_stop') {
        $route->stops[0]->latitude = '14.6200000';
    } elseif ($case === 'missing_id') {
        $fields = $record->getAttributes();
        unset($fields['id']);
        $record->setRawAttributes($fields, true);
    } elseif ($case === 'missing_status') {
        $fields = $route->getAttributes();
        unset($fields['status']);
        $route->setRawAttributes($fields, true);
    } else {
        $changes = match ($case) {
            'hash' => ['route_snapshot_hash' => str_repeat('0', 64)],
            'marker' => ['current_marker' => null],
            'json' => ['route_snapshot' => '{broken'],
            'raw_metrics' => ['duration_seconds' => '1.000000'],
            'reservation_identity' => ['reservation_id' => 999],
            'traffic' => ['traffic_model_status' => 'guaranteed'],
            'proof_window' => ['accepted_at_utc' => '2026-11-12 00:31:00'],
            'proof_version' => ['proof_version' => 1],
            'saved_revision' => ['saved_route_revision' => 0],
            'options' => ['routing_options' => '{}'],
            'null_metric' => ['estimated_duration_minutes' => null],
            'wrong_strategy' => ['routing_strategy' => 'OSRM'],
        };
        $record->setRawAttributes([...$record->getAttributes(), ...$changes], true);
    }
    $summary = (new RouteEstimateProvenanceService)->summary($route, $record);
    expect($summary['status'])->toBe('stale_unverified')->and($summary['save_eligible'])->toBeFalse()
        ->and($summary['provider'])->toBeNull();
})->with(['route_revision', 'archive', 'coordinate', 'reservation_schedule', 'unloaded_stops', 'unloaded_reservation', 'dirty_stop', 'missing_id', 'missing_status', 'hash', 'marker', 'json', 'raw_metrics', 'reservation_identity', 'traffic', 'proof_window', 'proof_version', 'saved_revision', 'options', 'null_metric', 'wrong_strategy']);

it('rejects internally matching planned evidence which a valid proof could never establish', function (string $case): void {
    $route = repModelRoute();
    $overrides = [];
    if ($case === 'strategy') {
        $route->optimization_strategy = 'Unrecognized provider strategy';
        $overrides['routing_strategy'] = $route->optimization_strategy;
    } elseif ($case === 'null_coordinates') {
        $route->origin_latitude = null;
        $route->origin_longitude = null;
    } else {
        $route->departure_date = '2026-11-12';
        $route->departure_time = '08:05:00';
        $route->reservation->update(['schedule_date' => '2026-11-12', 'schedule_time' => '08:05:00']);
        $overrides = ['departure_at_utc' => '2026-11-12 00:05:00', 'accepted_at_utc' => '2026-11-12 00:10:00', 'routing_options' => TomTomRoutingService::routingContext('2026-11-12T08:05:00+08:00')];
    }
    $route->save();
    $route->refresh()->load(['stops', 'reservation']);
    $record = repModelRecord($route, true, $overrides);
    expect((new RouteEstimateProvenanceService)->summary($route, $record)['status'])->toBe('stale_unverified');
})->with(['strategy', 'null_coordinates', 'accepted_after_departure']);

it('retains zero metrics and permits nullable manual coordinates without treating either as verified', function (bool $planned): void {
    $route = repModelRoute();
    $route->estimated_distance = 0;
    $route->estimated_time = 0;
    if (! $planned) {
        $route->origin_latitude = null;
        $route->origin_longitude = null;
    }
    $route->save();
    $route->refresh()->load(['stops', 'reservation']);
    $record = repModelRecord($route, $planned, ['estimated_distance_km' => '0.00', 'estimated_duration_minutes' => 0, ...($planned ? ['distance_meters' => '0.000000', 'duration_seconds' => '0.000000'] : [])]);
    expect((new RouteEstimateProvenanceService)->summary($route, $record)['status'])->toBe($planned ? 'current_planned' : 'manual_unverified')
        ->and($record->estimated_distance_km)->toBe('0.00')->and($record->estimated_duration_minutes)->toBe(0);
})->with([true, false]);

it('normalizes tiny persisted SQLite coordinates without floating point arithmetic', function (): void {
    $route = repModelRoute();
    $route->origin_latitude = '0.0000001';
    $route->origin_longitude = '-0.0000001';
    $route->save();
    $route->refresh()->load(['stops', 'reservation']);
    $point = (new RouteEstimateProvenanceService)->snapshot($route)['origin'];
    expect($point['latitude'])->toBe('0.0000001')->and($point['longitude'])->toBe('-0.0000001');
});

it('fails closed on an incomplete historical snapshot even when its hash is self consistent', function (): void {
    $route = repModelRoute();
    $record = repModelRecord($route, true, ['verification_status' => 'invalidated', 'current_marker' => null, 'ended_at_utc' => '2026-11-12 00:10:00', 'end_reason' => 'manual_estimate_changed']);
    $snapshot = $record->route_snapshot;
    unset($snapshot['stops']);
    $record->forceFill(['route_snapshot' => $snapshot, 'route_snapshot_hash' => hash('sha256', (new RouteEstimateProvenanceService)->canonicalJson($snapshot))])->save();
    expect((new RouteEstimateProvenanceService)->summary($route, $record->fresh())['status'])->toBe('stale_unverified');
});

it('ignores JSON object key order while strictly binding value types and occurrence lists', function (): void {
    $route = repModelRoute();
    $record = repModelRecord($route);
    $record->forceFill(['route_snapshot' => array_reverse($record->route_snapshot, true), 'routing_options' => array_reverse($record->routing_options, true)])->save();
    $record->refresh();
    expect((new RouteEstimateProvenanceService)->summary($route, $record)['status'])->toBe('current_planned');
    $snapshot = $record->route_snapshot;
    $snapshot['estimated_duration_minutes'] = '16';
    $record->setRawAttributes([...$record->getAttributes(), 'route_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)], true);
    expect((new RouteEstimateProvenanceService)->summary($route, $record)['status'])->toBe('stale_unverified');
});

it('returns an unverified presentation for malformed dirty decimal casts', function (bool $dirtyRoute): void {
    $route = repModelRoute();
    $record = repModelRecord($route);
    $model = $dirtyRoute ? $route : $record;
    $field = $dirtyRoute ? 'estimated_distance' : 'distance_meters';
    $model->setRawAttributes([...$model->getAttributes(), $field => 'not-a-decimal']);
    expect((new RouteEstimateProvenanceService)->summary($route, $record)['status'])->toBe('stale_unverified');
})->with([true, false]);

it('provides closure and revision intentions for supersession invalidation and notes preservation', function (string $event): void {
    $before = repModelRoute();
    $record = repModelRecord($before);
    $after = clone $before;
    if ($event === 'archive') {
        $after->status = 'Archived';
    } elseif ($event === 'manual_edit') {
        $after->estimated_time = 99;
    } elseif ($event === 'notes') {
        $after->notes = 'Synthetic notes only';
    }
    $original = $record->getAttributes();
    $plan = (new RouteEstimateProvenanceService)->planTransition($before, $after, $record, $event === 'notes' ? 'manual_edit' : $event, 1, CarbonImmutable::parse('2026-11-12T00:10:00Z'));
    expect($plan['persistence_allowed'])->toBeFalse()->and($plan['source_route_revision'])->toBe(1)
        ->and($plan['saved_route_revision'])->toBe($event === 'notes' ? 1 : 2)
        ->and($record->fresh()->getAttributes())->toBe($original)
        ->and(RouteEstimateProvenance::query()->count())->toBe(1)
        ->and($before->fresh()->estimate_revision)->toBe(1);
    if ($event === 'notes') {
        expect($plan['close'])->toBeNull()->and($plan['next_mode'])->toBeNull();
    } else {
        expect($plan['close']['id'])->toBe($record->id)
            ->and($plan['close']['current_marker'])->toBeNull()
            ->and($plan['close']['ended_at_utc'])->toBe('2026-11-12 00:10:00.000000')
            ->and($plan['close']['verification_status'])->toBe($event === 'accept_planned' ? 'superseded' : 'invalidated')
            ->and($plan['close']['end_reason'])->toBe(match ($event) {
                'accept_planned' => 'recalculated', 'archive' => 'route_archived', default => 'manual_estimate_changed'
            });
    }
})->with(['accept_planned', 'manual_edit', 'archive', 'notes']);

it('requires closed current evidence before restoration and does not inherit evidence on duplication', function (): void {
    $before = repModelRoute();
    $record = repModelRecord($before);
    $after = clone $before;
    $before->status = 'Archived';
    $before->save();
    $before->refresh()->load(['stops', 'reservation']);
    expect(fn () => (new RouteEstimateProvenanceService)->planTransition($before, $after, $record, 'restore', 1, CarbonImmutable::now('UTC')))->toThrow(InvalidArgumentException::class)
        ->and(RouteEstimateProvenance::query()->count())->toBe(1);
});

it('plans stop occurrence and coordinate invalidation while preserving stored stops and history', function (): void {
    $before = repModelRoute();
    $record = repModelRecord($before);
    $after = clone $before;
    $after->setRelation('stops', $before->stops->map(fn ($stop) => clone $stop));
    $after->stops[0]->latitude = '14.6300000';
    $after->stops[0]->stop_order = 2;
    $after->stops[1]->stop_order = 1;
    $stored = $before->stops->map->getAttributes()->all();
    $plan = (new RouteEstimateProvenanceService)->planTransition($before, $after, $record, 'manual_edit', 1, CarbonImmutable::parse('2026-11-12T00:10:00Z'));
    expect($plan['close']['verification_status'])->toBe('invalidated')
        ->and($plan['close']['end_reason'])->toBe('manual_estimate_changed')
        ->and($plan['next_mode'])->toBe('manual')->and($plan['saved_route_revision'])->toBe(2)
        ->and($before->stops()->get()->map->getAttributes()->all())->toBe($stored)
        ->and($record->fresh()->verification_status)->toBe('current');
});

it('enforces current and UUID uniqueness while preserving multiple historical occurrences', function (): void {
    $route = repModelRoute();
    $record = repModelRecord($route);
    expect(fn () => repModelRecord($route))->toThrow(QueryException::class);
    $record->forceFill(['verification_status' => 'superseded', 'current_marker' => null, 'ended_at_utc' => '2026-11-12 00:10:00', 'end_reason' => 'recalculated'])->save();
    $second = repModelRecord($route, true, ['verification_status' => 'invalidated', 'current_marker' => null, 'ended_at_utc' => '2026-11-12 00:10:00', 'end_reason' => 'manual_estimate_changed']);
    repModelRecord($route);
    expect(RouteEstimateProvenance::query()->count())->toBe(3)
        ->and(fn () => repModelRecord(repModelRoute(), true, ['calculation_uuid' => $second->calculation_uuid]))->toThrow(QueryException::class)
        ->and(RouteEstimateProvenance::query()->where('current_marker', 1)->count())->toBe(1);
});

it('enforces foreign keys restrictive history deletion and lifecycle constraints', function (string $case): void {
    $route = repModelRoute();
    $record = repModelRecord($route);
    if ($case === 'route_delete') {
        expect(fn () => $route->delete())->toThrow(QueryException::class);
    } elseif ($case === 'reservation_delete') {
        expect(fn () => $route->reservation->delete())->toThrow(QueryException::class);
    } else {
        $changes = match ($case) {
            'status' => ['verification_status' => 'verified'], 'marker' => ['current_marker' => null],
            'foreign_route' => ['route_plan_id' => 9999], 'foreign_reservation' => ['reservation_id' => 9999],
        };
        expect(fn () => $record->forceFill($changes)->save())->toThrow(QueryException::class);
    }
    expect(RouteEstimateProvenance::query()->count())->toBe(1)->and($route->fresh())->not->toBeNull();
})->with(['route_delete', 'reservation_delete', 'status', 'marker', 'foreign_route', 'foreign_reservation']);

it('detects a cross-table reservation mismatch which separate foreign keys cannot prevent', function (): void {
    $route = repModelRoute();
    $other = repModelRoute();
    $record = repModelRecord($route, true, ['reservation_id' => $other->reservation_id]);
    expect((new RouteEstimateProvenanceService)->summary($route, $record)['status'])->toBe('stale_unverified');
});

it('leaves legacy routes unrecorded without backfill or queries and preserves manual updates', function (): void {
    $route = repModelRoute();
    $route->forceFill(['estimate_revision' => 0, 'estimated_distance' => '2.00', 'estimated_time' => 3, 'optimization_strategy' => 'Manual'])->save();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $summary = (new RouteEstimateProvenanceService)->summary($route);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($summary['status'])->toBe('unrecorded')->and($summary['save_eligible'])->toBeFalse()->and($queries)->toBe([])
        ->and(RouteEstimateProvenance::query()->count())->toBe(0)
        ->and($route->fresh()->estimated_distance)->toBe('2.00')->and($route->fresh()->estimate_revision)->toBe(0);
});

function repWriterAudit(int $actorId): Closure
{
    return fn (Connection $connection, RoutePlan $route, array $result): AuditLog => AuditLog::on($connection->getName())->create([
        'user_id' => $actorId, 'module' => 'Route Planning', 'action' => 'Updated',
        'description' => 'Synthetic manual writer test', 'auditable_type' => RoutePlan::class, 'auditable_id' => $route->id,
        'new_values' => ['estimate_revision' => $result['saved_route_revision']],
    ]);
}

function repWriterCall(RoutePlan $route, User $actor, array $changes, ?int $base = null, ?Closure $audit = null, ?Closure $authorize = null): array
{
    return (new RouteEstimateProvenanceService)->writeManualEstimate(
        $route->getConnectionName(), $route->id, $route->reservation_id, $base ?? (int) $route->estimate_revision, $actor->id, $changes,
        $authorize ?? fn (RoutePlan $locked, Reservation $reservation, User $freshActor): bool => $locked->id === $route->id && $reservation->id === $route->reservation_id && $freshActor->id === $actor->id,
        $audit ?? repWriterAudit($actor->id),
    );
}

describe('atomic manual provenance writer', function (): void {
    beforeEach(function (): void {
        expect(app()->environment())->toBe('testing')->and(config('database.connections.sqlite.database'))->toBe(':memory:')
            ->and(config('database.connections.sqlite.url'))->toBeFalsy();
        config(['database.connections.provenance_writer' => config('database.connections.sqlite')]);
        $this->writerConnection = DB::connection('provenance_writer');
        expect($this->writerConnection->getDriverName())->toBe('sqlite')
            ->and($this->writerConnection->getDatabaseName())->toBe(':memory:')
            ->and($this->writerConnection->getPdo())->not->toBe(DB::connection()->getPdo())
            ->and($this->writerConnection->transactionLevel())->toBe(0);
        $this->artisan('migrate', ['--database' => 'provenance_writer', '--no-interaction' => true])->assertExitCode(0);
        expect($this->writerConnection->selectOne('PRAGMA foreign_keys')->foreign_keys)->toBe(1);
        $this->writerActor = User::factory()->connection('provenance_writer')->create();
        $this->writerRoute = repModelRoute('provenance_writer');
        $this->writerRoute->forceFill(['estimate_revision' => 0])->save();
        $this->writerRoute->refresh()->load(['stops', 'reservation']);
        $this->travelTo(CarbonImmutable::parse('2026-11-12T00:10:00Z'));
        Http::preventStrayRequests();
    });

    afterEach(function (): void {
        expect($this->writerConnection->transactionLevel())->toBe(0)
            ->and($this->writerConnection->getPdo()->inTransaction())->toBeFalse();
        DB::purge('provenance_writer');
    });

    it('commits normalized manual evidence route stops revision and audit together', function (): void {
        $result = repWriterCall($this->writerRoute, $this->writerActor, ['route' => ['estimated_distance' => '23.456', 'estimated_time' => '24', 'origin_latitude' => '14.60000005']]);
        $route = $this->writerRoute->fresh()->load(['stops', 'reservation']);
        $record = RouteEstimateProvenance::on('provenance_writer')->findOrFail($result['provenance_id']);
        expect($result['source_route_revision'])->toBe(0)->and($result['saved_route_revision'])->toBe(1)
            ->and($result['estimate_changed'])->toBeTrue()->and($result['closed_provenance_id'])->toBeNull()
            ->and($result['summary']['status'])->toBe('manual_unverified')->and($result['summary']['save_eligible'])->toBeFalse()
            ->and($route->estimated_distance)->toBe('23.46')->and($route->estimated_time)->toBe(24)->and($route->origin_latitude)->toBe('14.6000001')
            ->and($route->estimate_revision)->toBe(1)->and($record->route_snapshot_hash)->toBe((new RouteEstimateProvenanceService)->fingerprint($route))
            ->and($record->provider)->toBeNull()->and($record->calculation_uuid)->toBeNull()->and($record->proof_version)->toBeNull()
            ->and($record->distance_meters)->toBeNull()->and($record->accepted_by_user_id)->toBe($this->writerActor->id)
            ->and($record->route_snapshot['stops'])->toHaveCount(2)
            ->and(AuditLog::on('provenance_writer')->findOrFail($result['audit_id'])->new_values)->toBe(['estimate_revision' => 1])
            ->and(DB::connection()->table('route_estimate_provenances')->count())->toBe(0);
        expect(json_encode($result, JSON_THROW_ON_ERROR))->not->toContain('route_snapshot', 'proof_token', 'Synthetic Origin');
    });

    it('queries fresh route reservation provenance and stops in the required order', function (): void {
        $this->writerConnection->enableQueryLog();
        repWriterCall($this->writerRoute, $this->writerActor, ['route' => ['estimated_time' => 30]]);
        $tables = [];
        foreach ($this->writerConnection->getQueryLog() as $query) {
            if (preg_match('/from "(route_plans|reservations|route_estimate_provenances|route_stops)"/', $query['query'], $match)) {
                $tables[] = $match[1];
            }
        }
        $this->writerConnection->disableQueryLog();
        expect(array_slice($tables, 0, 4))->toBe(['route_plans', 'reservations', 'route_estimate_provenances', 'route_stops']);
    });

    it('supersedes manual history and invalidates planned history without deleting either', function (bool $planned): void {
        $route = $this->writerRoute;
        $route->forceFill(['estimate_revision' => 1])->save();
        $route->refresh()->load(['stops', 'reservation']);
        $old = repModelRecord($route, $planned);
        $snapshot = $old->route_snapshot;
        $hash = $old->route_snapshot_hash;
        $candidate = clone $route;
        $candidate->estimated_time = 30;
        $plan = (new RouteEstimateProvenanceService)->planTransition($route, $candidate, $old, 'manual_edit', 1, CarbonImmutable::now('UTC'));
        $result = repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]]);
        $old->refresh();
        expect($result['saved_route_revision'])->toBe(2)->and($result['closed_provenance_id'])->toBe($old->id)
            ->and($old->verification_status)->toBe($planned ? 'invalidated' : 'superseded')
            ->and($old->end_reason)->toBe($planned ? 'manual_estimate_changed' : 'manual_estimate_replaced')
            ->and($old->verification_status)->toBe($plan['close']['verification_status'])->and($old->end_reason)->toBe($plan['close']['end_reason'])
            ->and($old->current_marker)->toBeNull()->and($old->ended_at_utc->format('Y-m-d H:i:s'))->toBe('2026-11-12 00:10:00')
            ->and($old->route_snapshot)->toBe($snapshot)->and($old->route_snapshot_hash)->toBe($hash)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(2)
            ->and(RouteEstimateProvenance::on('provenance_writer')->where('current_marker', 1)->count())->toBe(1);
    })->with([true, false]);

    it('preserves notes-only evidence and does not backfill an unrecorded legacy route', function (bool $withRecord): void {
        $route = $this->writerRoute;
        $old = null;
        if ($withRecord) {
            $route->forceFill(['estimate_revision' => 1])->save();
            $route->refresh()->load(['stops', 'reservation']);
            $old = repModelRecord($route);
        }
        $before = $old?->getAttributes();
        $stops = $route->stops->map->getAttributes()->all();
        $result = repWriterCall($route, $this->writerActor, ['route' => ['notes' => 'Synthetic notes only']]);
        expect($result['estimate_changed'])->toBeFalse()->and($result['saved_route_revision'])->toBe($withRecord ? 1 : 0)
            ->and($result['provenance_id'])->toBe($old?->id)->and($result['closed_provenance_id'])->toBeNull()
            ->and($old?->fresh()->getAttributes())->toBe($before)
            ->and($route->fresh()->notes)->toBe('Synthetic notes only')
            ->and($route->stops()->get()->map->getAttributes()->all())->toBe($stops)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe($withRecord ? 1 : 0);
    })->with([true, false]);

    it('preserves null versus zero estimates and treats copied provider labels as manual', function (): void {
        $route = $this->writerRoute;
        $first = repWriterCall($route, $this->writerActor, ['route' => ['estimated_distance' => null, 'estimated_time' => null]]);
        $record = RouteEstimateProvenance::on('provenance_writer')->findOrFail($first['provenance_id']);
        expect($record->estimated_distance_km)->toBeNull()->and($record->estimated_duration_minutes)->toBeNull();
        $second = repWriterCall($route->fresh(), $this->writerActor, ['route' => ['estimated_distance' => 0, 'estimated_time' => 0, 'optimization_strategy' => 'TomTom Traffic-Aware Optimized']]);
        $current = RouteEstimateProvenance::on('provenance_writer')->findOrFail($second['provenance_id']);
        expect($current->estimated_distance_km)->toBe('0.00')->and($current->estimated_duration_minutes)->toBe(0)
            ->and($current->route_snapshot_hash)->not->toBe($record->route_snapshot_hash)
            ->and($second['saved_route_revision'])->toBe(2)->and($second['summary']['status'])->toBe('manual_unverified')
            ->and($current->provider)->toBeNull();
    });

    it('persists ordered repeated occurrences without pairing labels to the wrong coordinates', function (): void {
        $stops = [
            ['location' => 'Repeated synthetic label', 'latitude' => '14.62000001', 'longitude' => '121.02000001'],
            ['location' => 'Repeated synthetic label', 'latitude' => '14.6100000', 'longitude' => '121.0100000'],
            ['location' => 'Coincident synthetic stop', 'latitude' => '14.6100000', 'longitude' => '121.0100000'],
        ];
        $first = repWriterCall($this->writerRoute, $this->writerActor, ['stops' => $stops]);
        $route = $this->writerRoute->fresh()->load(['stops', 'reservation']);
        $record = RouteEstimateProvenance::on('provenance_writer')->findOrFail($first['provenance_id']);
        expect($route->stops->pluck('stop_order')->all())->toBe([1, 2, 3])
            ->and($route->stops->pluck('location')->all())->toBe(array_column($stops, 'location'))
            ->and($route->stops->pluck('latitude')->all())->toBe(['14.6200000', '14.6100000', '14.6100000'])
            ->and($record->route_snapshot['stops'][2]['label_hash'])->toBe(hash('sha256', 'Coincident synthetic stop'));
        $ids = $route->stops->pluck('id')->all();
        $same = repWriterCall($route, $this->writerActor, ['stops' => $stops]);
        expect($same['saved_route_revision'])->toBe(1)->and($same['provenance_id'])->toBe($first['provenance_id'])
            ->and($route->stops()->pluck('id')->all())->toBe($ids);
        $reordered = repWriterCall($route->fresh(), $this->writerActor, ['stops' => [$stops[2], $stops[0], $stops[1]]]);
        expect($reordered['saved_route_revision'])->toBe(2)
            ->and($route->stops()->pluck('location')->all())->toBe(['Coincident synthetic stop', 'Repeated synthetic label', 'Repeated synthetic label']);
    });

    it('accepts the first base revision and rejects the sequential stale submission before audit', function (): void {
        $route = $this->writerRoute;
        repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]], 0);
        $called = false;
        $audit = function () use (&$called): void {
            $called = true;
        };
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 99]], 0, $audit))->toThrow(InvalidArgumentException::class, 'Stale route estimate revision.')
            ->and($called)->toBeFalse()->and($route->fresh()->estimated_time)->toBe(30)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(1)
            ->and(AuditLog::on('provenance_writer')->count())->toBe(1);
    });

    it('keeps independent route revisions independent', function (): void {
        $other = repModelRoute('provenance_writer');
        $other->forceFill(['estimate_revision' => 0])->save();
        foreach ([$this->writerRoute, $other] as $route) {
            expect(repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]], 0)['saved_route_revision'])->toBe(1);
        }
        expect(RouteEstimateProvenance::on('provenance_writer')->where('current_marker', 1)->count())->toBe(2);
    });

    it('requires affirmative authorization of freshly locked database resources', function (mixed $approval): void {
        $route = $this->writerRoute;
        $route->status = 'Archived';
        $authorize = function (RoutePlan $fresh, Reservation $reservation, User $actor) use ($route, $approval): mixed {
            expect($fresh->status)->toBe('Draft')->and($fresh->id)->toBe($route->id)
                ->and($reservation->id)->toBe($route->reservation_id)->and($actor->id)->toBe($this->writerActor->id);

            return $approval;
        };
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]], 0, null, $authorize))->toThrow(AuthorizationException::class)
            ->and($route->fresh()->estimated_time)->toBe(16)->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    })->with([false, null, 1, 'true']);

    it('rejects lifecycle linked dispatch reservation and readiness bypasses', function (string $case): void {
        $route = $this->writerRoute;
        if (in_array($case, ['Completed', 'Archived', 'Ready For Dispatch'], true)) {
            $route->status = $case;
            $route->save();
        } elseif ($case === 'dispatch') {
            Dispatch::on('provenance_writer')->create(['dispatch_number' => 'SYNTHETIC-'.Str::uuid(), 'reservation_id' => $route->reservation_id, 'dispatch_date' => '2026-11-20', 'departure_time' => '09:00:00', 'trip_status' => 'Pending']);
        } else {
            $route->reservation->update($case === 'reservation_archived' ? ['archived_at' => now()] : ['status' => 'Cancelled']);
        }
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]]))->toThrow(InvalidArgumentException::class)
            ->and($route->fresh()->estimated_time)->toBe(16)->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(0);
    })->with(['Completed', 'Archived', 'Ready For Dispatch', 'dispatch', 'reservation_archived', 'reservation_cancelled']);

    it('rejects mismatched reservation identity and ambient transactions', function (string $case): void {
        $route = $this->writerRoute;
        if ($case === 'relationship') {
            expect(fn () => (new RouteEstimateProvenanceService)->writeManualEstimate('provenance_writer', $route->id, $route->reservation_id + 99, 0, $this->writerActor->id, [], fn () => true, repWriterAudit($this->writerActor->id)))->toThrow(InvalidArgumentException::class, 'Locked route/reservation mismatch.');
        } elseif ($case === 'ambient') {
            $this->writerConnection->beginTransaction();
            try {
                expect(fn () => repWriterCall($route, $this->writerActor, []))->toThrow(InvalidArgumentException::class, 'top-level');
            } finally {
                $this->writerConnection->rollBack();
            }
        } else {
            $this->writerConnection->getPdo()->beginTransaction();
            try {
                expect(fn () => repWriterCall($route, $this->writerActor, []))->toThrow(InvalidArgumentException::class, 'top-level');
            } finally {
                $this->writerConnection->getPdo()->rollBack();
            }
        }
        expect($route->fresh()->estimate_revision)->toBe(0)->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    })->with(['relationship', 'ambient', 'raw_ambient']);

    it('rejects invalid current provenance without silently replacing its history', function (): void {
        $route = $this->writerRoute;
        $route->forceFill(['estimate_revision' => 1])->save();
        $route->refresh()->load(['stops', 'reservation']);
        $record = repModelRecord($route, true, ['route_snapshot_hash' => str_repeat('0', 64)]);
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]]))->toThrow(InvalidArgumentException::class, 'Current provenance is inconsistent')
            ->and($route->fresh()->estimate_revision)->toBe(1)->and($route->fresh()->estimated_time)->toBe(16)
            ->and($record->fresh()->verification_status)->toBe('current')->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    });

    it('rejects unsafe patch fields invalid storage metrics and forged planned claims', function (array $changes): void {
        expect(fn () => repWriterCall($this->writerRoute, $this->writerActor, $changes))->toThrow(InvalidArgumentException::class)
            ->and($this->writerRoute->fresh()->estimate_revision)->toBe(0)
            ->and($this->writerRoute->fresh()->estimated_time)->toBe(16)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(0)
            ->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    })->with([
        [['verified' => true]], [['route' => ['provider' => 'TomTom']]], [['route' => ['status' => 'Ready For Dispatch']]],
        [['route' => ['estimate_revision' => 99]]], [['route' => ['reservation_id' => 99]]],
        [['route' => ['estimated_time' => '1.2']]], [['route' => ['estimated_distance' => -1]]],
        [['route' => ['departure_date' => '2026-02-30']]], [['route' => ['departure_time' => '10:00:00']]],
        [['stops' => [['location' => 'Synthetic', 'latitude' => 91, 'longitude' => 1]]]],
        [['stops' => [['location' => 'Synthetic', 'latitude' => null]]]],
        [['route' => null]],
    ]);

    it('detects silently ignored route note stop deletion and final stop insertion mutations', function (string $case): void {
        $route = $this->writerRoute;
        $before = $route->getAttributes();
        $stops = $route->stops->map->getAttributes()->all();
        $trigger = match ($case) {
            'route' => 'CREATE TRIGGER synthetic_ignore BEFORE UPDATE OF estimated_time ON route_plans BEGIN SELECT RAISE(IGNORE); END',
            'notes' => 'CREATE TRIGGER synthetic_ignore BEFORE UPDATE OF notes ON route_plans BEGIN SELECT RAISE(IGNORE); END',
            'stop_delete' => 'CREATE TRIGGER synthetic_ignore BEFORE DELETE ON route_stops BEGIN SELECT RAISE(IGNORE); END',
            'stop_insert' => 'CREATE TRIGGER synthetic_ignore BEFORE INSERT ON route_stops WHEN NEW.stop_order = 2 BEGIN SELECT RAISE(IGNORE); END',
        };
        $this->writerConnection->statement($trigger);
        $changes = match ($case) {
            'route' => ['route' => ['estimated_time' => 30]],
            'notes' => ['route' => ['notes' => 'Must not be silently discarded']],
            'stop_delete' => ['stops' => []],
            'stop_insert' => ['stops' => [
                ['location' => 'Synthetic first', 'latitude' => '14.62', 'longitude' => '121.02'],
                ['location' => 'Synthetic second', 'latitude' => '14.63', 'longitude' => '121.03'],
            ]],
        };
        expect(fn () => repWriterCall($route, $this->writerActor, $changes))->toThrow(InvalidArgumentException::class, $case === 'notes' ? 'Persisted manual fields' : 'Persisted route/stops')
            ->and($route->fresh()->getAttributes())->toBe($before)
            ->and($route->stops()->get()->map->getAttributes()->all())->toBe($stops)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(0)
            ->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    })->with(['route', 'notes', 'stop_delete', 'stop_insert']);

    it('rejects audit callbacks that mutate lifecycle or delete the new current evidence', function (string $case): void {
        $route = $this->writerRoute;
        $audit = function (Connection $connection, RoutePlan $fresh, array $result) use ($case): AuditLog {
            $row = repWriterAudit($this->writerActor->id)($connection, $fresh, $result);
            if ($case === 'lifecycle') {
                $fresh->update(['status' => 'Planned']);
            } else {
                RouteEstimateProvenance::on('provenance_writer')->whereKey($result['provenance_id'])->delete();
            }

            return $row;
        };
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]], 0, $audit))->toThrow(InvalidArgumentException::class)
            ->and($route->fresh()->status)->toBe('Draft')->and($route->fresh()->estimated_time)->toBe(16)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(0)
            ->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    })->with(['lifecycle', 'current_deleted']);

    it('rejects revision overflow and rolls back the attempted edit', function (): void {
        $route = $this->writerRoute;
        $route->forceFill(['estimate_revision' => PHP_INT_MAX])->save();
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]]))->toThrow(InvalidArgumentException::class, 'revision exhausted')
            ->and($route->fresh()->estimate_revision)->toBe(PHP_INT_MAX)->and($route->fresh()->estimated_time)->toBe(16)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(0);
    });

    it('rolls back a database commit failure without leaving an open transaction or partial audit', function (): void {
        $route = $this->writerRoute;
        $before = $route->getAttributes();
        $audit = function (Connection $connection, RoutePlan $fresh, array $result): AuditLog {
            $row = repWriterAudit($this->writerActor->id)($connection, $fresh, $result);
            $connection->statement('PRAGMA defer_foreign_keys = ON');
            $connection->table('route_stops')->insert(['route_plan_id' => 999999, 'stop_order' => 1, 'location' => 'Synthetic deferred constraint failure']);

            return $row;
        };
        expect(fn () => repWriterCall($route, $this->writerActor, ['route' => ['estimated_time' => 30]], 0, $audit))->toThrow(PDOException::class, 'FOREIGN KEY constraint failed')
            ->and($route->fresh()->getAttributes())->toBe($before)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(0)
            ->and(AuditLog::on('provenance_writer')->count())->toBe(0)
            ->and($this->writerConnection->transactionLevel())->toBe(0)->and($this->writerConnection->getPdo()->inTransaction())->toBeFalse();
    });

    it('rolls back route stops revision current closure insertion and audits at every failure stage', function (string $stage): void {
        $route = $this->writerRoute;
        $route->forceFill(['estimate_revision' => 1])->save();
        $route->refresh()->load(['stops', 'reservation']);
        $record = repModelRecord($route);
        $beforeRoute = $route->getAttributes();
        $beforeStops = $route->stops->map->getAttributes()->all();
        $beforeRecord = $record->getAttributes();
        $trigger = match ($stage) {
            'revision' => "CREATE TRIGGER synthetic_failure BEFORE UPDATE OF estimate_revision ON route_plans BEGIN SELECT RAISE(ABORT, 'synthetic revision failure'); END",
            'revision_ignored' => 'CREATE TRIGGER synthetic_failure BEFORE UPDATE OF estimate_revision ON route_plans BEGIN SELECT RAISE(IGNORE); END',
            'closure' => "CREATE TRIGGER synthetic_failure BEFORE UPDATE OF current_marker ON route_estimate_provenances BEGIN SELECT RAISE(ABORT, 'synthetic closure failure'); END",
            'insertion' => "CREATE TRIGGER synthetic_failure BEFORE INSERT ON route_estimate_provenances BEGIN SELECT RAISE(ABORT, 'synthetic insertion failure'); END",
            'insertion_ignored' => 'CREATE TRIGGER synthetic_failure BEFORE INSERT ON route_estimate_provenances BEGIN SELECT RAISE(IGNORE); END',
            default => null,
        };
        if ($trigger !== null) {
            $this->writerConnection->statement($trigger);
        }
        $auditInvoked = false;
        $audit = function (Connection $connection, RoutePlan $fresh, array $result) use ($stage, $record, &$auditInvoked): mixed {
            $auditInvoked = true;
            expect($fresh->estimate_revision)->toBe(2)->and($result['summary']['status'])->toBe('manual_unverified')
                ->and($record->fresh()->verification_status)->toBe('invalidated')
                ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(2);
            $row = repWriterAudit($this->writerActor->id)($connection, $fresh, $result);
            if ($stage === 'duplicate_uuid') {
                $duplicate = $record->getAttributes();
                unset($duplicate['id']);
                $duplicate['verification_status'] = 'superseded';
                $duplicate['current_marker'] = null;
                $duplicate['ended_at_utc'] = '2026-11-12 00:10:00';
                $duplicate['end_reason'] = 'synthetic_duplicate';
                $connection->table('route_estimate_provenances')->insert($duplicate);
            }
            if ($stage === 'invalid_audit') {
                return false;
            }
            throw new RuntimeException('Synthetic audit failure');
        };
        $changes = ['route' => ['estimated_time' => 30], 'stops' => [['location' => 'Synthetic New Stop', 'latitude' => '14.64', 'longitude' => '121.04']]];
        [$exception, $message] = match ($stage) {
            'revision' => [QueryException::class, 'synthetic revision failure'],
            'revision_ignored' => [InvalidArgumentException::class, 'Route revision advancement failed.'],
            'closure' => [QueryException::class, 'synthetic closure failure'],
            'insertion' => [QueryException::class, 'synthetic insertion failure'],
            'insertion_ignored' => [InvalidArgumentException::class, 'inserted manual provenance'],
            'duplicate_uuid' => [QueryException::class, 'UNIQUE constraint failed: route_estimate_provenances.calculation_uuid'],
            'invalid_audit' => [InvalidArgumentException::class, 'same-transaction audit record'],
            default => [RuntimeException::class, 'Synthetic audit failure'],
        };
        expect(fn () => repWriterCall($route, $this->writerActor, $changes, 1, $audit))->toThrow($exception, $message)
            ->and($auditInvoked)->toBe(in_array($stage, ['audit', 'invalid_audit', 'duplicate_uuid'], true))
            ->and($route->fresh()->getAttributes())->toBe($beforeRoute)
            ->and($route->stops()->get()->map->getAttributes()->all())->toBe($beforeStops)
            ->and($record->fresh()->getAttributes())->toBe($beforeRecord)
            ->and(RouteEstimateProvenance::on('provenance_writer')->count())->toBe(1)
            ->and(AuditLog::on('provenance_writer')->count())->toBe(0);
    })->with(['revision', 'revision_ignored', 'closure', 'insertion', 'insertion_ignored', 'audit', 'invalid_audit', 'duplicate_uuid']);
});
