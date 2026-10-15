<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Vehicle;
use RuntimeException;

class DispatchAssignmentService
{
    public function __construct(
        private DispatchSuitabilityService $suitabilityService,
    ) {}

    /** @return array{vehicle: Vehicle, driver: Driver} */
    public function validateManualPair(
        Reservation $reservation,
        int $vehicleId,
        int $driverId,
    ): array {
        $vehicle = Vehicle::query()
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->find($vehicleId);

        if (! $vehicle) {
            throw new RuntimeException('The selected vehicle is not available for dispatch.');
        }

        $driver = Driver::query()
            ->whereNull('archived_at')
            ->with(['qualifications', 'licenseCodes'])
            ->lockForUpdate()
            ->find($driverId);

        if (! $driver) {
            throw new RuntimeException('The selected driver is not available for dispatch.');
        }

        if ($vehicle->status !== 'Available') {
            throw new RuntimeException('The selected vehicle is not available.');
        }

        if ($driver->status !== 'Available') {
            throw new RuntimeException('The selected driver is not available.');
        }

        $candidate = $this->suitabilityService->evaluate(
            $reservation,
            $vehicle,
            $driver,
        );

        if (! in_array($candidate['status'], ['suitable', 'suitable_with_advisory'], true)) {
            $reason = collect([
                ...($candidate['blocking_concerns'] ?? []),
                ...($candidate['information_gaps'] ?? []),
            ])->filter()->first();

            throw new RuntimeException(
                $reason ?: 'The selected vehicle and driver pair is not suitable for this dispatch.'
            );
        }

        return compact('vehicle', 'driver');
    }
}
