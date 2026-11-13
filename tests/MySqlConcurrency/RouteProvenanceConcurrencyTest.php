<?php

use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\RouteEstimateProvenance;
use App\Models\RoutePlan;
use App\Models\User;
use App\Services\PlannedRouteProofService;
use App\Services\RouteEstimateProvenanceService;
use App\Services\TomTomRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\MySqlConcurrency\Support\ConcurrentProcessRunner;
use Tests\MySqlConcurrency\Support\DatabaseBarrier;
use Tests\MySqlConcurrency\Support\HarnessEnvironment;
use Tests\MySqlConcurrency\Support\HarnessSafetyPolicy;
use Tests\MySqlConcurrency\Support\IsolatedMySqlEnvironment;
use Tests\MySqlConcurrency\Support\NotificationOccurrenceSchemaTransition;
use Tests\MySqlConcurrency\Support\RouteProvenanceConcurrencyWorker;
use Tests\MySqlConcurrency\Support\RouteProvenanceRunCoordinator;

beforeEach(function (): void {
    RouteProvenanceConcurrencyWorker::enableTestCapabilities();
    $this->routeUser = User::factory()->create(['role' => 'fleet_manager', 'status' => 'active',
        'name' => 'Synthetic Route Concurrency', 'email' => 'route-'.Str::uuid().'@example.invalid']);
});

