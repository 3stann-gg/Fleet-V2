<?php

namespace App\Services;

use App\Models\FuelLog;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class FuelEfficiencyService
{
    private const DECLINING_ADVISORY = 'Fuel efficiency has decreased compared with recent valid records. Review vehicle, route, load, traffic, and maintenance conditions.';

    /**
     * Add dynamically calculated interval metrics to the supplied fuel logs.
     *
     * @param  Collection<int, FuelLog>  $fuelLogs
     * @return array{fuelLogs: Collection<int, FuelLog>, summary: array<string, float|int|null>}
     */
    public function analyze(Collection $fuelLogs): array
    {
        if ($fuelLogs->isEmpty()) {
            return [
                'fuelLogs' => $fuelLogs,
                'summary' => $this->emptySummary(),
            ];
        }

        $vehicleIds = $fuelLogs->pluck('vehicle_id')->filter()->unique()->values();

        /** @var EloquentCollection<int, FuelLog> $history */
        $history = FuelLog::query()
            ->with('vehicle:id,powertrain_type,fuel_type')
            ->whereIn('vehicle_id', $vehicleIds)
            ->orderBy('date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'vehicle_id',
                'fuel_amount',
                'odometer',
                'date',
                'refuel_time',
                'created_at',
            ]);

        $metrics = $this->buildMetrics($history);

        $fuelLogs->each(function (FuelLog $fuelLog) use ($metrics): void {
            $this->applyMetrics($fuelLog, $metrics->get($fuelLog->id, $this->unavailableMetrics()));
        });

        return [
            'fuelLogs' => $fuelLogs,
            'summary' => $this->summarize($fuelLogs),
        ];
    }

    public function enrich(FuelLog $fuelLog): FuelLog
    {
        /** @var FuelLog $enriched */
        $enriched = $this->analyze(collect([$fuelLog]))['fuelLogs']->first();

        return $enriched;
    }

    /**
     * @param  Collection<int, FuelLog>  $fuelLogs
     * @return array{total_liters_recorded: float, total_fuel_cost: float, latest_valid_estimated_efficiency_km_per_liter: float|null, aggregate_estimated_efficiency_km_per_liter: float|null, valid_efficiency_interval_count: int, total_valid_interval_distance_km: float, liters_in_valid_intervals: float}
     */
    public function summarize(Collection $fuelLogs): array
    {
        $validLogs = $fuelLogs->filter(
            fn (FuelLog $fuelLog): bool => $fuelLog->getAttribute('estimated_fuel_efficiency_km_per_liter') !== null
        );
        $validDistance = (float) $validLogs->sum(
            fn (FuelLog $fuelLog): float => (float) $fuelLog->getAttribute('distance_since_previous_fuel_log_km')
        );
        $validLiters = (float) $validLogs->sum(
            fn (FuelLog $fuelLog): float => (float) $fuelLog->fuel_amount
        );
        $latestValid = $validLogs
            ->pipe(fn (Collection $logs): Collection => $this->chronologically($logs))
            ->last();

        return [
            'total_liters_recorded' => round((float) $fuelLogs->sum('fuel_amount'), 2),
            'total_fuel_cost' => round((float) $fuelLogs->sum('cost'), 2),
            'latest_valid_estimated_efficiency_km_per_liter' => $latestValid
                ? (float) $latestValid->getAttribute('estimated_fuel_efficiency_km_per_liter')
                : null,
            'aggregate_estimated_efficiency_km_per_liter' => $validLiters > 0
                ? round($validDistance / $validLiters, 2)
                : null,
            'valid_efficiency_interval_count' => $validLogs->count(),
            'total_valid_interval_distance_km' => round($validDistance, 2),
            'liters_in_valid_intervals' => round($validLiters, 2),
        ];
    }

    /**
     * @param  Collection<int, FuelLog>  $history
     * @return Collection<int, array<string, float|int|string|null>>
     */
    private function buildMetrics(Collection $history): Collection
    {
        $metrics = collect();

        foreach ($history->groupBy('vehicle_id') as $vehicleLogs) {
            $previousOdometer = null;
            $previousValidEfficiency = null;

            foreach ($this->chronologically($vehicleLogs) as $fuelLog) {
                $vehicle = $fuelLog->vehicle;

                if (! $vehicle || ! $vehicle->supportsLiquidFuel()) {
                    $metrics->put($fuelLog->id, $this->notApplicableMetrics());

                    continue;
                }

                $odometer = $fuelLog->odometer === null ? null : (float) $fuelLog->odometer;
                $liters = (float) $fuelLog->fuel_amount;

                if ($odometer === null || $liters <= 0) {
                    $metrics->put($fuelLog->id, $this->unavailableMetrics(
                        $odometer === null ? 'Missing odometer reading.' : 'Fuel liters must be greater than zero.'
                    ));

                    continue;
                }

                if ($previousOdometer === null) {
                    $metrics->put($fuelLog->id, $this->unavailableMetrics('No previous valid same-vehicle fuel log.'));
                    $previousOdometer = $odometer;

                    continue;
                }

                if ($odometer < $previousOdometer) {
                    $metrics->put($fuelLog->id, $this->unavailableMetrics('Odometer reading is lower than the previous valid same-vehicle fuel log.'));

                    continue;
                }

                $distance = $odometer - $previousOdometer;
                $previousOdometer = $odometer;

                if ($distance <= 0) {
                    $metrics->put($fuelLog->id, $this->unavailableMetrics('No positive distance since the previous valid same-vehicle fuel log.'));

                    continue;
                }

                $efficiency = $distance / $liters;
                $trend = $this->trend($efficiency, $previousValidEfficiency);

                $metrics->put($fuelLog->id, [
                    'distance_since_previous_fuel_log_km' => round($distance, 2),
                    'estimated_fuel_efficiency_km_per_liter' => round($efficiency, 2),
                    'fuel_efficiency_status' => 'Available',
                    'fuel_efficiency_reason' => null,
                    'fuel_efficiency_trend' => $trend,
                    'fuel_efficiency_advisory' => $trend === 'Declining' ? self::DECLINING_ADVISORY : null,
                ]);

                $previousValidEfficiency = $efficiency;
            }
        }

        return $metrics;
    }

    private function trend(float $efficiency, ?float $previousEfficiency): string
    {
        if ($previousEfficiency === null) {
            return 'Insufficient Data';
        }

        return match ($efficiency <=> $previousEfficiency) {
            1 => 'Improving',
            -1 => 'Declining',
            default => 'Stable',
        };
    }

    /** @param array<string, float|int|string|null> $metrics */
    private function applyMetrics(FuelLog $fuelLog, array $metrics): void
    {
        foreach ($metrics as $attribute => $value) {
            $fuelLog->setAttribute($attribute, $value);
        }
    }

    /**
     * Known times define chronology when every record for that vehicle/day has
     * one. If any historical time is missing, creation order is the only
     * non-fabricated stable fallback for that entire day.
     *
     * @param  Collection<int, FuelLog>  $fuelLogs
     * @return Collection<int, FuelLog>
     */
    private function chronologically(Collection $fuelLogs): Collection
    {
        return $fuelLogs
            ->groupBy(fn (FuelLog $fuelLog): string => $fuelLog->date?->format('Y-m-d') ?? '')
            ->sortKeys()
            ->flatMap(function (Collection $sameDayLogs): Collection {
                $hasMissingTime = $sameDayLogs->contains(
                    fn (FuelLog $fuelLog): bool => trim((string) $fuelLog->refuel_time) === ''
                );

                if ($hasMissingTime) {
                    return $sameDayLogs->sortBy(
                        fn (FuelLog $fuelLog): string => sprintf(
                            '%s %020d',
                            $fuelLog->created_at?->format('Y-m-d H:i:s.u') ?? '',
                            $fuelLog->id
                        )
                    );
                }

                return $sameDayLogs->sortBy(
                    fn (FuelLog $fuelLog): string => sprintf(
                        '%s %020d',
                        $fuelLog->refuel_time,
                        $fuelLog->id
                    )
                );
            })
            ->values();
    }

    /** @return array<string, float|int|string|null> */
    private function unavailableMetrics(string $reason = 'Required fuel interval data is unavailable.'): array
    {
        return [
            'distance_since_previous_fuel_log_km' => null,
            'estimated_fuel_efficiency_km_per_liter' => null,
            'fuel_efficiency_status' => 'Insufficient Data',
            'fuel_efficiency_reason' => $reason,
            'fuel_efficiency_trend' => 'Insufficient Data',
            'fuel_efficiency_advisory' => null,
        ];
    }

    /** @return array<string, float|int|string|null> */
    private function notApplicableMetrics(): array
    {
        return [
            ...$this->unavailableMetrics('Liquid-fuel efficiency is not applicable to this vehicle.'),
            'fuel_efficiency_status' => 'Not Applicable',
        ];
    }

    /** @return array{total_liters_recorded: float, total_fuel_cost: float, latest_valid_estimated_efficiency_km_per_liter: null, aggregate_estimated_efficiency_km_per_liter: null, valid_efficiency_interval_count: int, total_valid_interval_distance_km: float, liters_in_valid_intervals: float} */
    private function emptySummary(): array
    {
        return [
            'total_liters_recorded' => 0.0,
            'total_fuel_cost' => 0.0,
            'latest_valid_estimated_efficiency_km_per_liter' => null,
            'aggregate_estimated_efficiency_km_per_liter' => null,
            'valid_efficiency_interval_count' => 0,
            'total_valid_interval_distance_km' => 0.0,
            'liters_in_valid_intervals' => 0.0,
        ];
    }
}
