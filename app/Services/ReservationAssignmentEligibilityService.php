<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Models\VehicleCapability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use RuntimeException;

class ReservationAssignmentEligibilityService
{
    private const CONFLICTING_STATUSES = [
        'Pending',
        'Approved',
        'Scheduled',
    ];

    public function __construct(
        private DriverLicenseCompatibilityService $licenseCompatibility,
    ) {}

    public function requestedAt(string $scheduleDate, string $scheduleTime): CarbonImmutable
    {
        return CarbonImmutable::parse(
            "{$scheduleDate} {$scheduleTime}",
            config('app.timezone'),
        );
    }

    public function validateAndLock(
        ?int $vehicleId,
        ?int $driverId,
        string $scheduleDate,
        string $scheduleTime,
        int $durationHours,
        ?int $exceptReservationId = null,
        array $requiredCapabilityIds = [],
    ): void {
        $this->validatePairPresence($vehicleId, $driverId);

        if ($vehicleId === null) {
            return;
        }

        $vehicle = Vehicle::query()
            ->with('capabilities')
            ->lockForUpdate()
            ->find($vehicleId);
        $driver = Driver::query()
            ->with(['licenseCodes', 'qualifications'])
            ->lockForUpdate()
            ->find($driverId);

        if ($vehicle === null) {
            throw new RuntimeException('Selected vehicle was not found.');
        }

        if ($driver === null) {
            throw new RuntimeException('Selected driver was not found.');
        }

        $requestedAt = $this->requestedAt($scheduleDate, $scheduleTime);
        $requiredCapabilities = VehicleCapability::query()
            ->whereKey($requiredCapabilityIds)
            ->get();
        $message = $this->ineligibilityReason(
            $vehicle,
            $driver,
            $requestedAt,
            $requiredCapabilities,
        );

        if ($message !== null) {
            throw new RuntimeException($message);
        }

        $this->validateScheduleConflicts(
            $vehicle,
            $driver,
            $requestedAt,
            $durationHours,
            $exceptReservationId,
            true,
        );
    }

    /**
     * @return Collection<int, Vehicle>
     */
    public function eligibleVehicles(
        string $scheduleDate,
        string $scheduleTime,
        int $durationHours,
        ?Reservation $existingReservation = null,
        array $requiredCapabilityIds = [],
    ): Collection {
        $requestedAt = $this->requestedAt($scheduleDate, $scheduleTime);
        $existingVehicleId = $existingReservation?->vehicle_id;
        $requiredCapabilities = VehicleCapability::query()
            ->whereKey($requiredCapabilityIds)
            ->get();

        return Vehicle::query()
            ->with(['capabilities', 'drivers.licenseCodes', 'drivers.qualifications'])
            ->whereNull('archived_at')
            ->where(function ($query) use ($existingVehicleId) {
                $query->where('status', 'Available');

                if ($existingVehicleId !== null) {
                    $query->orWhere('id', $existingVehicleId);
                }
            })
            ->orderBy('brand')
            ->orderBy('model')
            ->get()
            ->map(function (Vehicle $vehicle) use (
                $requestedAt,
                $durationHours,
                $existingReservation,
                $requiredCapabilities,
            ): Vehicle {
                $eligibleDrivers = collect();
                $firstReason = null;

                foreach ($vehicle->drivers as $driver) {
                    $reason = $this->ineligibilityReason(
                        $vehicle,
                        $driver,
                        $requestedAt,
                        $requiredCapabilities,
                    );

                    if ($reason === null) {
                        try {
                            $this->validateScheduleConflicts(
                                $vehicle,
                                $driver,
                                $requestedAt,
                                $durationHours,
                                $existingReservation?->id,
                                false,
                            );
                        } catch (RuntimeException $exception) {
                            $reason = $exception->getMessage();
                        }
                    }

                    $isExistingUnchangedPair = $existingReservation !== null
                        && (int) $existingReservation->vehicle_id === (int) $vehicle->id
                        && (int) $existingReservation->driver_id === (int) $driver->id
                        && $existingReservation->schedule_date?->toDateString() === $requestedAt->toDateString()
                        && substr((string) $existingReservation->schedule_time, 0, 5) === $requestedAt->format('H:i');

                    if ($reason === null || $isExistingUnchangedPair) {
                        $driver->setAttribute('reservation_eligible', $reason === null);
                        $driver->setAttribute('reservation_eligibility_message', $reason);
                        $driver->setAttribute('reservation_existing_assignment', $isExistingUnchangedPair);
                        $eligibleDrivers->push($driver);
                    } elseif ($firstReason === null) {
                        $firstReason = $reason;
                    }
                }

                $vehicle->setRelation('drivers', $eligibleDrivers);
                $vehicle->setAttribute('reservation_eligibility_message', $firstReason);

                return $vehicle;
            });
    }