afterEach(function (): void {
    foreach (['planned_departure_enabled', 'planned_proof_issuance_enabled', 'planned_apply_enabled', 'planned_save_enabled'] as $flag) {
        config(['services.tomtom.'.$flag => false]);
    }
    config(['services.route_estimates.provenance_recording_enabled' => false]);
    (new NotificationOccurrenceSchemaTransition)->assertBaseline();
    expect(DB::transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    Http::assertNothingSent();
});

function rpcRoute(): RoutePlan
{
    $reservation = Reservation::create(['reservation_number' => 'RP-'.Str::uuid(), 'patient_name' => null,
        'request_type' => 'staff_transport', 'pickup_location' => 'Synthetic Origin', 'destination' => 'Synthetic Destination',
        'schedule_date' => '2050-12-20', 'schedule_time' => '09:00:00', 'priority' => 'routine', 'status' => 'Approved']);
    $route = RoutePlan::create(['route_number' => 'RP-'.Str::uuid(), 'reservation_id' => $reservation->id,
        'origin' => 'Synthetic Origin', 'destination' => 'Synthetic Destination', 'department' => 'Operations',
        'origin_latitude' => 14.6, 'origin_longitude' => 121, 'destination_latitude' => 14.7, 'destination_longitude' => 121.1,
        'status' => 'Draft', 'priority' => 'routine', 'departure_date' => '2050-12-20', 'departure_time' => '09:00:00',
        'estimated_distance' => 10, 'estimated_time' => 12, 'optimization_strategy' => 'Manual']);
    foreach ([1, 2] as $position) {
        $route->stops()->create(['stop_order' => $position, 'location' => 'Synthetic Repeated Stop', 'latitude' => 14.61, 'longitude' => 121.01]);
    }

    return $route->refresh()->load(['stops', 'reservation']);
}

function rpcManual(RoutePlan $route): array
{
    return [...$route->only(['origin', 'destination', 'department', 'status', 'origin_latitude', 'origin_longitude',
        'destination_latitude', 'destination_longitude', 'estimated_distance', 'estimated_time', 'optimization_strategy']),
        'expected_estimate_revision' => (int) $route->estimate_revision,
        'stops' => $route->stops->map(fn ($stop): array => $stop->only(['location', 'latitude', 'longitude']))->all()];
}

/** Mint genuine encrypted evidence through the canonical issuer; no live provider is called. */
function rpcPlanned(RoutePlan $route, User $user): array
{
    $coordinates = [['latitude' => 14.6, 'longitude' => 121.0], ['latitude' => 14.61, 'longitude' => 121.01],
        ['latitude' => 14.61, 'longitude' => 121.01], ['latitude' => 14.7, 'longitude' => 121.1]];
    $token = app(PlannedRouteProofService::class)->issue((new RouteEstimateProvenanceService)->plannedContext(
        $route, $route->reservation, $user->id, 'transition', (int) $route->estimate_revision, 'Planned'), [
            'coordinates' => $coordinates, 'stop_labels' => ['Synthetic Repeated Stop', 'Synthetic Repeated Stop'],
            'result' => ['provider' => 'TomTom', 'request_coordinates' => $coordinates, 'waypoint_order_optimized' => true,
                'routing_context' => TomTomRoutingService::routingContext('2050-12-20T09:00:00+08:00'),
                'optimized_waypoints' => [['providedIndex' => 0, 'optimizedIndex' => 1], ['providedIndex' => 1, 'optimizedIndex' => 0]],
                'comparison_metrics' => ['distance_meters' => 12345.67, 'travel_time_seconds' => 905.5],
                'calculation_started_at' => now()->subSecond()->toISOString(), 'calculated_at' => now()->toISOString()],
        ]);

    return [...rpcManual($route), 'status' => 'Planned', 'estimated_distance' => 12.35, 'estimated_time' => 16,
        'optimization_strategy' => 'TomTom Traffic-Aware Optimized', 'optimization_applied' => true,
        'optimization_proof' => $token, 'departure_mode' => 'planned', 'planned_stop_order' => [1, 0]];
}

/** Every command launches an independent guarded PHP/MySQL process. Probe barriers run on separate connections. */
function rpcRun(User $user, array $operations, bool $ordered = false): array
{
    $barrier = 'rp-'.Str::uuid();
    foreach (['start', 'locked', 'attempt', 'probed'] as $phase) {
        DatabaseBarrier::reset($barrier.'-'.$phase);
    }
    $commands = [];
    $contexts = [];
    foreach ($operations as $participant => $operation) {
        $contexts[$participant] = ['barrier' => $barrier, 'expected_estimate_revision' => $operation['request']['expected_estimate_revision'] ?? null];
        $commands[$participant] = HarnessEnvironment::routeProvenanceWorkerCommand(base_path(), $operation['action'], [
            'route_id' => $operation['route_id'], 'user_id' => $user->id, 'request' => $operation['request'] ?? [],
            'barrier' => $barrier, 'participant' => $participant,
            'coordination' => $ordered ? $participant : 'simultaneous', 'audit_failure' => $operation['audit_failure'] ?? false,
        ]);
    }
    $recorder = new RouteProvenanceRunCoordinator;
    $results = (new ConcurrentProcessRunner(environmentFactory: fn (): array => HarnessEnvironment::routeProvenanceWorkerEnvironment(),
        evidenceRecorder: fn (string $worker, array $result, ?int $pid) => $recorder->recordWorker($worker, $result, $pid, $contexts[$worker])))->run($commands, 25);
    $decoded = [];
    foreach ($results as $name => $result) {
        expect($result['exit_code'])->toBe(0)->and(trim($result['error_output']))->toBe('');
        $decoded[$name] = json_decode(trim($result['output']), true, 64, JSON_THROW_ON_ERROR);
    }
    $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    expect(array_unique(array_column($decoded, 'connection_id')))->toHaveCount(count($decoded))
        ->and(array_unique(array_column($decoded, 'pid')))->toHaveCount(count($decoded))
        ->and(array_column($decoded, 'connection_id'))->not->toContain($parentId);
    if ($ordered) {
        expect($decoded['probe']['outcome'])->toBe('row_lock_confirmed')->and($decoded['probe']['mysql_error'])->toBe(3572)
            ->and($decoded['holder']['lock_acquired'])->toBeTrue()->and($decoded['contender']['lock_acquired'])->toBeTrue();
    }

    return $decoded;
}

function rpcOrdered(User $user, RoutePlan $route, string $holderAction, array $holder, string $contenderAction, array $contender, bool $auditFailure = false): array
{
    return rpcRun($user, [
        'holder' => ['action' => $holderAction, 'route_id' => $route->id, 'request' => $holder, 'audit_failure' => $auditFailure],
        'contender' => ['action' => $contenderAction, 'route_id' => $route->id, 'request' => $contender],
        'probe' => ['action' => 'probe', 'route_id' => $route->id],
    ], true);
}

function rpcState(RoutePlan $route): array
{
    return ['route' => $route->fresh()->getAttributes(), 'stops' => $route->stops()->orderBy('id')->get()->toArray(),
        'reservation' => $route->reservation->fresh()->getAttributes(),
        'provenance' => RouteEstimateProvenance::where('route_plan_id', $route->id)->orderBy('id')->get()->map->getAttributes()->all(),
        'audits' => AuditLog::where('auditable_type', RoutePlan::class)->where('auditable_id', $route->id)->orderBy('id')->get()->toArray()];
}

function rpcAssertCurrent(RoutePlan $route, string $mode, int $revision = 1): void
{
    $route = $route->fresh()->load(['stops', 'reservation']);
    $records = RouteEstimateProvenance::where('route_plan_id', $route->id)->get();
    $current = $records->where('current_marker', 1);
    expect((int) $route->estimate_revision)->toBe($revision)->and($records)->toHaveCount($revision)->and($current)->toHaveCount(1)
        ->and($current->first()->calculation_mode)->toBe($mode)->and($current->first()->saved_route_revision)->toBe($revision)
        ->and($route->stops)->toHaveCount(2)->and($current->first()->route_snapshot_hash)->toBe((new RouteEstimateProvenanceService)->fingerprint($route))
        ->and(AuditLog::where('auditable_type', RoutePlan::class)->where('auditable_id', $route->id)->count())->toBe($revision)
        ->and((new RouteEstimateProvenanceService)->summary($route, $current->first())['status'])->toBe($mode === 'planned' ? 'current_planned' : 'manual_unverified');
}

it('permits exactly one of two simultaneous planned Saves at one observed revision', function (): void {
    $route = rpcRoute();
    $before = rpcState($route);
    $results = rpcRun($this->routeUser, [
        'a' => ['action' => 'planned', 'route_id' => $route->id, 'request' => rpcPlanned($route, $this->routeUser)],
        'b' => ['action' => 'planned', 'route_id' => $route->id, 'request' => rpcPlanned($route, $this->routeUser)],
    ]);
    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['accepted', 'revision_conflict']);
    rpcAssertCurrent($route, 'planned');
    expect($route->reservation->fresh()->getAttributes())->toBe($before['reservation']);
});

