<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Reservation;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

class DispatchSuitabilityService
{
    private const STATUS_ORDER = [
        'suitable' => 1,
        'suitable_with_advisory' => 2,
        'insufficient_information' => 3,
        'not_recommended' => 4,
    ];

    public function __construct(
        private VehicleOperationalHealthService $operationalHealth,
    ) {}

    /** @return array<string, mixed> */
    public function compare(Reservation $reservation, ?Driver $driver = null): array
    {
        $reservation->loadMissing(['requiredCapabilities', 'routePlan']);
        $driver?->loadMissing('qualifications');

        $candidates = Vehicle::query()
            ->whereNull('archived_at')
            ->with([
                'capabilities',
                'lastCompletedMaintenance',
                'latestPreTripChecklist',
                'latestPostTripChecklist',
                'activeSanitationLog',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn (Vehicle $vehicle): array => $this->evaluate($reservation, $vehicle, $driver))
            ->sortBy(fn (array $candidate): array => [
                self::STATUS_ORDER[$candidate['status']] ?? 99,
                $candidate['readiness']['state'] === 'ready' ? 0 : 1,
                count($candidate['warnings']),
                $candidate['vehicle_id'],
            ])
            ->values();

        return [
            'reservation_id' => $reservation->id,
            'priority' => $reservation->priority_label,
            'driver' => $driver === null ? null : [
                'id' => $driver->id,
                'name' => trim("{$driver->first_name} {$driver->last_name}"),
            ],
            'planned_distance_km' => $this->plannedDistance($reservation),
            'candidates' => $candidates->all(),
            'ordering' => [
                'Suitable',
                'Suitable with Advisory',
                'Insufficient Information',
                'Not Recommended',
            ],
            'decision_notice' => 'Advisory only. The authorized dispatcher retains final control.',
        ];
    }

    /** @return array<string, mixed> */
    public function evaluate(Reservation $reservation, Vehicle $vehicle, ?Driver $driver = null): array
    {
        $reservation->loadMissing(['requiredCapabilities', 'routePlan']);
        $vehicle->loadMissing([
            'capabilities',
            'lastCompletedMaintenance',
            'latestPreTripChecklist',
            'latestPostTripChecklist',
            'activeSanitationLog',
        ]);
        $driver?->loadMissing('qualifications');

        $reasons = collect();
        $warnings = collect();
        $blockingConcerns = collect();
        $informationGaps = collect();
        $essentialInformationMissing = false;

        $capability = $vehicle->capabilityAdvisory($reservation->requiredCapabilities);
        if ($capability['state'] === 'warning') {
            $blockingConcerns->push($capability['message']);
        } else {
            $reasons->push($capability['message']);
        }

        $readiness = $this->readinessCheck($vehicle);
        $this->collectCheck($readiness, $reasons, $warnings, $blockingConcerns, $informationGaps);

        $lifecycle = $this->lifecycleCheck($vehicle);
        $this->collectCheck($lifecycle, $reasons, $warnings, $blockingConcerns, $informationGaps);

        $driverCheck = $this->driverCheck($driver, $vehicle);
        $this->collectCheck($driverCheck, $reasons, $warnings, $blockingConcerns, $informationGaps);
        $essentialInformationMissing = $essentialInformationMissing || ($driverCheck['essential_gap'] ?? false);

        $energy = $this->energyCheck($reservation, $vehicle);
        $this->collectCheck($energy, $reasons, $warnings, $blockingConcerns, $informationGaps);
        $essentialInformationMissing = $essentialInformationMissing || ($energy['essential_gap'] ?? false);

        $operationalHealth = $this->operationalHealth->summarize($vehicle);
        $registration = $this->registrationCheck($vehicle, $operationalHealth['registration']);
        $insurance = $this->complianceCheck('Insurance', $operationalHealth['insurance']);
        $maintenance = $this->maintenanceCheck($operationalHealth['maintenance']);
        $battery = $this->batteryCheck($operationalHealth['battery_health']);
        $checklists = $this->checklistAndSanitationCheck($vehicle);

        foreach ([$registration, $insurance, $maintenance, $battery, $checklists] as $check) {
            $this->collectCheck($check, $reasons, $warnings, $blockingConcerns, $informationGaps);
        }

        [$status, $label] = match (true) {
            $blockingConcerns->isNotEmpty() => ['not_recommended', 'Not Recommended'],
            $essentialInformationMissing => ['insufficient_information', 'Insufficient Information'],
            $warnings->isNotEmpty() => ['suitable_with_advisory', 'Suitable with Advisory'],
            default => ['suitable', 'Suitable'],
        };

        return [
            'vehicle_id' => $vehicle->id,
            'vehicle_label' => $vehicle->display_label,
            'plate_number' => $vehicle->plate_number,
            'vehicle_type' => $vehicle->vehicle_type,
            'powertrain_type' => $vehicle->powertrain_type,
            'powertrain_label' => $vehicle->powertrain_label,
            'fuel_level_percent' => $vehicle->fuel_level_percent,
            'battery_level_percent' => $vehicle->battery_level_percent === null
                ? null
                : (float) $vehicle->battery_level_percent,
            'estimated_range_km' => $vehicle->estimated_range_km === null
                ? null
                : (float) $vehicle->estimated_range_km,
            'status' => $status,
            'label' => $label,
            'reasons' => $this->uniqueValues($reasons),
            'warnings' => $this->uniqueValues($warnings),
            'blocking_concerns' => $this->uniqueValues($blockingConcerns),
            'information_gaps' => $this->uniqueValues($informationGaps),
            'readiness' => $readiness,
            'lifecycle' => $lifecycle,
            'capabilities' => $capability,
            'driver_match' => $driverCheck,
            'energy' => $energy,
            'registration' => $registration,
            'insurance' => $insurance,
            'maintenance' => $maintenance,
            'battery_health' => $battery,
            'checklists_and_sanitation' => $checklists,
            'operational_health' => $operationalHealth,
            'selected' => (int) $reservation->vehicle_id === (int) $vehicle->id,
        ];
    }

    /** @return array<string, mixed> */
    private function readinessCheck(Vehicle $vehicle): array
    {
        $state = $vehicle->readiness_status ?? match ($vehicle->status) {
            'Available' => 'ready',
            'On Trip' => 'in_use',
            'Maintenance' => 'maintenance',
            'Out of Service' => 'unavailable',
            default => 'unknown',
        };

        return match ($state) {
            'ready' => $this->check('suitable', 'Ready', 'Vehicle readiness is suitable for dispatch.'),
            'maintenance', 'cleaning', 'unavailable', 'in_use' => $this->check(
                'blocking',
                $vehicle->readiness_label,
                "Vehicle readiness is {$vehicle->readiness_label}.",
            ),
            'charging', 'refueling' => $this->check(
                'blocking',
                $vehicle->readiness_label,
                "Vehicle is currently {$vehicle->readiness_label} and is not ready for a new assignment.",
            ),
            default => $this->check('gap', 'Not Specified', 'Vehicle readiness is not recorded.', true),
        } + ['state' => $state];
    }

    /** @return array<string, mixed> */
    private function lifecycleCheck(Vehicle $vehicle): array
    {
        return match ($vehicle->status) {
            'Available' => $this->check('suitable', 'Available', 'Vehicle lifecycle status is Available.'),
            'On Trip', 'Maintenance', 'Out of Service' => $this->check(
                'blocking',
                $vehicle->status,
                "Vehicle lifecycle status is {$vehicle->status}.",
            ),
            default => $this->check('warning', $vehicle->status ?: 'Not Recorded', 'Vehicle lifecycle status requires review.'),
        } + ['state' => $vehicle->status];
    }

    /** @return array<string, mixed> */
    private function driverCheck(?Driver $driver, Vehicle $vehicle): array
    {
        if ($driver === null) {
            return $this->check(
                'neutral',
                'Not Evaluated - No Driver Selected',
                'Driver qualification was not evaluated because no driver is selected.',
            ) + ['qualification' => null, 'license' => null];
        }

        $qualification = $driver->qualificationAdvisory($vehicle->vehicle_type);
        $license = $this->operationalHealth->driverLicenseAdvisory($driver);

        if ($license['state'] === 'expired') {
            return $this->check('blocking', 'License Expired', "Selected driver's license has expired.")
                + compact('qualification', 'license');
        }

        if ($qualification['state'] === 'warning') {
            return $this->check('blocking', $qualification['label'], $qualification['message'])
                + compact('qualification', 'license');
        }

        if ($qualification['is_qualified'] === null) {
            return $this->check('gap', 'Qualification Information Unavailable', $qualification['message'], true)
                + compact('qualification', 'license');
        }

        if (str_starts_with($license['state'], 'expiring_')) {
            return $this->check('warning', 'Qualified - License Expiring Soon', "Driver is qualified; license is {$license['label']}.")
                + compact('qualification', 'license');
        }

        if ($license['state'] === 'not_recorded') {
            return $this->check('neutral', 'Qualified - License Expiry Not Recorded', $qualification['message'])
                + compact('qualification', 'license');
        }

        return $this->check('suitable', 'Qualified', $qualification['message'])
            + compact('qualification', 'license');
    }

    /** @return array<string, mixed> */
    private function energyCheck(Reservation $reservation, Vehicle $vehicle): array
    {
        $distance = $this->plannedDistance($reservation);
        $range = $vehicle->estimated_range_km === null ? null : (float) $vehicle->estimated_range_km;

        if ($vehicle->powertrain_type === 'electric') {
            if ($range === null || $distance === null) {
                return $this->check(
                    'gap',
                    'Insufficient Information',
                    'Estimated electric range or planned route distance is unavailable.',
                    true,
                ) + ['estimated_range_km' => $range, 'planned_distance_km' => $distance];
            }

            if ($range < $distance) {
                return $this->check(
                    'blocking',
                    'Energy Warning',
                    "Estimated electric range ({$range} km) is below the planned route distance ({$distance} km).",
                ) + ['estimated_range_km' => $range, 'planned_distance_km' => $distance];
            }

            return $this->check(
                'suitable',
                'Energy Suitable',
                "Estimated electric range ({$range} km) covers the planned route distance ({$distance} km).",
            ) + ['estimated_range_km' => $range, 'planned_distance_km' => $distance];
        }

        if ($vehicle->powertrain_type === 'plug_in_hybrid') {
            $message = 'Fuel and battery information remain separate; no combined driving range is inferred.';

            return ($range !== null && $distance !== null && $range < $distance)
                ? $this->check('warning', 'Review Fuel and Battery', $message)
                    + ['estimated_range_km' => $range, 'planned_distance_km' => $distance]
                : $this->check('suitable', 'Energy Available', $message)
                    + ['estimated_range_km' => $range, 'planned_distance_km' => $distance];
        }

        return $this->check(
            'suitable',
            'Energy Information Available',
            'Recorded fuel information is available; no exact remaining range is inferred.',
        ) + ['estimated_range_km' => null, 'planned_distance_km' => $distance];
    }

    /** @param array<string, mixed> $advisory */
    private function registrationCheck(Vehicle $vehicle, array $advisory): array
    {
        if (!$vehicle->isMotorcycle() && $vehicle->registration_expiry === null) {
            return $this->check('neutral', 'Not Applicable', 'Registration expiry is not structurally required for this legacy non-motorcycle vehicle.')
                + ['state' => 'not_applicable', 'days_remaining' => null, 'date' => null];
        }

        return $this->complianceCheck('Registration', $advisory);
    }

    /** @param array<string, mixed> $advisory */
    private function complianceCheck(string $name, array $advisory): array
    {
        $check = match (true) {
            $advisory['state'] === 'expired' => $this->check('blocking', $advisory['label'], "{$name} has expired."),
            str_starts_with($advisory['state'], 'expiring_') => $this->check('warning', $advisory['label'], "{$name} is {$advisory['label']}.") ,
            $advisory['state'] === 'not_recorded' => $this->check('warning', 'Not Recorded', "{$name} expiry is not recorded."),
            default => $this->check('suitable', $advisory['label'], "{$name} is valid."),
        };

        return $check + $advisory;
    }

    /** @param array<string, mixed> $advisory */
    private function maintenanceCheck(array $advisory): array
    {
        $check = match ($advisory['state']) {
            'overdue', 'due' => $this->check('blocking', $advisory['label'], "Maintenance is {$advisory['label']}.") ,
            'due_soon' => $this->check('warning', 'Due Soon', 'Maintenance is due soon.'),
            'not_due' => $this->check('suitable', 'Not Due', 'Maintenance is not currently due.'),
            default => $this->check('neutral', 'Not Enough Data', 'Maintenance schedule information is not sufficient for a due-date check.'),
        };

        return $check + $advisory;
    }

    /** @param array<string, mixed> $advisory */
    private function batteryCheck(array $advisory): array
    {
        $check = match ($advisory['state']) {
            'good', 'not_applicable' => $this->check('suitable', $advisory['label'], "Battery health is {$advisory['label']}.") ,
            'monitor' => $this->check('warning', 'Monitor', 'Battery health should be monitored.'),
            'service_review' => $this->check('warning', 'Service Review', 'Battery health requires service review.'),
            default => $this->check('warning', 'Not Recorded', 'Battery health is not recorded.'),
        };

        return $check + $advisory;
    }

    /** @return array<string, mixed> */
    private function checklistAndSanitationCheck(Vehicle $vehicle): array
    {
        $messages = collect();

        if ($vehicle->latestPreTripChecklist?->result === 'failed') {
            $messages->push('Latest pre-trip checklist failed and remains an operational concern.');
        }

        if ($vehicle->latestPostTripChecklist?->maintenance_required || $vehicle->latestPostTripChecklist?->issue_reported) {
            $messages->push('Latest post-trip checklist reported maintenance or an operational issue.');
        }

        if ($vehicle->latestPostTripChecklist?->cleaning_required) {
            $messages->push('Latest post-trip checklist requires cleaning.');
        }

        if ($vehicle->activeSanitationLog !== null) {
            $messages->push('An active sanitation record requires completion.');
        }

        if ($messages->isNotEmpty()) {
            return $this->check('blocking', 'Attention Required', $messages->implode(' '))
                + ['items' => $messages->all()];
        }

        return $this->check('suitable', 'Clear', 'No active checklist or sanitation concern was found.')
            + ['items' => []];
    }

    /** @param Collection<int, string> $reasons @param Collection<int, string> $warnings @param Collection<int, string> $blockingConcerns @param Collection<int, string> $informationGaps */
    private function collectCheck(
        array $check,
        Collection $reasons,
        Collection $warnings,
        Collection $blockingConcerns,
        Collection $informationGaps,
    ): void {
        match ($check['impact']) {
            'blocking' => $blockingConcerns->push($check['message']),
            'warning' => $warnings->push($check['message']),
            'gap' => $informationGaps->push($check['message']),
            'suitable' => $reasons->push($check['message']),
            default => null,
        };
    }

    /** @return array{impact: string, label: string, message: string, essential_gap: bool} */
    private function check(string $impact, string $label, string $message, bool $essentialGap = false): array
    {
        return [
            'impact' => $impact,
            'label' => $label,
            'message' => $message,
            'essential_gap' => $essentialGap,
        ];
    }

    private function plannedDistance(Reservation $reservation): ?float
    {
        return $reservation->routePlan?->estimated_distance === null
            ? null
            : (float) $reservation->routePlan->estimated_distance;
    }

    /** @param Collection<int, string> $values @return array<int, string> */
    private function uniqueValues(Collection $values): array
    {
        return $values->filter()->unique()->values()->all();
    }
}
