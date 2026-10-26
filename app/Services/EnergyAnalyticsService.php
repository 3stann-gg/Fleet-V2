<?php

namespace App\Services;

use App\Models\ChargingLog;
use App\Models\Dispatch;
use App\Models\FuelLog;
use App\Models\Reservation;
use App\Models\TripLog;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EnergyAnalyticsService
{
    public function __construct(private FuelEfficiencyService $fuelEfficiencyService) {}

    /** @param array{start_date: string, end_date: string, vehicle_id?: int|null, vehicle_type?: string|null, powertrain_type?: string|null, department?: string|null} $filters */
    public function analyze(array $filters): array
    {
        $vehicles = $this->vehicleQuery($filters)
            ->get([
                'id',
                'plate_number',
                'brand',
                'model',
                'vehicle_type',
                'department',
                'powertrain_type',
                'fuel_type',
            ]);
        $vehicleIds = $vehicles->pluck('id');

        if ($vehicleIds->isEmpty()) {
            return $this->emptyResult($filters);
        }

        $distanceByVehicle = $this->distanceByVehicle($vehicleIds, $filters);
        $fuelLogs = FuelLog::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('date', [$filters['start_date'], $filters['end_date']])
            ->get();
        $fuelAnalysis = $this->fuelEfficiencyService->analyze($fuelLogs);
        $fuelByVehicle = $fuelAnalysis['fuelLogs']->groupBy('vehicle_id');
        $chargingByVehicle = $this->chargingByVehicle($vehicleIds, $filters);

        $rows = $vehicles->map(function (Vehicle $vehicle) use ($distanceByVehicle, $fuelByVehicle, $chargingByVehicle) {
            $distance = (float) ($distanceByVehicle->get($vehicle->id)?->distance_km ?? 0);
            $vehicleFuelSummary = $this->fuelEfficiencyService->summarize(
                $fuelByVehicle->get($vehicle->id, collect())
            );
            $fuelUsed = (float) $vehicleFuelSummary['total_liters_recorded'];
            $fuelCost = (float) $vehicleFuelSummary['total_fuel_cost'];
            $estimatedFuelEfficiency = $vehicleFuelSummary['aggregate_estimated_efficiency_km_per_liter'];
            $electricityUsed = (float) ($chargingByVehicle->get($vehicle->id)?->electricity_kwh ?? 0);
            $chargingCost = (float) ($chargingByVehicle->get($vehicle->id)?->charging_cost ?? 0);
            $supportsFuel = $vehicle->supportsLiquidFuel();
            $supportsCharging = $vehicle->supportsExternalCharging();
            $operatingCost = ($supportsFuel ? $fuelCost : 0) + ($supportsCharging ? $chargingCost : 0);

            return [
                'vehicle_id' => $vehicle->id,
                'vehicle' => $vehicle->display_label,
                'plate_number' => $vehicle->plate_number,
                'vehicle_type' => $vehicle->vehicle_type,
                'powertrain_type' => $vehicle->powertrain_type,
                'department' => $vehicle->department,
                'distance_km' => $distance,
                'fuel_liters' => $supportsFuel ? $fuelUsed : 0.0,
                'electricity_kwh' => $supportsCharging ? $electricityUsed : 0.0,
                'fuel_cost' => $supportsFuel ? $fuelCost : 0.0,
                'charging_cost' => $supportsCharging ? $chargingCost : 0.0,
                'total_energy_cost' => $operatingCost,
                'estimated_fuel_efficiency_km_per_liter' => $supportsFuel
                    ? $estimatedFuelEfficiency
                    : null,
                'fuel_efficiency_km_per_liter' => $supportsFuel
                    ? $estimatedFuelEfficiency
                    : null,
                'electric_efficiency_km_per_kwh' => $supportsCharging && $electricityUsed > 0
                    ? round($distance / $electricityUsed, 2)
                    : null,
                'cost_per_km' => $distance > 0 && $operatingCost > 0
                    ? round($operatingCost / $distance, 2)
                    : null,
                'valid_fuel_efficiency_interval_count' => $supportsFuel
                    ? $vehicleFuelSummary['valid_efficiency_interval_count']
                    : 0,
                'data_status' => $estimatedFuelEfficiency !== null || ($distance > 0 && $electricityUsed > 0)
                    ? 'Available'
                    : 'Insufficient Data',
            ];
        })->values();

        $distance = (float) $rows->sum('distance_km');
        $totalCost = (float) $rows->sum('total_energy_cost');

        return [
            'filters' => $filters,
            'summary' => [
                'distance_km' => round($distance, 2),
                'fuel_liters' => round((float) $rows->sum('fuel_liters'), 2),
                'electricity_kwh' => round((float) $rows->sum('electricity_kwh'), 2),
                'fuel_cost' => round((float) $rows->sum('fuel_cost'), 2),
                'charging_cost' => round((float) $rows->sum('charging_cost'), 2),
                'total_energy_cost' => round($totalCost, 2),
                'average_cost_per_km' => $distance > 0 && $totalCost > 0
                    ? round($totalCost / $distance, 2)
                    : null,
                'estimated_fuel_efficiency_km_per_liter' => $fuelAnalysis['summary']['aggregate_estimated_efficiency_km_per_liter'],
                'valid_fuel_efficiency_interval_count' => $fuelAnalysis['summary']['valid_efficiency_interval_count'],
            ],
            'vehicles' => $rows,
            'trend' => $this->trend($vehicleIds, $filters),
            'sources' => [
                'distance' => 'Trip Log',
                'fuel' => 'Fuel Transaction',
                'fuel_efficiency_distance' => 'Fuel Log Odometer Interval',
                'electricity' => 'Charging Transaction',
            ],
        ];
    }

    private function vehicleQuery(array $filters): Builder
    {
        return Vehicle::query()
            ->when($filters['vehicle_id'] ?? null, fn (Builder $query, int $id) => $query->whereKey($id))
            ->when($filters['vehicle_type'] ?? null, fn (Builder $query, string $type) => $query->where('vehicle_type', $type))
            ->when($filters['powertrain_type'] ?? null, fn (Builder $query, string $type) => $query->where('powertrain_type', $type))
            ->when($filters['department'] ?? null, function (Builder $query, string $department) {
                return $department === '__unassigned__'
                    ? $query->whereNull('department')
                    : $query->where('department', $department);
            });
    }

    private function distanceByVehicle(Collection $vehicleIds, array $filters): Collection
    {
        $tripTable = (new TripLog)->getTable();
        $dispatchTable = (new Dispatch)->getTable();
        $reservationTable = (new Reservation)->getTable();

        return TripLog::query()
            ->join($dispatchTable, "{$dispatchTable}.id", '=', "{$tripTable}.dispatch_id")
            ->join($reservationTable, "{$reservationTable}.id", '=', "{$dispatchTable}.reservation_id")
            ->whereIn("{$reservationTable}.vehicle_id", $vehicleIds)
            ->where(function ($query) use ($tripTable, $filters) {
                $query->whereBetween("{$tripTable}.submitted_at", [
                    $filters['start_date'].' 00:00:00',
                    $filters['end_date'].' 23:59:59',
                ])->orWhere(function ($legacy) use ($tripTable, $filters) {
                    $legacy->whereNull("{$tripTable}.submitted_at")
                        ->whereBetween("{$tripTable}.created_at", [
                            $filters['start_date'].' 00:00:00',
                            $filters['end_date'].' 23:59:59',
                        ]);
                });
            })
            ->selectRaw("{$reservationTable}.vehicle_id, SUM({$tripTable}.distance) AS distance_km")
            ->groupBy("{$reservationTable}.vehicle_id")
            ->get()
            ->keyBy('vehicle_id');
    }

    private function chargingByVehicle(Collection $vehicleIds, array $filters): Collection
    {
        return ChargingLog::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('charging_date', [$filters['start_date'], $filters['end_date']])
            ->selectRaw('vehicle_id, SUM(energy_added_kwh) AS electricity_kwh, SUM(charging_cost) AS charging_cost')
            ->groupBy('vehicle_id')
            ->get()
            ->keyBy('vehicle_id');
    }

    private function trend(Collection $vehicleIds, array $filters): array
    {
        $fuel = FuelLog::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('date', [$filters['start_date'], $filters['end_date']])
            ->selectRaw('date AS period, SUM(cost) AS fuel_cost')
            ->groupBy('date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->period);
        $charging = ChargingLog::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('charging_date', [$filters['start_date'], $filters['end_date']])
            ->selectRaw('charging_date AS period, SUM(charging_cost) AS charging_cost')
            ->groupBy('charging_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->period);

        return $fuel->keys()
            ->merge($charging->keys())
            ->unique()
            ->sort()
            ->map(function (string $period) use ($fuel, $charging) {
                $fuelCost = (float) ($fuel->get($period)?->fuel_cost ?? 0);
                $chargingCost = (float) ($charging->get($period)?->charging_cost ?? 0);

                return [
                    'period' => $period,
                    'fuel_cost' => $fuelCost,
                    'charging_cost' => $chargingCost,
                    'total_cost' => $fuelCost + $chargingCost,
                ];
            })
            ->values()
            ->all();
    }

    private function emptyResult(array $filters): array
    {
        return [
            'filters' => $filters,
            'summary' => [
                'distance_km' => 0,
                'fuel_liters' => 0,
                'electricity_kwh' => 0,
                'fuel_cost' => 0,
                'charging_cost' => 0,
                'total_energy_cost' => 0,
                'average_cost_per_km' => null,
                'estimated_fuel_efficiency_km_per_liter' => null,
                'valid_fuel_efficiency_interval_count' => 0,
            ],
            'vehicles' => [],
            'trend' => [],
            'sources' => [
                'distance' => 'Trip Log',
                'fuel' => 'Fuel Transaction',
                'fuel_efficiency_distance' => 'Fuel Log Odometer Interval',
                'electricity' => 'Charging Transaction',
            ],
        ];
    }
}