it('holds a real row lock while the competing Save becomes stale', function (): void {
    $route = rpcRoute();
    $results = rpcOrdered($this->routeUser, $route, 'planned', rpcPlanned($route, $this->routeUser), 'planned', rpcPlanned($route, $this->routeUser));
    expect($results['holder']['outcome'])->toBe('accepted')->and($results['contender']['outcome'])->toBe('revision_conflict');
    rpcAssertCurrent($route, 'planned');
});

it('rolls back every failed planned write before the waiting Save commits', function (): void {
    $route = rpcRoute();
    $reservationBefore = $route->reservation->getAttributes();
    $failed = rpcPlanned($route, $this->routeUser);
    $results = rpcOrdered($this->routeUser, $route, 'planned', $failed, 'planned', rpcPlanned($route, $this->routeUser), true);
    expect($results['holder']['outcome'])->toBe('audit_rolled_back')->and($results['contender']['outcome'])->toBe('accepted');
    rpcAssertCurrent($route, 'planned');
    $rolledBack = $results['holder']['rolled_back_ids'];
    expect($rolledBack)->toBeArray()->and(AuditLog::find($rolledBack['audit']))->toBeNull()
        ->and(RouteEstimateProvenance::find($rolledBack['provenance']))->toBeNull()
        ->and(RouteEstimateProvenance::where('calculation_uuid', $rolledBack['calculation'])->exists())->toBeFalse()
        ->and($route->stops()->whereIn('id', $rolledBack['stops'])->exists())->toBeFalse()
        ->and($route->reservation->fresh()->getAttributes())->toBe($reservationBefore);
});

it('supersedes previous evidence while keeping exactly one current record after competing Saves', function (): void {
    $route = rpcRoute();
    $this->actingAs($this->routeUser)->postJson(route('route-planning.transition', $route), rpcPlanned($route, $this->routeUser))->assertSuccessful();
    $previous = RouteEstimateProvenance::where('route_plan_id', $route->id)->sole();
    $snapshot = $previous->route_snapshot_hash;
    $route->refresh()->load(['stops', 'reservation']);
    $results = rpcRun($this->routeUser, [
        'a' => ['action' => 'planned', 'route_id' => $route->id, 'request' => rpcPlanned($route, $this->routeUser)],
        'b' => ['action' => 'planned', 'route_id' => $route->id, 'request' => rpcPlanned($route, $this->routeUser)],
    ]);
    expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['accepted', 'revision_conflict']);
    rpcAssertCurrent($route, 'planned', 2);
    expect($previous->fresh()->verification_status)->toBe('superseded')->and($previous->fresh()->current_marker)->toBeNull()
        ->and($previous->fresh()->route_snapshot_hash)->toBe($snapshot);
});

it('lets an explicitly ordered manual or planned winner invalidate the competing base revision', function (bool $manualFirst): void {
    $route = rpcRoute();
    $manual = [...rpcManual($route), 'estimated_distance' => 23.45, 'estimated_time' => 40];
    $planned = rpcPlanned($route, $this->routeUser);
    $results = rpcOrdered($this->routeUser, $route, $manualFirst ? 'manual' : 'planned', $manualFirst ? $manual : $planned,
        $manualFirst ? 'planned' : 'manual', $manualFirst ? $planned : $manual);
    expect($results['holder']['outcome'])->toBe('accepted')->and($results['contender']['outcome'])->toBe('revision_conflict')
        ->and($route->fresh()->estimated_distance)->toBe($manualFirst ? '23.45' : '12.35');
    rpcAssertCurrent($route, $manualFirst ? 'manual' : 'planned');
})->with([true, false]);

