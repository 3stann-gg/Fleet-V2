<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FuelPeriodComparisonService
{
    /**
     * The caller supplies authorized transactions and authorized interval distances.
     * Each entire interval belongs to its ending transaction's calendar date.
     *
     * @param  Collection<int, array{date: string, cost: float|string, fuel_amount: float|string, distance_km: float|null}>  $records
     * @return array<string, mixed>
     */
    public function compare(Collection $records, CarbonImmutable $asOf, string $scope = 'fleet'): array
    {
        $today = $asOf->startOfDay();
        [$previous, $previousEfficiency] = $this->period($records, $today->subDays(60), $today->subDays(30));
        [$current, $currentEfficiency] = $this->period($records, $today->subDays(30), $today);
        $costChange = $this->change($previous['fuel_cost'], $current['fuel_cost'], false);
        $efficiencyChange = $this->change($previousEfficiency, $currentEfficiency, true);

        return [
            'timezone' => $today->getTimezone()->getName(),
            'as_of_date' => $today->toDateString(),
            'scope' => $scope,
            'previous' => $previous,
            'current' => $current,
            'changes' => [
                'fuel_cost_percent' => $costChange['percent'],
                'estimated_efficiency_percent' => $efficiencyChange['percent'],
                'cost_direction' => $costChange['direction'],
                'efficiency_direction' => $efficiencyChange['direction'],
                'cost_reason' => $costChange['reason'],
                'efficiency_reason' => $efficiencyChange['reason'],
            ],
            'advisory' => $efficiencyChange['direction'] === 'Declining'
                ? 'Estimated fuel efficiency declined. Review vehicle, route, load, traffic, and maintenance conditions. Refueling records do not measure exact fuel consumption.'
                : null,
        ];
    }

    /**
     * @param  Collection<int, array{date: string, cost: float|string, fuel_amount: float|string, distance_km: float|null}>  $records
     * @return array{0: array<string, mixed>, 1: float|null}
     */
    private function period(Collection $records, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $logs = $records->filter(fn (array $row): bool => $row['date'] >= $startDate && $row['date'] < $endDate);
        $valid = $logs->filter(fn (array $row): bool => $row['distance_km'] !== null
            && is_finite($row['distance_km']) && $row['distance_km'] > 0
            && is_finite((float) $row['fuel_amount']) && (float) $row['fuel_amount'] > 0);
        $distance = $valid->sum(fn (array $row): int => (int) round($row['distance_km'] * 100));
        $liters = $valid->sum(fn (array $row): int => (int) round((float) $row['fuel_amount'] * 100));
        $cost = $logs->sum(fn (array $row): int => (int) round((float) $row['cost'] * 100));
        $efficiency = $liters > 0 ? $distance / $liters : null;

        return [[
            'start_date' => $startDate,
            'end_date' => $end->subDay()->toDateString(),
            'record_count' => $logs->count(),
            'fuel_cost' => $cost / 100,
            'estimated_efficiency_km_per_liter' => $efficiency !== null ? round($efficiency, 2) : null,
            'valid_interval_count' => $valid->count(),
            'valid_distance_km' => $distance / 100,
            'valid_liters' => $liters / 100,
            'expense_reason' => $logs->isEmpty() ? 'No recorded fuel transactions in this period.' : null,
            'efficiency_reason' => $valid->isEmpty() ? 'No valid authorized liquid-fuel odometer intervals in this period.' : null,
        ], $efficiency];
    }

    /** @return array{percent: float|null, direction: string, reason: string|null} */
    private function change(?float $previous, ?float $current, bool $efficiency): array
    {
        if ($previous === null || $current === null || $previous <= 0 || ! is_finite($previous) || ! is_finite($current)) {
            return [
                'percent' => null,
                'direction' => 'Unavailable',
                'reason' => $efficiency
                    ? 'Both periods require valid estimated efficiency and a positive previous baseline.'
                    : 'A positive previous recorded fuel expense is required to calculate percentage change.',
            ];
        }

        $percent = (($current - $previous) / $previous) * 100;
        $direction = match ($current <=> $previous) {
            1 => $efficiency ? 'Improving' : 'Increase',
            -1 => $efficiency ? 'Declining' : 'Decrease',
            default => 'Stable',
        };

        return ['percent' => round($percent, 2) + 0.0, 'direction' => $direction, 'reason' => null];
    }
}