    private function validatePairPresence(?int $vehicleId, ?int $driverId): void
    {
        if ($vehicleId !== null && $driverId === null) {
            throw new RuntimeException('A driver must be assigned when a vehicle is selected.');
        }

        if ($vehicleId === null && $driverId !== null) {
            throw new RuntimeException('A driver cannot be assigned without a vehicle.');
        }
    }

    private function ineligibilityReason(
        Vehicle $vehicle,
        Driver $driver,
        CarbonInterface $requestedAt,
        Collection $requiredCapabilities,
    ): ?string {
        if ($vehicle->archived_at !== null) {
            return 'Selected vehicle is archived.';
        }

        if ($vehicle->status !== 'Available') {
            return "Selected vehicle is {$vehicle->status}.";
        }

        if ($driver->archived_at !== null) {
            return 'Selected driver is archived.';
        }

        if ($driver->status !== 'Available') {
            return match ($driver->status) {
                'Inactive' => 'Selected driver is inactive.',
                'On Leave' => 'Selected driver is on leave.',
                default => "Selected driver is {$driver->status}.",
            };
        }

        if ((int) $driver->assigned_vehicle_id !== (int) $vehicle->id) {
            return 'The selected driver is not assigned to the selected vehicle.';
        }

        $capability = $vehicle->capabilityAdvisory($requiredCapabilities);

        if ($capability['state'] === 'warning') {
            return $capability['message'];
        }

        $qualification = $driver->qualificationAdvisory($vehicle->vehicle_type);

        if (($qualification['is_qualified'] ?? null) !== true) {
            return 'Driver is not qualified to operate the selected vehicle type.';
        }

        $compatibility = $this->licenseCompatibility->evaluate(
            $driver,
            $vehicle,
            $requestedAt,
        );

        foreach (['license_expiry_status', 'dl_code_status', 'transmission_status', 'license_classification_status'] as $checkName) {
            $check = $compatibility[$checkName];

            if ($check['impact'] === 'blocking' || ($check['essential_gap'] ?? false)) {
                return match ($checkName) {
                    'license_expiry_status' => $check['message'],
                    'dl_code_status' => 'Driver is not licensed for the selected vehicle category.',
                    'transmission_status' => 'Driver does not have the required transmission authorization.',
                    'license_classification_status' => $check['message'],
                };
            }
        }

        return null;
    }

    private function validateScheduleConflicts(
        Vehicle $vehicle,
        Driver $driver,
        CarbonInterface $requestedAt,
        int $durationHours,
        ?int $exceptReservationId,
        bool $lock,
    ): void {
        $requestedEnd = $requestedAt->copy()->addHours($durationHours);
        $query = Reservation::query()
            ->whereNull('archived_at')
            ->whereIn('status', self::CONFLICTING_STATUSES)
            ->where(function ($query) use ($vehicle, $driver) {
                $query->where('vehicle_id', $vehicle->id)
                    ->orWhere('driver_id', $driver->id);
            })
            ->whereBetween('schedule_date', [
                $requestedAt->copy()->subDays(3)->toDateString(),
                $requestedEnd->toDateString(),
            ]);

        if ($exceptReservationId !== null) {
            $query->where('id', '!=', $exceptReservationId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        foreach ($query->get() as $reservation) {
            $existingStart = $this->requestedAt(
                $reservation->schedule_date->toDateString(),
                (string) $reservation->schedule_time,
            );
            $existingEnd = $existingStart->addHours($durationHours);

            if (! ($existingStart->lt($requestedEnd) && $requestedAt->lt($existingEnd))) {
                continue;
            }

            if ((int) $reservation->driver_id === (int) $driver->id) {
                throw new RuntimeException(
                    'Driver already has a conflicting assignment during the requested schedule.'
                );
            }

            throw new RuntimeException(
                'Vehicle already has a conflicting reservation during the requested schedule.'
            );
        }
    }
}
