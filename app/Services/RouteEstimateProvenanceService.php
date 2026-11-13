<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Dispatch;
use App\Models\Reservation;
use App\Models\RouteEstimateProvenance;
use App\Models\RoutePlan;
use App\Models\RouteStop;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Exceptions\MathException as CastMathException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Provenance interpretation, gated controller operations and an internal manual writer. Persistence flags are
 * consistency checks, not authentication; request data must never be hydrated as trusted evidence.
 */
final class RouteEstimateProvenanceService
{
    public const SNAPSHOT_VERSION = 1;

    private const PLANNED_FIELDS = [
        'calculation_uuid', 'proof_version', 'departure_at_utc', 'hospital_timezone', 'schedule_fingerprint',
        'provider', 'routing_strategy', 'routing_options', 'traffic_model_status', 'verification_scope',
        'calculated_at_utc', 'distance_meters', 'duration_seconds', 'proof_issued_at_utc', 'proof_expires_at_utc',
    ];

    /**
     * Labels are bound by exact UTF-8 digests, avoiding another plaintext copy of potentially private labels.
     * Stops must be explicitly loaded; database IDs are deliberately excluded from occurrence identity.
     *
     * @return array{version: int, route_id: int, reservation_id: int, origin: array, destination: array, departure_date: string, departure_time: string, estimated_distance_km: ?string, estimated_duration_minutes: ?int, routing_strategy: ?string, stops: list<array>}
     */
    public function snapshot(RoutePlan $route): array
    {
        $data = $route->getAttributes();
        $this->require($route->relationLoaded('stops'), 'Route stops must be explicitly loaded.');
        $stops = $route->getRelation('stops');
        $this->require($stops instanceof Collection, 'Route stops must be a collection.');
        $routeId = $this->integer($this->field($data, 'id'), 1);
        $occurrences = [];
        foreach ($stops as $stop) {
            $this->require($stop instanceof RouteStop, 'Invalid stop model.');
            $fields = $stop->getAttributes();
            $this->require($this->integer($this->field($fields, 'route_plan_id'), 1) === $routeId, 'Stop belongs to another route.');
            $position = $this->integer($this->field($fields, 'stop_order'), 1);
            $this->require(! isset($occurrences[$position]), 'Duplicate stop position.');
            $occurrences[$position] = ['position' => $position, ...$this->point($fields, 'location', 'latitude', 'longitude')];
        }
        ksort($occurrences);
        $this->require(array_keys($occurrences) === ($occurrences === [] ? [] : range(1, count($occurrences))), 'Stop positions must be contiguous.');
        $strategy = $this->field($data, 'optimization_strategy');
        $this->require($strategy === null || (is_string($strategy) && trim($strategy) !== '' && mb_strlen($strategy) <= 100), 'Invalid routing strategy.');

        return [
            'version' => self::SNAPSHOT_VERSION,
            'route_id' => $routeId,
            'reservation_id' => $this->integer($this->field($data, 'reservation_id'), 1),
            'origin' => $this->point($data, 'origin', 'origin_latitude', 'origin_longitude'),
            'destination' => $this->point($data, 'destination', 'destination_latitude', 'destination_longitude'),
            'departure_date' => $this->date($this->field($data, 'departure_date')),
            'departure_time' => $this->time($this->field($data, 'departure_time')),
            'estimated_distance_km' => $this->nullableDecimal($this->field($data, 'estimated_distance'), 2, '99999999.99'),
            'estimated_duration_minutes' => ($minutes = $this->field($data, 'estimated_time')) === null ? null : $this->integer($minutes, 0, 4294967295),
            'routing_strategy' => $strategy,
            'stops' => array_values($occurrences),
        ];
    }

    public function fingerprint(RoutePlan $route): string
    {
        return hash('sha256', $this->canonicalJson($this->snapshot($route)));
    }

    /** Read only persisted evidence, never request-provided verification flags. */
    public function persistedSummary(RoutePlan $route): array
    {
        $installed = (new RouteEstimateProvenanceReadinessService)->installed($route->getConnection());
        $route->loadMissing(['stops', 'reservation']);
        $record = $installed ? RouteEstimateProvenance::on($route->getConnectionName())->where('route_plan_id', $route->id)->latest('id')->first() : null;

        return $this->summary($route, $record);
    }