it('keeps archive and restore ahead of stale planned Saves without reviving history', function (bool $restore): void {
    $route = rpcRoute();
    $this->actingAs($this->routeUser)->postJson(route('route-planning.transition', $route), rpcPlanned($route, $this->routeUser))->assertSuccessful();
    $route->refresh()->load(['stops', 'reservation']);
    $history = RouteEstimateProvenance::where('route_plan_id', $route->id)->sole();
    $planned = rpcPlanned($route, $this->routeUser);
    if ($restore) {
        $this->actingAs($this->routeUser)->postJson(route('route-planning.archive', $route), ['expected_estimate_revision' => 1])->assertSuccessful();
        $route->refresh()->load(['stops', 'reservation']);
    }
    $beforeStops = $route->stops->toArray();
    $results = rpcOrdered($this->routeUser, $route, $restore ? 'restore' : 'archive', ['expected_estimate_revision' => (int) $route->estimate_revision], 'planned', $planned);
    expect($results['holder']['outcome'])->toBe('accepted')->and($results['contender']['outcome'])->toBe('revision_conflict')
        ->and($route->fresh()->status)->toBe($restore ? 'Draft' : 'Archived')->and((int) $route->fresh()->estimate_revision)->toBe($restore ? 3 : 2)
        ->and($route->stops()->get()->toArray())->toBe($beforeStops)
        ->and(RouteEstimateProvenance::where('route_plan_id', $route->id)->count())->toBe(1)
        ->and($history->fresh()->current_marker)->toBeNull()->and($history->fresh()->verification_status)->toBe('invalidated')
        ->and($history->fresh()->end_reason)->toBe('route_archived')
        ->and(AuditLog::where('auditable_type', RoutePlan::class)->where('auditable_id', $route->id)->count())->toBe($restore ? 3 : 2);
})->with([false, true]);

it('enforces duplicate calculation UUID uniqueness across genuinely parallel route Saves', function (): void {
    $first = rpcRoute();
    $second = rpcRoute();
    $uuid = (string) Str::uuid();
    Str::createUuidsUsing(fn (): string => $uuid);
    try {
        $firstPayload = rpcPlanned($first, $this->routeUser);
        $secondPayload = rpcPlanned($second, $this->routeUser);
    } finally {
        Str::createUuidsNormally();
    }
    (new RouteProvenanceRunCoordinator)->observeDuplicate(
        guard: function (): bool {
            IsolatedMySqlEnvironment::assertSafe();
            IsolatedMySqlEnvironment::assertSafe(IsolatedMySqlEnvironment::CONTROL_CONNECTION);
            IsolatedMySqlEnvironment::assertExternalLockOwner();

            return DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
        },
        inspect: fn (): array => [$first->id => rpcState($first), $second->id => rpcState($second)],
        execute: function (array $before) use ($first, $second, $firstPayload, $secondPayload, $uuid): bool {
            $results = rpcRun($this->routeUser, [
                'a' => ['action' => 'planned', 'route_id' => $first->id, 'request' => $firstPayload],
                'b' => ['action' => 'planned', 'route_id' => $second->id, 'request' => $secondPayload],
            ]);
            expect(collect($results)->pluck('outcome')->sort()->values()->all())->toBe(['accepted', 'uuid_conflict'])
                ->and(RouteEstimateProvenance::where('calculation_uuid', $uuid)->count())->toBe(1);
            $winner = $results['a']['outcome'] === 'accepted' ? $first : $second;
            $loser = $winner->id === $first->id ? $second : $first;
            rpcAssertCurrent($winner, 'planned');
            expect(rpcState($loser))->toBe($before[$loser->id]);
            expect(DB::table('route_estimate_provenances')->select('calculation_uuid')->whereNotNull('calculation_uuid')
                ->groupBy('calculation_uuid')->havingRaw('COUNT(*) > 1')->get())->toBeEmpty()
                ->and(DB::table('route_estimate_provenances')->select('route_plan_id')->where('current_marker', 1)
                    ->groupBy('route_plan_id')->havingRaw('COUNT(*) > 1')->get())->toBeEmpty()
                ->and(DB::transactionLevel())->toBe(0)->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();

            return true;
        },
    );
})->group(HarnessSafetyPolicy::ROUTE_DUPLICATE_GROUP);
