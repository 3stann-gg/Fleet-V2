<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

final class PlannedRouteProofService
{
    public const LIFETIME_SECONDS = 1800;

    public const MAX_TOKEN_LENGTH = 10000;

    /** @param (Closure(): DateTimeInterface)|null $clock */
    public function __construct(
        private readonly Encrypter $encrypter,
        private readonly Repository $config,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Configuration readiness only; these values do not authorize an operation or enable Save acceptance.
     *
     * @return array{calculation: bool, issuance: bool, apply: bool, save: bool}
     */
    public function capabilities(): array
    {
        $calculation = $this->config->get('services.tomtom.planned_departure_enabled', false) === true;
        $issuance = $calculation && $this->config->get('services.tomtom.planned_proof_issuance_enabled', false) === true;
        $apply = $issuance && $this->config->get('services.tomtom.planned_apply_enabled', false) === true;

        return [
            'calculation' => $calculation,
            'issuance' => $issuance,
            'apply' => $apply,
            'save' => $apply && $this->config->get('services.tomtom.planned_save_enabled', false) === true,
        ];
    }

    /**
     * Callers must authorize and load the context themselves; request data is not trusted calculation evidence.
     *
     * @param  array{user_id: int, reservation_id: int, route_id: int, route_reservation_id: int, route_status: string, intended_operation: string, expected_estimate_revision: int, target_status: string, schedule_date: string, schedule_time: string}  $authorizedContext
     * @param  array{coordinates: list<array{latitude: float|int, longitude: float|int}>, stop_labels: list<string>, result: array<string, mixed>}  $trustedCalculation
     */
    public function issue(array $authorizedContext, array $trustedCalculation): string
    {
        $this->require($this->capabilities()['issuance'], 'Planned proof issuance is disabled.');
        $instant = $this->instant();
        $data = $this->payload($authorizedContext, $trustedCalculation, $instant, $instant->timestamp, (string) Str::uuid());
        $token = $this->encrypter->encrypt($this->json($data), false);
        $this->require(strlen($token) <= self::MAX_TOKEN_LENGTH, 'The planned proof exceeds the token size limit.');

        return $token;
    }

    /**
     * Validates evidence only, not authorization or Save eligibility. The Save caller must hold
     * its existing locks and pass fresh authorized context. Stateless verification does not consume tokens.
     *
     * @param  array<string, mixed>  $authorizedContext
     * @param  array<string, mixed>  $submittedRoute
     * @return array<string, mixed>
     */
    public function verify(string $token, array $authorizedContext, array $submittedRoute): array
    {
        $this->require($token !== '' && strlen($token) <= self::MAX_TOKEN_LENGTH, 'The planned proof size is invalid.');
        try {
            $data = json_decode($this->encrypter->decrypt($token, false), true, 64, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new InvalidArgumentException('The planned proof could not be authenticated.');
        }
        $this->require(is_array($data) && ($data['version'] ?? null) === 2 && ($data['proof_type'] ?? null) === 'planned_route', 'The planned proof version or type is invalid.');
        $issuedAt = $data['issued_at'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;
        $instant = $this->instant();
        $this->require(is_int($issuedAt) && is_int($expiresAt) && $issuedAt > 0 && $issuedAt <= PHP_INT_MAX - self::LIFETIME_SECONDS, 'The planned proof timestamps are invalid.');
        $this->require($expiresAt === $issuedAt + self::LIFETIME_SECONDS && $issuedAt <= $instant->timestamp + 60 && $instant->timestamp <= $expiresAt, 'The planned proof has expired or has an invalid issue time.');
        $calculationId = $data['calculation_id'] ?? null;
        $this->require(is_string($calculationId) && Str::isUuid($calculationId), 'The calculation identity is invalid.');
        $calculation = [
            'coordinates' => $data['input_coordinates'] ?? null,
            'stop_labels' => $data['input_stop_labels'] ?? null,
            'result' => [
                'provider' => $data['provider'] ?? null,
                'routing_context' => $data['routing_context'] ?? null,
                'request_coordinates' => $data['input_coordinates'] ?? null,
                'waypoint_order_optimized' => true,
                'optimized_waypoints' => $data['optimized_waypoints'] ?? null,
                'comparison_metrics' => $data['raw_metrics'] ?? null,
                'calculation_started_at' => $data['calculation_started_at'] ?? null,
                'calculated_at' => $data['calculated_at'] ?? null,
            ],
        ];
        $expected = $this->payload($authorizedContext, $calculation, $instant, $issuedAt, $calculationId);
        $keys = array_keys($data);
        $expectedKeys = array_keys($expected);
        sort($keys);
        sort($expectedKeys);
        $this->require($keys === $expectedKeys, 'The planned proof fields are incomplete or unsupported.');
        foreach ($expected as $field => $value) {
            $this->require($data[$field] === $value, 'The planned proof does not match its authoritative context or calculation.');
        }
        $this->matchSubmittedRoute($expected, $submittedRoute);

        return $expected;
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $calculation @return array<string, mixed> */
    private function payload(array $context, array $calculation, CarbonImmutable $instant, int $issuedAt, string $calculationId): array
    {
        foreach (['user_id', 'reservation_id', 'route_id', 'route_reservation_id'] as $field) {
            $this->require(is_int($context[$field] ?? null) && $context[$field] > 0, 'The authorized identity is invalid.');
        }
        $this->require($context['route_reservation_id'] === $context['reservation_id'] && in_array($context['route_status'] ?? null, ['Draft', 'Planned'], true), 'The authorized route context is ineligible.');
        $this->require(in_array($context['intended_operation'] ?? null, ['update', 'transition'], true), 'The intended planned operation is unsupported.');
        $this->require(is_int($context['expected_estimate_revision'] ?? null) && $context['expected_estimate_revision'] >= 0, 'The source estimate revision is required.');
        $this->require(in_array($context['target_status'] ?? null, ['Draft', 'Planned', 'Ready For Dispatch'], true)
            && ($context['intended_operation'] !== 'update' || $context['target_status'] === $context['route_status']), 'The intended route state is invalid.');
        $this->require(is_string($context['schedule_date'] ?? null) && is_string($context['schedule_time'] ?? null), 'The authorized schedule is missing.');
        $departure = (new RouteDepartureContextService(fn (): CarbonImmutable => $instant))->forPlannedDeparture($context['schedule_date'], $context['schedule_time']);
        $points = $this->coordinates($calculation['coordinates'] ?? null);
        $labels = $calculation['stop_labels'] ?? null;
        $stopCount = count($points) - 2;
        $this->require(is_array($labels) && array_is_list($labels) && count($labels) === $stopCount, 'The input stop occurrences are incomplete.');
        foreach ($labels as $label) {
            $this->require(is_string($label) && trim($label) !== '' && mb_strlen($label) <= 255, 'The input stop label is invalid.');
        }
        $result = $calculation['result'] ?? null;
        $this->require(is_array($result) && ($result['provider'] ?? null) === 'TomTom', 'The trusted routing provider is invalid.');
        $options = TomTomRoutingService::routingContext($departure['depart_at']);
        $this->require(($result['routing_context'] ?? null) === $options && ($result['waypoint_order_optimized'] ?? null) === true && $this->coordinates($result['request_coordinates'] ?? null) === $points, 'The routing options or input coordinates do not match.');
        $mapping = $result['optimized_waypoints'] ?? null;
        $order = $this->stopOrder($mapping, $stopCount);
        $metrics = $result['comparison_metrics'] ?? null;
        $this->require(is_array($metrics), 'The trusted provider metrics are missing.');
        $meters = $this->metric($metrics['distance_meters'] ?? null);
        $seconds = $this->metric($metrics['travel_time_seconds'] ?? null);
        $this->require($meters / 1000 <= 99999999.99 && $seconds / 60 <= 4294967295, 'The provider metrics exceed storage limits.');
        $distance = round($meters / 1000, 2);
        $minutes = (int) ceil(round($seconds / 60, 1));
        $start = $this->timestamp($result['calculation_started_at'] ?? null);
        $finish = $this->timestamp($result['calculated_at'] ?? null);
        $this->require($start->lte($finish) && $start->timestamp >= $issuedAt - RouteComparisonService::FRESHNESS_SECONDS && $finish->timestamp <= $issuedAt + 1, 'The trusted calculation timestamps are invalid or stale.');
        $orderedPoints = [$points[0]];
        foreach ($order as $index) {
            $orderedPoints[] = $points[$index + 1];
        }
        $orderedPoints[] = $points[array_key_last($points)];

        return [
            'version' => 2,
            'proof_type' => 'planned_route',
            'user_id' => $context['user_id'],
            'reservation_id' => $context['reservation_id'],
            'route_id' => $context['route_id'],
            'expected_estimate_revision' => $context['expected_estimate_revision'],
            'source_route_status' => $context['route_status'],
            'target_status' => $context['target_status'],
            'issued_at' => $issuedAt,
            'provider' => 'TomTom',
            'departure_context' => $departure,
            'schedule_fingerprint' => hash('sha256', $this->json([
                'reservation_id' => $context['reservation_id'], 'schedule_date' => $context['schedule_date'], 'schedule_time' => $context['schedule_time'],
            ])),
            'origin_latitude' => $points[0]['latitude'],
            'origin_longitude' => $points[0]['longitude'],
            'destination_latitude' => $points[array_key_last($points)]['latitude'],
            'destination_longitude' => $points[array_key_last($points)]['longitude'],
            'coordinates' => $orderedPoints,
            'stop_order' => $order,
            'stop_labels' => array_map(fn (int $index): string => $labels[$index], $order),
            'estimated_distance' => $distance,
            'estimated_time' => $minutes,
            'optimization_strategy' => match (true) {
                $stopCount >= 2 => 'TomTom Traffic-Aware Optimized',
                $stopCount === 1 => 'TomTom Traffic-Aware Multi-stop',
                default => 'TomTom Traffic-Aware Fastest',
            },
            'intended_operation' => $context['intended_operation'],
            'calculation_id' => $calculationId,
            'expires_at' => $issuedAt + self::LIFETIME_SECONDS,
            'routing_context' => $options,
            'input_coordinates' => $points,
            'input_stop_labels' => $labels,
            'optimized_waypoints' => $mapping,
            'raw_metrics' => ['distance_meters' => $meters, 'travel_time_seconds' => $seconds],
            'calculation_started_at' => $result['calculation_started_at'],
            'calculated_at' => $result['calculated_at'],
        ];
    }

    /** @return list<array{latitude: int|float, longitude: int|float}> */
    private function coordinates(mixed $coordinates): array
    {
        $this->require(is_array($coordinates) && array_is_list($coordinates) && count($coordinates) >= 2, 'The routing coordinates are incomplete.');
        $normalized = [];
        foreach ($coordinates as $point) {
            foreach (['latitude' => 90, 'longitude' => 180] as $axis => $limit) {
                $value = is_array($point) ? ($point[$axis] ?? null) : null;
                $this->require((is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= $limit, 'The routing coordinates are invalid.');
            }
            $this->require(count($point) === 2, 'The routing coordinate fields are unsupported.');
            $normalized[] = ['latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']];
        }

        return $normalized;
    }

    /** @return list<int> */
    private function stopOrder(mixed $mapping, int $count): array
    {
        $this->require(is_array($mapping) && array_is_list($mapping), 'The waypoint permutation is invalid.');
        if ($count < 2 && $mapping === []) {
            return $count === 0 ? [] : [0];
        }
        $this->require(count($mapping) === $count, 'The waypoint permutation is incomplete.');
        $order = [];
        foreach ($mapping as $entry) {
            $provided = is_array($entry) ? ($entry['providedIndex'] ?? null) : null;
            $optimized = is_array($entry) ? ($entry['optimizedIndex'] ?? null) : null;
            $this->require(is_int($provided) && is_int($optimized) && $provided >= 0 && $provided < $count && $optimized >= 0 && $optimized < $count && ! isset($order[$optimized]) && ! in_array($provided, $order, true), 'The waypoint permutation is invalid.');
            $order[$optimized] = $provided;
        }
        ksort($order);

        return array_values($order);
    }

    /** @param array<string, mixed> $proof @param array<string, mixed> $submitted */
    private function matchSubmittedRoute(array $proof, array $submitted): void
    {
        $points = $this->coordinates($submitted['coordinates'] ?? null);
        $this->require($points === $proof['coordinates'] && ($submitted['stop_labels'] ?? null) === $proof['stop_labels'] && ($submitted['stop_order'] ?? null) === $proof['stop_order'], 'The submitted stop occurrences do not match.');
        $this->require(($submitted['provider'] ?? null) === $proof['provider'] && ($submitted['optimization_strategy'] ?? null) === $proof['optimization_strategy'], 'The submitted provider or strategy does not match.');
        $this->require($this->metric($submitted['estimated_distance'] ?? null) === $proof['estimated_distance'] && ($submitted['estimated_time'] ?? null) === $proof['estimated_time'], 'The submitted route metrics do not match.');
    }

    private function metric(mixed $value): float
    {
        $this->require((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0, 'The provider metric must be finite and non-negative.');

        return (float) $value;
    }

    private function timestamp(mixed $value): CarbonImmutable
    {
        $this->require(is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/', $value) === 1, 'The calculation timestamp is malformed.');
        try {
            $timestamp = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('The calculation timestamp is malformed.');
        }
        $this->require($timestamp->format('Y-m-d\TH:i:s') === substr($value, 0, 19), 'The calculation timestamp is invalid.');

        return $timestamp;
    }

    private function instant(): CarbonImmutable
    {
        $instant = $this->clock === null ? CarbonImmutable::now() : ($this->clock)();
        if (! $instant instanceof DateTimeInterface) {
            throw new UnexpectedValueException('The proof clock must return a date-time value.');
        }

        return CarbonImmutable::instance($instant);
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw new InvalidArgumentException('The planned proof contains unsupported values.');
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