    /** Object keys are sorted recursively; occurrence lists retain order, including repeated values. */
    public function canonicalJson(array $snapshot): string
    {
        return json_encode($this->sortKeys($snapshot), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Controller-only unit of work. The operation is trusted server code, not a request callback.
     * It preserves existing lifecycle/proof rules and may write only this route and its stops on the
     * supplied connection. Neither operation nor audit may control transactions or call providers.
     * Recording OFF still enforces integrity for every route with history or an advanced revision.
     * A managed outer transaction composes through a savepoint, never an independent commit. Callers
     * must not acquire earlier locks in reverse order; the service always acquires its own row locks.
     *
     * @param  Closure(RoutePlan, Reservation): bool  $authorize
     * @param  Closure(RoutePlan, Reservation): void  $operation
     * @param  Closure(Connection, RoutePlan, RoutePlan, array): ?AuditLog  $audit
     * @return array{route: RoutePlan, summary: array}
     */
    public function executeRouteOperation(int $routeId, string $event, mixed $expectedRevision, int $actorId, Closure $authorize, Closure $operation, Closure $audit, ?array $plannedSubmission = null): array
    {
        $this->integer($routeId, 1);
        $this->integer($actorId, 1);
        $this->require(in_array($event, ['update', 'transition', 'archive', 'restore'], true), 'Unsupported controller operation.');
        $connection = DB::connection();
        $entryLevel = $connection->transactionLevel();
        $this->require(($entryLevel > 0) === $connection->getPdo()->inTransaction(), 'Unmanaged database transaction.');

        return $this->writerTransaction($connection, function () use ($connection, $entryLevel, $routeId, $event, $expectedRevision, $actorId, $authorize, $operation, $audit, $plannedSubmission): array {
            $name = $connection->getName();
            $route = RoutePlan::on($name)->whereKey($routeId)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::on($name)->whereKey($route->reservation_id)->lockForUpdate()->firstOrFail();
            if ($authorize(clone $route, clone $reservation) !== true) {
                throw new AuthorizationException('The locked route operation was not authorized.');
            }
            $this->assertWriterTransaction($connection, $entryLevel + 1);
            $readiness = new RouteEstimateProvenanceReadinessService;
            $installed = $readiness->installed($connection);
            $recording = $readiness->recordingEnabled();
            $base = $installed ? $this->integer($this->field($route->getAttributes(), 'estimate_revision')) : null;
            $current = null;
            $hasHistory = false;
            if ($installed) {
                $records = RouteEstimateProvenance::on($name)->where('route_plan_id', $routeId)
                    ->where(fn ($query) => $query->whereNotNull('current_marker')->orWhere('verification_status', 'current'))
                    ->orderBy('id')->lockForUpdate()->get();
                $this->require($records->count() <= 1, 'Conflicting current provenance.');
                $current = $records->first();
                $latest = RouteEstimateProvenance::on($name)->where('route_plan_id', $routeId)->max('saved_route_revision');
                $hasHistory = $latest !== null;
                $this->require(! $hasHistory || $this->integer($latest, 1) <= $base, 'Provenance history exceeds the route revision.');
            }
            $protected = $recording || $hasHistory || ($base !== null && $base > 0);
            if ($protected) {
                $this->requireExpectedRevision($expectedRevision, $base);
            }
            $reservation->load(['dispatch', 'vehicle', 'driver', 'pickupHospital', 'destinationHospital']);
            $route->setRelation('reservation', $reservation);
            $route->setRelation('stops', $route->stops()->lockForUpdate()->get());
            $plannedEvidence = $plannedSubmission !== null
                ? $this->verifyPlannedSave($route, $reservation, $actorId, $event, $expectedRevision, $plannedSubmission) : null;
            $before = clone $route;
            $snapshotBefore = $protected ? $this->snapshot($before) : null;
            if ($current !== null) {
                $this->require($event !== 'restore' && in_array($this->summary($before, $current)['status'], ['current_planned', 'manual_unverified'], true), 'Current provenance is inconsistent; reconciliation is required.');
            }
            $reservationBefore = $reservation->getAttributes();
            $operation($route, $reservation);
            $this->assertWriterTransaction($connection, $entryLevel + 1);
            $route = RoutePlan::on($name)->findOrFail($routeId);
            $freshReservation = Reservation::on($name)->findOrFail($reservation->getKey());
            $this->require($freshReservation->getAttributes() === $reservationBefore && $this->integer($route->reservation_id, 1) === $reservation->getKey(), 'The operation changed reservation identity or state.');
            $route->setRelation('reservation', $freshReservation);
            $route->setRelation('stops', $route->stops()->get());
            $this->require($base === null || $this->integer($route->estimate_revision) === $base, 'The operation changed the writer-owned revision.');
            $oldStatus = $this->status($before);
            $newStatus = $this->status($route);
            $this->require(match ($event) {
                'archive' => $oldStatus !== 'Archived' && $newStatus === 'Archived',
                'restore' => $oldStatus === 'Archived' && $newStatus === 'Draft',
                'update' => ! in_array($oldStatus, ['Completed', 'Archived'], true) && $newStatus === $oldStatus,
                default => ! in_array($oldStatus, ['Completed', 'Archived'], true) && in_array($newStatus, ['Draft', 'Planned', 'Ready For Dispatch'], true),
            }, 'Unexpected route lifecycle mutation.');
            $next = $current;
            $changed = false;
            $closedId = null;
            $snapshot = null;
            if ($protected) {
                if (in_array($event, ['update', 'transition'], true)) {
                    [$normal] = $this->manualPatch($route, []);
                    $this->require($route->forceFill($normal)->save(), 'Normalized route persistence failed.');
                    $route->refresh()->setRelation('reservation', $freshReservation);
                    $route->setRelation('stops', $route->stops()->get());
                }
                $snapshot = $this->snapshot($route);
                $boundChange = $this->canonicalJson($snapshotBefore) !== $this->canonicalJson($snapshot);
                $this->require(! in_array($event, ['archive', 'restore'], true) || ! $boundChange, 'Archive/restore must not alter the estimate.');
                $changed = $plannedEvidence !== null || $boundChange || in_array($event, ['archive', 'restore'], true);
                if ($changed) {
                    $this->require($base < PHP_INT_MAX, 'Route estimate revision exhausted.');
                    $this->require(RoutePlan::on($name)->whereKey($routeId)->where('estimate_revision', $base)->update(['estimate_revision' => $base + 1]) === 1, 'Route revision advancement failed.');
                    $now = CarbonImmutable::now('UTC');
                    if ($current !== null) {
                        $this->require($now->gte($this->instant($current->getRawOriginal('accepted_at_utc'))), 'Invalid provenance closure time.');
                        $superseded = ($plannedEvidence !== null || $current->calculation_mode === 'manual') && ! in_array($event, ['archive', 'restore'], true);
                        $this->require(RouteEstimateProvenance::on($name)->whereKey($current->id)->where('current_marker', 1)->where('verification_status', 'current')->update([
                            'verification_status' => $superseded ? 'superseded' : 'invalidated', 'current_marker' => null,
                            'ended_at_utc' => $now->format('Y-m-d H:i:s.u'), 'updated_at' => $now->format('Y-m-d H:i:s.u'),
                            'end_reason' => $event === 'archive' ? 'route_archived' : ($plannedEvidence !== null ? 'planned_estimate_replaced' : ($superseded ? 'manual_estimate_replaced' : 'manual_estimate_changed')),
                        ]) === 1, 'Current provenance closure failed.');
                        $closedId = $current->id;
                    }
                    $next = $plannedEvidence !== null
                        ? $this->insertPlannedRecord($name, $route, $snapshot, $base, $actorId, $now, $plannedEvidence)
                        : ($recording && in_array($event, ['update', 'transition'], true)
                            ? $this->insertManualRecord($name, $route, $snapshot, $base, $actorId, $now) : null);
                    $route->refresh()->setRelation('reservation', $freshReservation);
                    $route->setRelation('stops', $route->stops()->get());
                }
            }
            $summary = $this->summary($route, $next);
            $context = ['protected' => $protected, 'event' => $event, 'estimate_changed' => $changed, 'source_route_revision' => $base,
                'saved_route_revision' => $protected && $changed ? $base + 1 : $base, 'provenance_id' => $next?->id, 'closed_provenance_id' => $closedId];
            $auditFloor = $protected ? (AuditLog::on($name)->max('id') ?? 0) : null;
            $savedRouteAttributes = $route->getAttributes();
            $savedProvenanceAttributes = $next?->getAttributes();
            $auditRecord = $audit($connection, $before, $route, $context);
            $this->assertWriterTransaction($connection, $entryLevel + 1);
            if ($protected) {
                $this->require($auditRecord instanceof AuditLog && $auditRecord->exists && $auditRecord->wasRecentlyCreated && $auditRecord->getConnection()->getName() === $name && $auditRecord->id > $auditFloor, 'A new same-transaction audit record is required.');
                $storedAudit = AuditLog::on($name)->findOrFail($auditRecord->id);
                $this->require($storedAudit->auditable_type === RoutePlan::class && (int) $storedAudit->auditable_id === $routeId && (int) $storedAudit->user_id === $actorId, 'Audit identity mismatch.');
                $check = RoutePlan::on($name)->findOrFail($routeId);
                $check->setRelation('stops', $check->stops()->get());
                $check->setRelation('reservation', $freshReservation);
                $this->require($check->getAttributes() === $savedRouteAttributes, 'Audit altered persisted route fields.');
                $this->require($this->integer($check->estimate_revision) === $context['saved_route_revision'] && $check->status === $newStatus && $this->canonicalJson($this->snapshot($check)) === $this->canonicalJson($snapshot), 'Audit altered route estimate state.');
                $finalCurrent = RouteEstimateProvenance::on($name)->where('route_plan_id', $routeId)
                    ->where(fn ($query) => $query->whereNotNull('current_marker')->orWhere('verification_status', 'current'))->get();
                $this->require($finalCurrent->count() === ($next === null ? 0 : 1)
                    && ($next === null || ($finalCurrent->first()->getAttributes() === $savedProvenanceAttributes && $this->summary($check, $finalCurrent->first()) === $summary)), 'Current provenance changed unexpectedly.');
            }
            $route->load(['reservation.vehicle', 'reservation.driver', 'stops']);

            return ['route' => $route, 'summary' => $summary];
        });
    }

    /** Creation/duplication remain unrecorded in this phase; they cannot inherit source provenance. */
    public function createUnrecordedRoute(Closure $operation): RoutePlan
    {
        $connection = DB::connection();
        $entryLevel = $connection->transactionLevel();
        $this->require(($entryLevel > 0) === $connection->getPdo()->inTransaction(), 'Unmanaged database transaction.');

        return $this->writerTransaction($connection, function () use ($connection, $entryLevel, $operation): RoutePlan {
            $installed = (new RouteEstimateProvenanceReadinessService)->installed($connection);
            $route = $operation();
            $this->assertWriterTransaction($connection, $entryLevel + 1);
            $this->require($route instanceof RoutePlan && $route->exists && $route->getConnection()->getName() === $connection->getName(), 'Invalid new route operation.');
            if ($installed) {
                $this->require($this->integer($route->fresh()->estimate_revision) === 0 && ! RouteEstimateProvenance::on($connection->getName())->where('route_plan_id', $route->id)->exists(), 'New routes cannot inherit provenance.');
            }

            return $route;
        });
    }

    private function requireExpectedRevision(mixed $expected, int $actual): void
    {
        try {
            $value = $this->integer($expected);
        } catch (InvalidArgumentException|MathException) {
            throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_ESTIMATE_REVISION_REQUIRED', 'message' => 'Reload the route and submit its expected estimate revision.'], 422));
        }
        if ($value !== $actual) {
            throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_ESTIMATE_REVISION_STALE', 'message' => 'This route changed. Reload it before saving.'], 409));
        }
    }

    /** Authorization belongs to the caller; Save must supply fresh context under Route -> Reservation locks. */
    public function plannedContext(RoutePlan $route, Reservation $reservation, int $actor, string $operation, int $revision, string $targetStatus): array
    {
        return ['user_id' => $actor, 'reservation_id' => (int) $reservation->id, 'route_id' => (int) $route->id,
            'route_reservation_id' => (int) $route->reservation_id, 'route_status' => $route->status,
            'intended_operation' => $operation, 'expected_estimate_revision' => $revision, 'target_status' => $targetStatus,
            'schedule_date' => $reservation->getRawOriginal('schedule_date'), 'schedule_time' => $reservation->getRawOriginal('schedule_time')];
    }

    /** @param array{token: string, target_status: string, route: array} $submission */
    public function verifyPlannedSave(RoutePlan $route, Reservation $reservation, int $actor, string $operation, mixed $revision, array $submission): array
    {
        $proofs = app(PlannedRouteProofService::class);
        if (! $proofs->capabilities()['save'] || ! (new RouteEstimateProvenanceReadinessService)->recordingEnabled()) {
            throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_PLANNED_SAVE_DISABLED', 'message' => 'Planned departure saving is not enabled.'], 422));
        }
        (new RouteEstimateProvenanceReadinessService)->installed($route->getConnection());
        $this->requireExpectedRevision($revision, $this->integer($route->estimate_revision));
        try {
            $this->require(in_array($route->status, ['Draft', 'Planned'], true) && $reservation->status === 'Approved'
                && ! $reservation->dispatch()->exists(), 'This route is no longer eligible for planned saving.');

            return $proofs->verify($submission['token'], $this->plannedContext($route, $reservation, $actor, $operation,
                $this->integer($route->estimate_revision), $submission['target_status']), $submission['route']);
        } catch (InvalidArgumentException $exception) {
            throw new HttpResponseException(response()->json(['success' => false, 'code' => 'ROUTE_PLANNED_PROOF_INVALID',
                'message' => 'The planned result is invalid or stale. Calculate the route again.'], 422));
        }
    }

    /** Only canonical, authenticated evidence from verifyPlannedSave may reach this private writer. */
    private function insertPlannedRecord(string $connection, RoutePlan $route, array $snapshot, int $base, int $actor, CarbonImmutable $now, array $proof): RouteEstimateProvenance
    {
        $points = [$snapshot['origin'], ...$snapshot['stops'], $snapshot['destination']];
        $this->require(count($points) === count($proof['coordinates']), 'Persisted planned occurrence count differs.');
        foreach ($points as $index => $point) {
            foreach (['latitude' => '90', 'longitude' => '180'] as $axis => $limit) {
                $this->require($point[$axis] === $this->decimal($proof['coordinates'][$index][$axis], 7, $limit, '-'.$limit), 'Persisted planned coordinates differ.');
            }
        }
        foreach ($snapshot['stops'] as $index => $stop) {
            $this->require($stop['label_hash'] === hash('sha256', $proof['stop_labels'][$index]), 'Persisted planned labels differ.');
        }
        $this->require($snapshot['estimated_distance_km'] === $this->decimal($proof['estimated_distance'], 2, '99999999.99')
            && $snapshot['estimated_duration_minutes'] === $proof['estimated_time'] && $snapshot['routing_strategy'] === $proof['optimization_strategy'], 'Persisted planned metrics differ.');
        $record = (new RouteEstimateProvenance)->setConnection($connection)->forceFill([
            'route_plan_id' => $route->id, 'reservation_id' => $route->reservation_id, 'calculation_mode' => 'planned',
            'calculation_uuid' => $proof['calculation_id'], 'proof_version' => 2, 'snapshot_version' => self::SNAPSHOT_VERSION,
            'departure_at_utc' => CarbonImmutable::parse($proof['departure_context']['depart_at'])->utc(),
            'hospital_timezone' => 'Asia/Manila', 'schedule_fingerprint' => $proof['schedule_fingerprint'],
            'provider' => $proof['provider'], 'routing_strategy' => $proof['optimization_strategy'], 'routing_options' => $proof['routing_context'],
            'traffic_model_status' => 'unknown', 'verification_scope' => 'requested_context_only',
            'calculated_at_utc' => CarbonImmutable::parse($proof['calculated_at'])->utc(),
            'distance_meters' => $proof['raw_metrics']['distance_meters'], 'duration_seconds' => $proof['raw_metrics']['travel_time_seconds'],
            'estimated_distance_km' => $snapshot['estimated_distance_km'], 'estimated_duration_minutes' => $snapshot['estimated_duration_minutes'],
            'proof_issued_at_utc' => CarbonImmutable::createFromTimestampUTC($proof['issued_at']),
            'proof_expires_at_utc' => CarbonImmutable::createFromTimestampUTC($proof['expires_at']),
            'route_snapshot' => $snapshot, 'route_snapshot_hash' => hash('sha256', $this->canonicalJson($snapshot)),
            'source_route_revision' => $base, 'saved_route_revision' => $base + 1, 'verification_status' => 'current', 'current_marker' => 1,
            'accepted_by_user_id' => $actor, 'accepted_at_utc' => $now, 'ended_at_utc' => null, 'end_reason' => null,
        ]);
        $this->require($record->save(), 'Planned provenance insertion was cancelled.');
        $stored = RouteEstimateProvenance::on($connection)->findOrFail($record->id);
        $route->refresh()->load(['stops', 'reservation']);
        $this->require($this->summary($route, $stored)['status'] === 'current_planned', 'Persisted planned evidence is inconsistent.');

        return $stored;
    }

    /** @param array<string, mixed> $snapshot */
    private function insertManualRecord(string $connection, RoutePlan $route, array $snapshot, int $base, int $actor, CarbonImmutable $now): RouteEstimateProvenance
    {
        $record = (new RouteEstimateProvenance)->setConnection($connection)->forceFill([
            'route_plan_id' => $route->id, 'reservation_id' => $route->reservation_id, 'calculation_mode' => 'manual',
            ...array_fill_keys(self::PLANNED_FIELDS, null), 'snapshot_version' => self::SNAPSHOT_VERSION,
            'route_snapshot' => $snapshot, 'route_snapshot_hash' => hash('sha256', $this->canonicalJson($snapshot)),
            'estimated_distance_km' => $snapshot['estimated_distance_km'], 'estimated_duration_minutes' => $snapshot['estimated_duration_minutes'],
            'source_route_revision' => $base, 'saved_route_revision' => $base + 1, 'verification_status' => 'current', 'current_marker' => 1,
            'accepted_by_user_id' => $actor, 'accepted_at_utc' => $now, 'ended_at_utc' => null, 'end_reason' => null,
        ]);
        $this->require($record->save(), 'Manual provenance insertion was cancelled.');
        $stored = RouteEstimateProvenance::on($connection)->find($record->id);
        $this->require($stored instanceof RouteEstimateProvenance && $stored->calculation_mode === 'manual' && $stored->route_snapshot_hash === hash('sha256', $this->canonicalJson($snapshot)) && $stored->saved_route_revision === $base + 1, 'Manual provenance insertion failed.');

        return $stored;
    }

    /**
     * Internal foundation only; no HTTP controller invokes this method. Owns the top-level transaction
     * so locks are acquired RoutePlan -> Reservation -> provenance -> stops. Only clean top-level
     * MySQL deadlock victims may replay the entire transaction, at most three attempts.
     * The future controller must validate its request and authorize the fresh locked resources through
     * the mandatory callback. This path never accepts proofs, changes lifecycle, or promotes readiness.
     *
     * Trusted callbacks must use only the supplied connection, perform no transaction control/DDL,
     * call no providers, and emit no external effects. Authorization must be read-only and return true;
     * audit must insert and return a new matching AuditLog on this connection. They are not a sandbox.
     *
     * @param  array{route?: array<string, mixed>, stops?: list<array{location: string, latitude: mixed, longitude: mixed}>}  $changes
     * @param  Closure(RoutePlan, Reservation, User): bool  $authorize
     * @param  Closure(Connection, RoutePlan, array<string, mixed>): AuditLog  $audit
     * @return array{route_id: int, reservation_id: int, source_route_revision: int, saved_route_revision: int, estimate_changed: bool, provenance_id: ?int, closed_provenance_id: ?int, audit_id: int, summary: array}
     */
    public function writeManualEstimate(string $connectionName, int $routeId, int $reservationId, int $expectedRevision, int $actorId, array $changes, Closure $authorize, Closure $audit): array
    {
        $this->integer($routeId, 1);
        $this->integer($reservationId, 1);
        $this->integer($expectedRevision);
        $this->integer($actorId, 1);
        $this->require($connectionName !== '', 'An explicit writer connection is required.');
        $connection = DB::connection($connectionName);
        $this->require(in_array($connection->getDriverName(), ['sqlite', 'mysql'], true), 'Unsupported writer connection.');
        $this->require($connection->transactionLevel() === 0 && ! $connection->getPdo()->inTransaction(), 'The writer requires its own top-level transaction.');

        return $this->writerTransaction($connection, function () use ($connection, $connectionName, $routeId, $reservationId, $expectedRevision, $actorId, $changes, $authorize, $audit): array {
            $route = RoutePlan::on($connectionName)->whereKey($routeId)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::on($connectionName)->whereKey($route->reservation_id)->lockForUpdate()->firstOrFail();
            $actor = User::on($connectionName)->findOrFail($actorId);
            if ($authorize(clone $route, clone $reservation, clone $actor) !== true) {
                throw new AuthorizationException('The locked route operation was not authorized.');
            }
            $this->assertWriterTransaction($connection);
            $this->require($this->integer($route->reservation_id, 1) === $reservationId && $reservation->getKey() === $reservationId, 'Locked route/reservation mismatch.');
            $base = $this->integer($this->field($route->getAttributes(), 'estimate_revision'));
            $this->require($base === $expectedRevision, 'Stale route estimate revision.');
            $this->require(in_array($this->status($route), ['Draft', 'Planned'], true), 'Manual writer requires an editable Draft or Planned route.');
            $initialStatus = $this->status($route);
            $this->require($reservation->status === 'Approved' && $reservation->archived_at === null && ! Dispatch::on($connectionName)->where('reservation_id', $reservationId)->exists(), 'The reservation is no longer editable.');

            $records = RouteEstimateProvenance::on($connectionName)->where('route_plan_id', $routeId)
                ->where(fn ($query) => $query->whereNotNull('current_marker')->orWhere('verification_status', 'current'))
                ->orderBy('id')->lockForUpdate()->get();
            $this->require($records->count() <= 1, 'Conflicting current provenance.');
            $current = $records->first();
            $route->setRelation('reservation', $reservation);
            $route->setRelation('stops', $route->stops()->lockForUpdate()->get());
            if ($current !== null) {
                $this->require(in_array($this->summary($route, $current)['status'], ['current_planned', 'manual_unverified'], true), 'Current provenance is inconsistent; reconciliation is required.');
            }
            $before = $this->snapshot($route);
            [$patch, $stops, $expectedSnapshot, $unboundPatch] = $this->manualPatch($route, $changes);
            $route->forceFill($patch);
            $this->require($route->save(), 'Manual route persistence was cancelled.');
            if ($stops !== null) {
                $route->stops()->delete();
                foreach ($stops as $stop) {
                    $this->require($route->stops()->create($stop)->exists, 'Manual stop persistence failed.');
                }
            }
            $route->refresh()->setRelation('reservation', $reservation);
            $route->setRelation('stops', $route->stops()->get());
            $snapshot = $this->snapshot($route);
            $this->require($this->canonicalJson($snapshot) === $this->canonicalJson($expectedSnapshot), 'Persisted route/stops differ from the requested manual state.');
            $this->assertUnboundPatch($route, $unboundPatch);
            $this->require($snapshot['departure_date'] === $this->date($reservation->getAttributes()['schedule_date']) && $snapshot['departure_time'] === $this->time($reservation->getAttributes()['schedule_time']), 'Manual route schedule must match its locked reservation.');
            $changed = $this->canonicalJson($before) !== $this->canonicalJson($snapshot);
            $now = CarbonImmutable::now('UTC');
            $closedId = null;
            $next = $current;
            if ($changed) {
                $this->require($base < PHP_INT_MAX, 'Route estimate revision exhausted.');
                $this->require(RoutePlan::on($connectionName)->whereKey($routeId)->where('reservation_id', $reservationId)->where('estimate_revision', $base)->update(['estimate_revision' => $base + 1]) === 1, 'Route revision advancement failed.');
                if ($current !== null) {
                    $this->require($now->gte($this->instant($current->getRawOriginal('accepted_at_utc'))), 'Replacement predates current acceptance.');
                    $manual = $current->calculation_mode === 'manual';
                    $this->require(RouteEstimateProvenance::on($connectionName)->whereKey($current->id)->where('current_marker', 1)->where('verification_status', 'current')->update([
                        'verification_status' => $manual ? 'superseded' : 'invalidated', 'current_marker' => null,
                        'ended_at_utc' => $now->format('Y-m-d H:i:s.u'), 'end_reason' => $manual ? 'manual_estimate_replaced' : 'manual_estimate_changed',
                        'updated_at' => $now->format('Y-m-d H:i:s.u'),
                    ]) === 1, 'Current provenance closure failed.');
                    $closedId = $current->id;
                }
                $next = new RouteEstimateProvenance;
                $next->setConnection($connectionName)->forceFill([
                    'route_plan_id' => $routeId, 'reservation_id' => $reservationId, 'calculation_mode' => 'manual',
                    ...array_fill_keys(self::PLANNED_FIELDS, null),
                    'snapshot_version' => self::SNAPSHOT_VERSION, 'route_snapshot' => $snapshot,
                    'route_snapshot_hash' => hash('sha256', $this->canonicalJson($snapshot)),
                    'estimated_distance_km' => $snapshot['estimated_distance_km'], 'estimated_duration_minutes' => $snapshot['estimated_duration_minutes'],
                    'source_route_revision' => $base, 'saved_route_revision' => $base + 1,
                    'verification_status' => 'current', 'current_marker' => 1,
                    'accepted_by_user_id' => $actorId, 'accepted_at_utc' => $now, 'ended_at_utc' => null, 'end_reason' => null,
                ]);
                $this->require($next->save(), 'Manual provenance insertion was cancelled.');
                $inserted = RouteEstimateProvenance::on($connectionName)->find($next->getKey());
                $this->require($inserted instanceof RouteEstimateProvenance && $inserted->id !== $closedId && $inserted->calculation_mode === 'manual', 'The inserted manual provenance could not be verified as persisted.');
                $next = $inserted;
                $route->refresh()->setRelation('reservation', $reservation);
                $route->setRelation('stops', $route->stops()->get());
            }
            $result = [
                'route_id' => $routeId, 'reservation_id' => $reservationId, 'source_route_revision' => $base,
                'saved_route_revision' => $changed ? $base + 1 : $base, 'estimate_changed' => $changed,
                'provenance_id' => $next?->id, 'closed_provenance_id' => $closedId, 'audit_id' => null,
                'summary' => $this->summary($route, $next),
            ];
            $this->require(! $changed || ($next?->id !== $closedId && $result['summary']['status'] === 'manual_unverified'), 'The inserted manual provenance could not be verified as persisted.');
            $this->assertWriterTransaction($connection);
            $auditWatermark = AuditLog::on($connectionName)->max('id') ?? 0;
            $auditRecord = $audit($connection, clone $route, $result);
            $this->assertWriterTransaction($connection);
            $this->require($auditRecord instanceof AuditLog && $auditRecord->getConnectionName() === $connectionName && $auditRecord->exists && $auditRecord->wasRecentlyCreated && $auditRecord->getKey() > $auditWatermark, 'A new same-transaction audit record is required.');
            $storedAudit = AuditLog::on($connectionName)->findOrFail($auditRecord->getKey());
            $this->require($storedAudit->auditable_type === RoutePlan::class && (int) $storedAudit->auditable_id === $routeId && (int) $storedAudit->user_id === $actorId, 'Audit identity does not match the manual operation.');
            $result['audit_id'] = $storedAudit->getKey();
            $route->refresh()->setRelation('reservation', $reservation);
            $route->setRelation('stops', $route->stops()->get());
            $this->require($this->integer($route->estimate_revision) === $result['saved_route_revision'] && $this->integer($route->reservation_id, 1) === $reservationId && $this->status($route) === $initialStatus && $this->fingerprint($route) === hash('sha256', $this->canonicalJson($snapshot)), 'The audit callback changed writer-owned state.');
            $this->assertUnboundPatch($route, $unboundPatch);
            $finalCurrent = RouteEstimateProvenance::on($connectionName)->where('route_plan_id', $routeId)
                ->where(fn ($query) => $query->whereNotNull('current_marker')->orWhere('verification_status', 'current'))->get();
            $this->require($finalCurrent->count() === ($next === null ? 0 : 1) && ($next === null || ($finalCurrent->first()?->id === $next->id && $this->summary($route, $finalCurrent->first()) === $result['summary'])), 'The final current provenance is inconsistent.');

            return $result;
        });
    }

    /** @param array<string, mixed> $changes @return array{array<string, mixed>, ?list<array<string, mixed>>, array, array<string, mixed>} */
    private function manualPatch(RoutePlan $route, array $changes): array
    {
        $this->require(array_diff(array_keys($changes), ['route', 'stops']) === [], 'Unsupported manual operation fields.');
        $patch = array_key_exists('route', $changes) ? $changes['route'] : [];
        $allowed = ['origin', 'origin_latitude', 'origin_longitude', 'destination', 'destination_latitude', 'destination_longitude', 'departure_date', 'departure_time', 'estimated_distance', 'estimated_time', 'optimization_strategy', 'notes', 'purpose', 'department', 'optimization_score'];
        $this->require(is_array($patch) && array_diff(array_keys($patch), $allowed) === [], 'Unsupported manual route fields.');
        foreach ($patch as $field => $value) {
            $this->require($value === null || is_scalar($value), 'Manual fields must be scalar storage values.');
            if (in_array($field, ['notes', 'purpose', 'department'], true)) {
                $this->require($value === null || is_string($value), 'Invalid manual text.');
            }
        }
        if (array_key_exists('departure_date', $patch)) {
            $patch['departure_date'] = $this->date($patch['departure_date']);
        }
        if (array_key_exists('departure_time', $patch)) {
            $patch['departure_time'] = $this->time($patch['departure_time']);
        }
        if (array_key_exists('optimization_score', $patch)) {
            $patch['optimization_score'] = $this->nullableDecimal($patch['optimization_score'], 2, '100');
        }
        $candidate = clone $route;
        $candidate->forceFill($patch);
        if (array_key_exists('stops', $changes)) {
            $this->require(is_array($changes['stops']) && array_is_list($changes['stops']), 'Manual stops must be an occurrence list.');
            $candidateStops = [];
            foreach ($changes['stops'] as $index => $stop) {
                $this->require(is_array($stop), 'Invalid manual stop.');
                $this->keys($stop, ['location', 'latitude', 'longitude']);
                $candidateStops[] = (new RouteStop)->forceFill(['route_plan_id' => $route->getKey(), 'stop_order' => $index + 1, ...$stop]);
            }
            $candidate->setRelation('stops', new \Illuminate\Database\Eloquent\Collection($candidateStops));
        }
        $snapshot = $this->snapshot($candidate);
        $patch = [...$patch,
            'origin_latitude' => $snapshot['origin']['latitude'], 'origin_longitude' => $snapshot['origin']['longitude'],
            'destination_latitude' => $snapshot['destination']['latitude'], 'destination_longitude' => $snapshot['destination']['longitude'],
            'estimated_distance' => $snapshot['estimated_distance_km'], 'estimated_time' => $snapshot['estimated_duration_minutes'],
        ];
        $stops = null;
        if (array_key_exists('stops', $changes) && $this->canonicalJson($snapshot['stops']) !== $this->canonicalJson($this->snapshot($route)['stops'])) {
            $stops = array_map(fn (array $point, int $index): array => ['stop_order' => $index + 1, 'location' => $changes['stops'][$index]['location'], 'latitude' => $point['latitude'], 'longitude' => $point['longitude']], $snapshot['stops'], array_keys($snapshot['stops']));
        }

        $unboundPatch = [];
        foreach (['notes', 'purpose', 'department', 'optimization_score'] as $field) {
            if (array_key_exists($field, $patch)) {
                $unboundPatch[$field] = $candidate->getAttribute($field);
            }
        }

        return [$patch, $stops, $snapshot, $unboundPatch];
    }

    /** @param array<string, mixed> $expected */
    private function assertUnboundPatch(RoutePlan $route, array $expected): void
    {
        foreach ($expected as $field => $value) {
            $this->require($route->getAttribute($field) === $value, 'Persisted manual fields differ from the requested state.');
        }
    }

    private function assertWriterTransaction(Connection $connection, int $expectedLevel = 1): void
    {
        $this->require($connection->transactionLevel() === $expectedLevel && $connection->getPdo()->inTransaction(), 'The writer transaction was altered.');
    }

    /** @template T @param Closure(): T $callback @return T */
    private function writerTransaction(Connection $connection, Closure $callback): mixed
    {
        $pdo = $connection->getPdo();
        $entryLevel = $connection->transactionLevel();
        for ($attempt = 1; ; $attempt++) {
            try {
                $result = $connection->transaction($callback, attempts: 1);
            } catch (Throwable $exception) {
                if ($connection->transactionLevel() > $entryLevel) {
                    $connection->rollBack($entryLevel);
                } elseif ($entryLevel === 0 && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($entryLevel !== 0 || $attempt >= 3 || ! $exception instanceof QueryException
                    || ($exception->errorInfo[0] ?? null) !== '40001' || (int) ($exception->errorInfo[1] ?? 0) !== 1213
                    || $connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
                    throw $exception;
                }
                RouteSaveDiagnosticService::retryScheduled($attempt);

                continue;
            }
            if ($attempt > 1) {
                RouteSaveDiagnosticService::recovered($attempt);
            }

            return $result;
        }
    }

    /**
     * This summary confers neither authorization nor Save eligibility. Token expiry is checked at
     * acceptance by the writer, not against today's clock when reading retained evidence.
     *
     * @return array{status: string, label: string, reason: string, provider: ?string, traffic_model_status: ?string, verification_scope: ?string, save_eligible: false}
     */
    public function summary(RoutePlan $route, ?RouteEstimateProvenance $record = null): array
    {
        if ($record === null) {
            return $this->presentation('unrecorded', 'Historical assumptions unrecorded', 'no_provenance');
        }
        try {
            $this->require($this->persistedClean($route) && $this->persistedClean($record), 'Models must be persisted and unchanged.');
            $fields = $record->getAttributes();
            $this->integer($this->field($fields, 'id'), 1);
            $routeId = $this->integer($this->field($route->getAttributes(), 'id'), 1);
            $reservationId = $this->integer($this->field($route->getAttributes(), 'reservation_id'), 1);
            $this->require($this->integer($this->field($fields, 'route_plan_id'), 1) === $routeId && $this->integer($this->field($fields, 'reservation_id'), 1) === $reservationId, 'Provenance ownership mismatch.');
            $revision = $this->integer($this->field($route->getAttributes(), 'estimate_revision'));
            $source = $this->integer($this->field($fields, 'source_route_revision'));
            $saved = $this->integer($this->field($fields, 'saved_route_revision'), 1);
            $this->require($saved === $source + 1 && $saved <= $revision && $this->integer($this->field($fields, 'snapshot_version'), 1) === self::SNAPSHOT_VERSION, 'Invalid provenance revision or version.');
            $snapshot = $record->route_snapshot;
            $this->require(is_array($snapshot) && ($snapshot['version'] ?? null) === self::SNAPSHOT_VERSION && ($snapshot['route_id'] ?? null) === $routeId && ($snapshot['reservation_id'] ?? null) === $reservationId, 'Invalid stored snapshot identity.');
            $this->validateSnapshot($snapshot);
            $hash = $this->field($fields, 'route_snapshot_hash');
            $this->require(is_string($hash) && preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 && hash_equals($hash, hash('sha256', $this->canonicalJson($snapshot))), 'Corrupt snapshot fingerprint.');
            $mode = $this->field($fields, 'calculation_mode');
            $this->require(in_array($mode, ['planned', 'manual'], true), 'Unknown calculation mode.');
            $this->validateEvidence($record, $snapshot, $mode);
            $status = $this->field($fields, 'verification_status');
            if (in_array($status, ['superseded', 'invalidated'], true)) {
                $this->require($this->field($fields, 'current_marker') === null && is_string($this->field($fields, 'end_reason')) && trim($fields['end_reason']) !== '' && $this->instant($this->field($fields, 'ended_at_utc'))->gte($this->instant($this->field($fields, 'accepted_at_utc'))), 'Malformed historical lifecycle.');

                return $this->presentation($status, $mode === 'planned' ? 'Historical planned calculation ('.$status.')' : 'Historical manual estimate ('.$status.')', 'retained_history');
            }
            $this->require($status === 'current' && $this->integer($this->field($fields, 'current_marker'), 1, 1) === 1 && $this->field($fields, 'ended_at_utc') === null && $this->field($fields, 'end_reason') === null, 'Invalid current marker or lifecycle.');
            $this->require($this->status($route) !== 'Archived' && $saved === $revision && hash_equals($hash, $this->fingerprint($route)), 'Current snapshot is stale or archived.');
            if ($mode === 'manual') {
                return $this->presentation('manual_unverified', 'Manually supplied / unverified', 'manual_evidence');
            }
            $this->validateSchedule($route, $record, $snapshot);

            return $this->presentation('current_planned', 'Recorded planned calculation — requested context only', 'matching_server_evidence', 'TomTom', $record->traffic_model_status, 'requested_context_only');
        } catch (InvalidArgumentException|JsonException|MathException|CastMathException) {
            return $this->presentation('stale_unverified', 'Unverified or stale estimate', 'inconsistent_evidence');
        }
    }

    /**
     * Returns intentions, never a trusted row payload or permission to write. Future writers must
     * authorize, lock RoutePlan then Reservation, verify proof under those locks, compare revisions,
     * close the current row, insert history and audit atomically. No provider calls under locks.
     *
     * @return array{event: string, changed: bool, source_route_revision: int, saved_route_revision: int, close: ?array, next_mode: ?string, inherit_provenance: false, persistence_allowed: false, requires: list<string>}
     */
    public function planTransition(RoutePlan $before, RoutePlan $after, ?RouteEstimateProvenance $current, string $event, int $expectedRevision, CarbonImmutable $at): array
    {
        $old = $this->snapshot($before);
        $next = $this->snapshot($after);
        $beforeStatus = $this->status($before);
        $afterStatus = $this->status($after);
        $base = $this->integer($this->field($before->getAttributes(), 'estimate_revision'));
        $this->require($base === $expectedRevision && $base < PHP_INT_MAX, 'Stale or exhausted route revision.');
        $this->require(in_array($event, ['accept_planned', 'manual_edit', 'archive', 'restore', 'duplicate'], true), 'Unknown transition.');
        $requires = ['authorization', 'route_then_reservation_locks', 'expected_revision', 'atomic_route_stops_provenance_audit'];
        $close = null;
        $mode = null;
        if ($event === 'duplicate') {
            $this->require($old['route_id'] !== $next['route_id'] && $this->integer($this->field($after->getAttributes(), 'estimate_revision')) === 0 && $current === null, 'Duplicate must start without inherited provenance.');

            return ['event' => $event, 'changed' => false, 'source_route_revision' => 0, 'saved_route_revision' => 0, 'close' => null, 'next_mode' => null, 'inherit_provenance' => false, 'persistence_allowed' => false, 'requires' => $requires];
        }
        $this->require($old['route_id'] === $next['route_id'] && $old['reservation_id'] === $next['reservation_id'] && $this->integer($this->field($after->getAttributes(), 'estimate_revision')) === $base, 'Transition identity or revision mismatch.');
        if ($current !== null) {
            $this->require(in_array($this->summary($before, $current)['status'], ['current_planned', 'manual_unverified'], true), 'Current evidence must be reloaded and reconciled before transitioning.');
            $this->require($at->gte($this->instant($current->getRawOriginal('accepted_at_utc'))), 'Transition predates acceptance.');
        }
        $changed = $this->canonicalJson($old) !== $this->canonicalJson($next);
        if ($event === 'accept_planned') {
            $this->require(in_array($beforeStatus, ['Draft', 'Planned'], true) && in_array($afterStatus, ['Draft', 'Planned'], true), 'Planned acceptance is lifecycle-ineligible.');
            $changed = true;
            $mode = 'planned';
            $requires[] = 'enabled_planned_gates';
            $requires[] = 'authenticated_fresh_revision_bound_proof';
            $requires[] = 'unique_calculation_uuid';
        } elseif ($event === 'manual_edit') {
            $this->require($beforeStatus === $afterStatus && $beforeStatus !== 'Archived', 'Lifecycle changes require a separate transition.');
            $mode = $changed ? 'manual' : null;
        } elseif ($event === 'archive') {
            $this->require($beforeStatus !== 'Archived' && $afterStatus === 'Archived', 'Invalid archive transition.');
            $changed = true;
        } else {
            $this->require($beforeStatus === 'Archived' && $afterStatus === 'Draft' && $current === null, 'Restore cannot revive current provenance.');
            $changed = true;
        }
        if ($changed && $current !== null) {
            $manualReplacement = $event === 'manual_edit' && $current->calculation_mode === 'manual';
            $close = [
                'id' => $current->getKey(),
                'verification_status' => $event === 'accept_planned' || $manualReplacement ? 'superseded' : 'invalidated',
                'current_marker' => null, 'ended_at_utc' => $at->utc()->format('Y-m-d H:i:s.u'),
                'end_reason' => match ($event) {
                    'accept_planned' => 'recalculated', 'archive' => 'route_archived', default => $manualReplacement ? 'manual_estimate_replaced' : 'manual_estimate_changed'
                },
            ];
        }

        return ['event' => $event, 'changed' => $changed, 'source_route_revision' => $base, 'saved_route_revision' => $changed ? $base + 1 : $base, 'close' => $close, 'next_mode' => $mode, 'inherit_provenance' => false, 'persistence_allowed' => false, 'requires' => $requires];
    }

    /** @param array<string, mixed> $snapshot */
    private function validateEvidence(RouteEstimateProvenance $record, array $snapshot, string $mode): void
    {
        $fields = $record->getAttributes();
        $this->instant($this->field($fields, 'accepted_at_utc'));
        $distance = $this->nullableDecimal($this->field($fields, 'estimated_distance_km'), 2, '99999999.99');
        $duration = ($minutes = $this->field($fields, 'estimated_duration_minutes')) === null ? null : $this->integer($minutes, 0, 4294967295);
        $this->require(array_key_exists('estimated_distance_km', $snapshot) && array_key_exists('estimated_duration_minutes', $snapshot) && $snapshot['estimated_distance_km'] === $distance && $snapshot['estimated_duration_minutes'] === $duration, 'Stored metrics disagree with snapshot.');
        if ($mode === 'manual') {
            foreach (self::PLANNED_FIELDS as $field) {
                $this->require($this->field($fields, $field) === null, 'Manual estimates cannot carry planned evidence.');
            }

            return;
        }
        foreach (self::PLANNED_FIELDS as $field) {
            $this->require($this->field($fields, $field) !== null, 'Planned evidence is incomplete.');
        }
        $this->require(is_string($fields['calculation_uuid']) && Str::isUuid($fields['calculation_uuid']) && $this->integer($fields['proof_version'], 2, 2) === 2, 'Invalid calculation identity.');
        $this->require($fields['provider'] === 'TomTom' && $fields['hospital_timezone'] === 'Asia/Manila' && $fields['verification_scope'] === 'requested_context_only' && in_array($fields['traffic_model_status'], ['unknown', 'returned', 'unavailable'], true), 'Unsupported provider evidence.');
        $this->require(($snapshot['routing_strategy'] ?? null) === $fields['routing_strategy'] && is_string($fields['schedule_fingerprint']) && preg_match('/\A[a-f0-9]{64}\z/', $fields['schedule_fingerprint']) === 1, 'Invalid strategy or schedule evidence.');
        $strategy = match (true) {
            count($snapshot['stops']) >= 2 => 'TomTom Traffic-Aware Optimized',
            count($snapshot['stops']) === 1 => 'TomTom Traffic-Aware Multi-stop',
            default => 'TomTom Traffic-Aware Fastest',
        };
        $this->require($fields['routing_strategy'] === $strategy, 'Strategy does not match stop occurrences.');
        foreach ([$snapshot['origin'], ...$snapshot['stops'], $snapshot['destination']] as $point) {
            $this->require($point['latitude'] !== null && $point['longitude'] !== null, 'Planned coordinates are incomplete.');
        }
        $departure = $this->instant($fields['departure_at_utc']);
        $this->require($departure->setTimezone('Asia/Manila')->format('Y-m-d H:i:s') === $snapshot['departure_date'].' '.$snapshot['departure_time'], 'Snapshot departure differs from evidence.');
        $options = TomTomRoutingService::routingContext($departure->setTimezone('Asia/Manila')->format(\DateTimeInterface::RFC3339));
        $this->require(is_array($record->routing_options) && $this->canonicalJson($record->routing_options) === $this->canonicalJson($options), 'Routing assumptions differ.');
        $meters = $this->decimal($fields['distance_meters'], 6, '99999999999999.999999');
        $seconds = $this->decimal($fields['duration_seconds'], 6, '99999999999999.999999');
        $this->require($distance !== null && $duration !== null && (string) BigDecimal::of($meters)->dividedBy(1000, 2, RoundingMode::HalfUp) === $distance && BigDecimal::of($seconds)->dividedBy(60, 1, RoundingMode::HalfUp)->toScale(0, RoundingMode::Ceiling)->toInt() === $duration, 'Raw metrics differ from saved estimates.');
        $issued = $this->instant($fields['proof_issued_at_utc']);
        $expires = $this->instant($fields['proof_expires_at_utc']);
        $accepted = $this->instant($fields['accepted_at_utc']);
        $calculated = $this->instant($fields['calculated_at_utc']);
        $this->require($expires->equalTo($issued->addSeconds(PlannedRouteProofService::LIFETIME_SECONDS)) && $accepted->betweenIncluded($issued->subSeconds(60), $expires) && $departure->gte($accepted) && $calculated->betweenIncluded($issued->subSeconds(RouteComparisonService::FRESHNESS_SECONDS), $issued->addSecond()), 'Evidence acceptance timestamps are inconsistent.');
    }

    /** @param array<string, mixed> $snapshot */
    private function validateSchedule(RoutePlan $route, RouteEstimateProvenance $record, array $snapshot): void
    {
        $this->require($route->relationLoaded('reservation'), 'Reservation must be explicitly loaded.');
        $reservation = $route->getRelation('reservation');
        $this->require($reservation instanceof Reservation && $this->persistedClean($reservation) && $reservation->getKey() === $snapshot['reservation_id'], 'Reservation identity is invalid.');
        $fields = $reservation->getAttributes();
        $date = $this->field($fields, 'schedule_date');
        $time = $this->field($fields, 'schedule_time');
        $normalizedDate = $this->date($date);
        $normalizedTime = $this->time($time);
        $expected = hash('sha256', json_encode(['reservation_id' => $reservation->getKey(), 'schedule_date' => $date, 'schedule_time' => $time], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $departure = $this->instant($record->getRawOriginal('departure_at_utc'))->setTimezone('Asia/Manila');
        $this->require(hash_equals($expected, $record->schedule_fingerprint) && $departure->format('Y-m-d H:i:s') === $normalizedDate.' '.$normalizedTime && $snapshot['departure_date'] === $normalizedDate && $snapshot['departure_time'] === $normalizedTime, 'Reservation schedule is stale.');
    }

    /** @param array<string, mixed> $snapshot */
    private function validateSnapshot(array $snapshot): void
    {
        $this->keys($snapshot, ['version', 'route_id', 'reservation_id', 'origin', 'destination', 'departure_date', 'departure_time', 'estimated_distance_km', 'estimated_duration_minutes', 'routing_strategy', 'stops']);
        $this->require(is_array($snapshot['stops']) && array_is_list($snapshot['stops']), 'Invalid occurrence list.');
        $this->require($this->date($snapshot['departure_date']) === $snapshot['departure_date'] && $this->time($snapshot['departure_time']) === $snapshot['departure_time'], 'Noncanonical schedule.');
        $this->require($this->nullableDecimal($snapshot['estimated_distance_km'], 2, '99999999.99') === $snapshot['estimated_distance_km'], 'Noncanonical distance.');
        $this->require($snapshot['estimated_duration_minutes'] === null || $this->integer($snapshot['estimated_duration_minutes'], 0, 4294967295) === $snapshot['estimated_duration_minutes'], 'Noncanonical duration.');
        $strategy = $snapshot['routing_strategy'];
        $this->require($strategy === null || (is_string($strategy) && trim($strategy) !== '' && mb_strlen($strategy) <= 100), 'Invalid snapshot strategy.');
        foreach (['origin', 'destination'] as $name) {
            $this->snapshotPoint($snapshot[$name], false);
        }
        foreach ($snapshot['stops'] as $index => $point) {
            $this->snapshotPoint($point, true);
            $this->require($point['position'] === $index + 1, 'Noncanonical occurrence position.');
        }
    }

    private function snapshotPoint(mixed $point, bool $stop): void
    {
        $this->require(is_array($point), 'Invalid snapshot point.');
        $this->keys($point, $stop ? ['position', 'label_hash', 'latitude', 'longitude'] : ['label_hash', 'latitude', 'longitude']);
        $this->require(is_string($point['label_hash']) && preg_match('/\A[a-f0-9]{64}\z/', $point['label_hash']) === 1 && ($point['latitude'] === null) === ($point['longitude'] === null), 'Invalid point digest or pair.');
        if ($point['latitude'] !== null) {
            $this->require($this->decimal($point['latitude'], 7, '90', '-90') === $point['latitude'] && $this->decimal($point['longitude'], 7, '180', '-180') === $point['longitude'], 'Noncanonical stored coordinates.');
        }
    }

    /** @param array<array-key, mixed> $value @param list<string> $expected */
    private function keys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);
        $this->require($keys === $expected, 'Unsupported snapshot fields.');
    }

    /** @param array<string, mixed> $fields @return array{label_hash: string, latitude: ?string, longitude: ?string} */
    private function point(array $fields, string $labelKey, string $latitudeKey, string $longitudeKey): array
    {
        $label = $this->field($fields, $labelKey);
        $this->require(is_string($label) && mb_check_encoding($label, 'UTF-8') && trim($label) !== '' && mb_strlen($label) <= 255, 'Invalid location label.');
        $latitude = $this->field($fields, $latitudeKey);
        $longitude = $this->field($fields, $longitudeKey);
        $this->require(($latitude === null) === ($longitude === null), 'Coordinates must be complete pairs.');

        return ['label_hash' => hash('sha256', $label), 'latitude' => $latitude === null ? null : $this->decimal($latitude, 7, '90', '-90'), 'longitude' => $longitude === null ? null : $this->decimal($longitude, 7, '180', '-180')];
    }

    private function date(mixed $value): string
    {
        $this->require(is_string($value) && preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})(?: 00:00:00)?\z/', $value, $parts) === 1 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]), 'Invalid calendar date.');

        return substr($value, 0, 10);
    }

    private function time(mixed $value): string
    {
        $this->require(is_string($value) && preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $value) === 1, 'Invalid departure time.');

        return strlen($value) === 5 ? $value.':00' : $value;
    }

    private function instant(mixed $value): CarbonImmutable
    {
        $this->require(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?\z/', $value) === 1, 'Invalid UTC timestamp.');
        $instant = CarbonImmutable::parse($value, 'UTC');
        $this->require($instant->format('Y-m-d H:i:s') === substr($value, 0, 19), 'Invalid UTC calendar timestamp.');

        return $instant;
    }

    private function nullableDecimal(mixed $value, int $scale, string $maximum): ?string
    {
        return $value === null ? null : $this->decimal($value, $scale, $maximum);
    }

    private function decimal(mixed $value, int $scale, string $maximum, string $minimum = '0'): string
    {
        $this->require((is_int($value) || is_float($value) || is_string($value)) && (! is_float($value) || is_finite($value)) && preg_match('/\A-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]{1,2})?\z/', (string) $value) === 1 && strlen((string) $value) <= 64, 'Invalid decimal.');
        $number = BigDecimal::of((string) $value);
        $this->require($number->isGreaterThanOrEqualTo($minimum) && $number->isLessThanOrEqualTo($maximum), 'Decimal outside storage limits.');

        return (string) $number->toScale($scale, RoundingMode::HalfUp);
    }

    private function integer(mixed $value, int $minimum = 0, int $maximum = PHP_INT_MAX): int
    {
        $this->require(is_int($value) || (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1 && strlen($value) <= 20), 'Invalid integer.');
        $number = BigDecimal::of($value);
        $this->require($number->isGreaterThanOrEqualTo($minimum) && $number->isLessThanOrEqualTo($maximum), 'Integer outside storage limits.');

        return $number->toInt();
    }

    /** @param array<string, mixed> $fields */
    private function field(array $fields, string $key): mixed
    {
        $this->require(array_key_exists($key, $fields), 'Incomplete estimate fields.');

        return $fields[$key];
    }

    /** @return array<array-key, mixed> */
    private function sortKeys(array $value, int $depth = 0): array
    {
        $this->require($depth <= 16, 'Snapshot nesting is excessive.');
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $this->require(is_array($item) || is_string($item) || is_int($item) || is_bool($item) || $item === null, 'Unsupported snapshot value.');
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item, $depth + 1);
            }
        }

        return $value;
    }

    private function persistedClean(Model $model): bool
    {
        if (! $model->exists || $model->isDirty()) {
            return false;
        }
        foreach ($model->getRelations() as $relation) {
            if ($relation instanceof Collection && $relation->contains(fn (mixed $item): bool => $item instanceof Model && (! $item->exists || $item->isDirty()))) {
                return false;
            }
        }

        return true;
    }

    private function status(RoutePlan $route): string
    {
        $status = $this->field($route->getAttributes(), 'status');
        $this->require(in_array($status, RoutePlan::STATUSES, true), 'Missing or unknown route lifecycle state.');

        return $status;
    }

    /** @return array{status: string, label: string, reason: string, provider: ?string, traffic_model_status: ?string, verification_scope: ?string, save_eligible: false} */
    private function presentation(string $status, string $label, string $reason, ?string $provider = null, ?string $traffic = null, ?string $scope = null): array
    {
        return ['status' => $status, 'label' => $label, 'reason' => $reason, 'provider' => $provider, 'traffic_model_status' => $traffic, 'verification_scope' => $scope, 'save_eligible' => false];
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
