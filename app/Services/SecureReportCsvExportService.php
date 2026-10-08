<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SecureReportCsvExportService
{
    /**
     * @param  array<string, mixed>  $sources
     * @param  array<string, mixed>  $filters
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>}
     */
    public function build(array $sources, string $reportType, array $filters): array
    {
        $vehicles = $this->filterVehicles(collect($sources['vehicles'] ?? []), $filters);
        $drivers = collect($sources['drivers'] ?? []);
        $reservations = $this->filterRelated(
            $this->filterDated(collect($sources['reservations'] ?? []), 'schedule_date', $filters),
            $filters
        );
        $dispatches = $this->filterRelated(
            $this->filterDated(collect($sources['dispatches'] ?? []), 'dispatch_date', $filters),
            $filters,
            true
        );
        $maintenance = $this->filterRelated(
            $this->filterDated(collect($sources['maintenance'] ?? []), 'maintenance_date', $filters),
            $filters
        );
        $fuel = $this->filterRelated(
            $this->filterDated(collect($sources['fuel'] ?? []), 'date', $filters),
            $filters
        );

        return match ($reportType) {
            'utilization' => $this->utilization($vehicles, $reservations, $dispatches),
            'trips' => $this->trips($dispatches),
            'reservations' => $this->reservations($reservations),
            'maintenance' => $this->maintenance($maintenance),
            'fuel' => $this->fuel($fuel),
            'drivers' => $this->drivers($drivers, $dispatches, $filters),
            default => $this->overview($vehicles, $reservations, $dispatches, $maintenance, $fuel),
        };
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function filterDated(Collection $records, string $dateField, array $filters): Collection
    {
        [$start, $end] = $this->dateBounds($filters);

        return $records->filter(function (array $record) use ($dateField, $start, $end): bool {
            $value = data_get($record, $dateField);

            return $value && CarbonImmutable::parse($value)->betweenIncluded($start, $end);
        })->values();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function dateBounds(array $filters): array
    {
        $today = CarbonImmutable::today();

        return match ($filters['date_range'] ?? 'last30') {
            'today' => [$today, $today->endOfDay()],
            'last7' => [$today->subDays(6), $today->endOfDay()],
            'thisMonth' => [$today->startOfMonth(), $today->endOfMonth()],
            'lastMonth' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'thisYear' => [$today->startOfYear(), $today->endOfYear()],
            'custom' => [
                CarbonImmutable::parse($filters['start_date'])->startOfDay(),
                CarbonImmutable::parse($filters['end_date'])->endOfDay(),
            ],
            default => [$today->subDays(29), $today->endOfDay()],
        };
    }

    /** @param Collection<int, array<string, mixed>> $vehicles */
    private function filterVehicles(Collection $vehicles, array $filters): Collection
    {
        return $vehicles->filter(fn (array $vehicle): bool =>
            $this->matches($filters['vehicle_id'] ?? null, data_get($vehicle, 'id'))
            && $this->matches($filters['vehicle_type'] ?? null, data_get($vehicle, 'vehicle_type'))
            && $this->matches($filters['powertrain'] ?? null, data_get($vehicle, 'powertrain'))
            && $this->matches($filters['department'] ?? null, data_get($vehicle, 'department'))
        )->values();
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function filterRelated(Collection $records, array $filters, bool $dispatch = false): Collection
    {
        return $records->filter(function (array $record) use ($filters, $dispatch): bool {
            $prefix = $dispatch ? 'reservation.' : '';
            $vehicleId = data_get($record, "{$prefix}vehicle_id")
                ?? data_get($record, "{$prefix}vehicle.id")
                ?? data_get($record, 'vehicle_id');
            $department = data_get($record, "{$prefix}department")
                ?? data_get($record, "{$prefix}route_plan.department")
                ?? data_get($record, "{$prefix}vehicle.department")
                ?? data_get($record, 'vehicle.department');

            return $this->matches($filters['vehicle_id'] ?? null, $vehicleId)
                && $this->matches($filters['department'] ?? null, $department)
                && $this->matches($filters['status'] ?? null, data_get($record, $dispatch ? 'trip_status' : 'status'))
                && $this->matches($filters['priority'] ?? null, data_get($record, "{$prefix}priority"));
        })->values();
    }

    private function matches(mixed $expected, mixed $actual): bool
    {
        return $expected === null || $expected === '' || $expected === 'all'
            || mb_strtolower((string) $expected) === mb_strtolower((string) $actual);
    }

    private function overview(Collection $vehicles, Collection $reservations, Collection $dispatches, Collection $maintenance, Collection $fuel): array
    {
        return [
            'headers' => ['Metric', 'Value'],
            'rows' => [
                ['Total Vehicles', $vehicles->count()],
                ['Available Vehicles', $vehicles->where('status', 'Available')->count()],
                ['Completed Trips', $dispatches->where('trip_status', 'Completed')->count()],
                ['Active Reservations', $reservations->whereNotIn('status', ['Completed', 'Cancelled'])->count()],
                ['Maintenance Cost', $maintenance->sum(fn (array $record) => (float) ($record['cost'] ?? 0))],
                ['Fuel Cost', $fuel->sum(fn (array $record) => (float) ($record['cost'] ?? 0))],
            ],
        ];
    }

    private function utilization(Collection $vehicles, Collection $reservations, Collection $dispatches): array
    {
        return [
            'headers' => ['Vehicle', 'Plate Number', 'Type', 'Department', 'Completed Trips', 'Reservations'],
            'rows' => $vehicles->map(function (array $vehicle) use ($reservations, $dispatches): array {
                $id = (string) $vehicle['id'];

                return [
                    $this->vehicleName($vehicle),
                    $vehicle['plate_number'] ?? '',
                    $vehicle['vehicle_type'] ?? '',
                    $vehicle['department'] ?? '',
                    $dispatches->filter(fn (array $dispatch): bool => (string) data_get($dispatch, 'reservation.vehicle_id') === $id && mb_strtolower((string) ($dispatch['trip_status'] ?? '')) === 'completed')->count(),
                    $reservations->where('vehicle_id', $vehicle['id'])->count(),
                ];
            })->values()->all(),
        ];
    }

    private function trips(Collection $records): array
    {
        return [
            'headers' => ['Trip No.', 'Date', 'Vehicle', 'Driver', 'Origin', 'Destination', 'Status'],
            'rows' => $records->map(fn (array $record): array => [
                $record['dispatch_number'] ?? $record['id'],
                $record['dispatch_date'] ?? '',
                $this->vehicleName(data_get($record, 'reservation.vehicle', [])),
                $this->driverName(data_get($record, 'reservation.driver', [])),
                data_get($record, 'reservation.route_plan.origin', data_get($record, 'reservation.pickup_location', '')),
                data_get($record, 'reservation.route_plan.destination', data_get($record, 'reservation.destination', '')),
                $record['trip_status'] ?? '',
            ])->values()->all(),
        ];
    }

    private function reservations(Collection $records): array
    {
        return [
            'headers' => ['Reservation No.', 'Date', 'Requester', 'Department', 'Vehicle', 'Purpose', 'Status'],
            'rows' => $records->map(fn (array $record): array => [
                $record['reservation_number'] ?? $record['id'],
                $record['schedule_date'] ?? '',
                $record['requester_name'] ?? $record['patient_name'] ?? $record['requested_by'] ?? '',
                $record['department'] ?? data_get($record, 'route_plan.department', ''),
                $this->vehicleName($record['vehicle'] ?? []),
                $record['purpose'] ?? data_get($record, 'route_plan.purpose', ''),
                $record['status'] ?? '',
            ])->values()->all(),
        ];
    }

    private function maintenance(Collection $records): array
    {
        return [
            'headers' => ['Maintenance No.', 'Date', 'Vehicle', 'Type', 'Status', 'Cost', 'Technician'],
            'rows' => $records->map(fn (array $record): array => [
                $record['maintenance_number'] ?? $record['id'],
                $record['maintenance_date'] ?? '',
                $this->vehicleName($record['vehicle'] ?? []),
                $record['maintenance_type'] ?? '',
                $record['status'] ?? '',
                $record['cost'] ?? 0,
                $record['technician'] ?? '',
            ])->values()->all(),
        ];
    }

    private function fuel(Collection $records): array
    {
        return [
            'headers' => ['Fuel Record No.', 'Date', 'Vehicle', 'Fuel Type', 'Quantity', 'Cost per Liter', 'Total Cost', 'Odometer', 'Fuel Station'],
            'rows' => $records->map(fn (array $record): array => [
                $record['fuel_number'] ?? $record['id'],
                $record['date'] ?? '',
                $this->vehicleName($record['vehicle'] ?? []),
                $record['fuel_type'] ?? '',
                $record['fuel_amount'] ?? 0,
                $record['cost_per_liter'] ?? 0,
                $record['cost'] ?? 0,
                $record['odometer'] ?? 0,
                $record['fuel_station'] ?? '',
            ])->values()->all(),
        ];
    }

    private function drivers(Collection $drivers, Collection $dispatches, array $filters): array
    {
        $drivers = $drivers->filter(fn (array $driver): bool =>
            $this->matches($filters['vehicle_id'] ?? null, $driver['assigned_vehicle_id'] ?? null)
            && $this->matches($filters['department'] ?? null, data_get($driver, 'vehicle.department'))
        );

        return [
            'headers' => ['Driver', 'Status', 'Assigned Vehicle', 'Completed Trips', 'Ongoing Trips', 'Cancelled Trips'],
            'rows' => $drivers->map(function (array $driver) use ($dispatches): array {
                $mine = $dispatches->filter(fn (array $dispatch): bool => (string) data_get($dispatch, 'reservation.driver_id') === (string) $driver['id']);

                return [
                    $this->driverName($driver),
                    $driver['status'] ?? '',
                    $this->vehicleName($driver['vehicle'] ?? []),
                    $mine->where('trip_status', 'Completed')->count(),
                    $mine->whereIn('trip_status', ['En Route', 'Arrived'])->count(),
                    $mine->where('trip_status', 'Cancelled')->count(),
                ];
            })->values()->all(),
        ];
    }

    /** @param array<string, mixed> $vehicle */
    private function vehicleName(array $vehicle): string
    {
        return trim(implode(' ', array_filter([
            $vehicle['brand'] ?? null,
            $vehicle['model'] ?? null,
            $vehicle['vehicle_type'] ?? null,
        ])));
    }

    /** @param array<string, mixed> $driver */
    private function driverName(array $driver): string
    {
        return trim(implode(' ', array_filter([
            $driver['first_name'] ?? null,
            $driver['last_name'] ?? null,
        ]))) ?: (string) ($driver['name'] ?? '');
    }
}
