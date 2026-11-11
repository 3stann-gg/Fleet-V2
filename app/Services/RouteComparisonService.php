<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

class RouteComparisonService
{
    public const BUDGET_SECONDS = 14;

    public const REQUEST_SECONDS = 7;

    public const FRESHNESS_SECONDS = 30;

    public function __construct(private TomTomRoutingService $routing) {}

    /**
     * @param  list<array{latitude: float|int|string, longitude: float|int|string}>  $coordinates
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    public function compare(array $coordinates, array $candidate, string $departAt, float $startedAt): array
    {
        $context = TomTomRoutingService::routingContext($departAt);
        $points = array_map(static fn (array $point): array => [
            'latitude' => (float) $point['latitude'],
            'longitude' => (float) $point['longitude'],
        ], $coordinates);
        $order = $this->candidateOrder($candidate['optimized_waypoints'] ?? null, max(count($points) - 2, 0));
        $result = [
            'status' => 'unavailable',
            'reason' => 'Comparable TomTom estimates are unavailable.',
            'provider' => 'TomTom',
            'routing_context' => $context,
            'generated_at' => now()->toISOString(),
            'expires_at' => now()->addSeconds(self::FRESHNESS_SECONDS)->toISOString(),
            'original' => null,
            'candidate' => null,
            'difference' => null,
            'source_coordinates' => $points,
            'candidate_order' => $order,
            'order_changed' => false,
            'original_calculated_at' => null,
            'candidate_calculated_at' => $candidate['calculated_at'] ?? null,
        ];
        if ($order === null || ! $this->validResult($candidate, $points, $context, true)) {
            return [...$result, 'reason' => 'The candidate has invalid metrics, ordering, timestamps, or routing assumptions.'];
        }
        $result['candidate'] = $candidate['comparison_metrics'];
        $remaining = self::BUDGET_SECONDS - $this->elapsedSeconds($startedAt);
        if ($remaining <= 0) {
            return [...$result, 'reason' => 'The comparison request budget was exhausted.'];
        }
        try {
            $original = $this->routing->calculateRoute($coordinates, $departAt, false, min(self::REQUEST_SECONDS, $remaining));
        } catch (Throwable) {
            return [...$result, 'reason' => 'The fixed-order TomTom baseline is unavailable. The candidate is still usable.'];
        }
        if ($this->elapsedSeconds($startedAt) > self::BUDGET_SECONDS || ! $this->validResult($original, $points, $context, false)) {
            return [...$result, 'reason' => 'The baseline is stale, incomplete, or has different routing assumptions.'];
        }
        $originalMetrics = $original['comparison_metrics'];
        $candidateMetrics = $candidate['comparison_metrics'];
        $difference = [];
        foreach (['distance_meters', 'travel_time_seconds'] as $metric) {
            $delta = $originalMetrics[$metric] - $candidateMetrics[$metric];
            $percent = $originalMetrics[$metric] > 0 ? ($delta / $originalMetrics[$metric]) * 100 : null;
            if (! is_finite((float) $delta) || ($percent !== null && ! is_finite($percent))) {
                return [...$result, 'reason' => 'The comparison could not produce finite differences.'];
            }
            $difference[$metric] = $delta;
            $difference[$metric.'_percent'] = $percent;
        }
        $identity = $order === ($order === [] ? [] : range(0, count($order) - 1));

        return [
            ...$result,
            'status' => 'available',
            'reason' => $identity
                ? 'Stop order unchanged. Recalculated differences are not verified savings.'
                : 'Estimated differences only. Actual travel outcomes may vary.',
            'order_changed' => ! $identity,
            'original' => $originalMetrics,
            'difference' => $difference,
            'original_calculated_at' => $original['calculated_at'],
            'generated_at' => now()->toISOString(),
            'expires_at' => now()->addSeconds(self::FRESHNESS_SECONDS)->toISOString(),
        ];
    }

    protected function elapsedSeconds(float $startedAt): float
    {
        return hrtime(true) / 1e9 - $startedAt;
    }

    /** @return list<int>|null */
    private function candidateOrder(mixed $mapping, int $count): ?array
    {
        if (! is_array($mapping) || ! array_is_list($mapping)) {
            return null;
        }
        if ($count < 2 && $mapping === []) {
            return $count === 0 ? [] : [0];
        }
        if (count($mapping) !== $count) {
            return null;
        }
        $order = [];
        foreach ($mapping as $entry) {
            $provided = is_array($entry) ? ($entry['providedIndex'] ?? null) : null;
            $optimized = is_array($entry) ? ($entry['optimizedIndex'] ?? null) : null;
            if (! is_int($provided) || ! is_int($optimized) || $provided < 0 || $provided >= $count || $optimized < 0 || $optimized >= $count || isset($order[$optimized]) || in_array($provided, $order, true)) {
                return null;
            }
            $order[$optimized] = $provided;
        }
        ksort($order);

        return array_values($order);
    }

    /** @param array<string, mixed> $result @param list<array{latitude: float, longitude: float}> $points @param array<string, mixed> $context */
    private function validResult(array $result, array $points, array $context, bool $optimized): bool
    {
        if (! is_array($result['comparison_metrics'] ?? null)) {
            return false;
        }
        if (($result['provider'] ?? null) !== 'TomTom' || ($result['routing_context'] ?? null) !== $context || ($result['request_coordinates'] ?? null) !== $points || ($result['waypoint_order_optimized'] ?? null) !== $optimized) {
            return false;
        }
        if (! $optimized && ($result['optimized_waypoints'] ?? []) !== []) {
            $count = max(count($points) - 2, 0);
            if ($this->candidateOrder($result['optimized_waypoints'], $count) !== ($count === 0 ? [] : range(0, $count - 1))) {
                return false;
            }
        }
        foreach (['distance_meters', 'travel_time_seconds'] as $metric) {
            $value = $result['comparison_metrics'][$metric] ?? null;
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < 0) {
                return false;
            }
        }
        try {
            foreach (['calculation_started_at', 'calculated_at'] as $field) {
                if (! is_string($result[$field] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T/', $result[$field])) {
                    return false;
                }
            }
            $start = CarbonImmutable::parse($result['calculation_started_at']);
            $finish = CarbonImmutable::parse($result['calculated_at']);

            return $start->lte($finish) && $start->gte(now()->subSeconds(self::FRESHNESS_SECONDS)) && $finish->lte(now()->addSecond());
        } catch (Throwable) {
            return false;
        }
    }
}
