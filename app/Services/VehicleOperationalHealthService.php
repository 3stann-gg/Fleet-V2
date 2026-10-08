<?php

namespace App\Services;

use App\Models\Driver;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

class VehicleOperationalHealthService
{
    /** @return array{state: string, label: string, days_remaining: ?int, date: ?string} */
    public function expiryAdvisory(?CarbonInterface $expiryDate): array
    {
        if ($expiryDate === null) {
            return $this->expiryResult('not_recorded', 'Not Recorded', null, null);
        }

        $daysRemaining = (int) round(
            now()->startOfDay()->diffInDays($expiryDate->copy()->startOfDay(), false),
        );

        if ($daysRemaining < 0) {
            return $this->expiryResult('expired', 'Expired', $daysRemaining, $expiryDate);
        }

        $urgentDays = (int) config('fleet_health.compliance.urgent_days', 7);
        $warningDays = (int) config('fleet_health.compliance.warning_days', 30);
        $advisoryDays = (int) config('fleet_health.compliance.advisory_days', 60);

        if ($daysRemaining <= $urgentDays) {
            return $this->expiryResult('expiring_7_days', 'Expiring within 7 days', $daysRemaining, $expiryDate);
        }

        if ($daysRemaining <= $warningDays) {
            return $this->expiryResult('expiring_30_days', 'Expiring within 30 days', $daysRemaining, $expiryDate);
        }

        if ($daysRemaining <= $advisoryDays) {
            return $this->expiryResult('expiring_60_days', 'Expiring within 60 days', $daysRemaining, $expiryDate);
        }

        return $this->expiryResult('valid', 'Valid', $daysRemaining, $expiryDate);
    }

    /** @return array{applicable: bool, state: string, label: string, value: ?float, updated_at: ?string, source: string} */
    public function batteryHealth(Vehicle $vehicle): array
    {
        if (!$vehicle->supportsExternalCharging()) {
            return [
                'applicable' => false,
                'state' => 'not_applicable',
                'label' => 'Not Applicable',
                'value' => null,
                'updated_at' => null,
                'source' => 'Manual Battery Health Entry',
            ];
        }

        if ($vehicle->battery_health_percent === null) {
            return [
                'applicable' => true,
                'state' => 'not_recorded',
                'label' => 'Not Recorded',
                'value' => null,
                'updated_at' => null,
                'source' => 'Manual Battery Health Entry',
            ];
        }

        $value = (float) $vehicle->battery_health_percent;
        $goodMinimum = (float) config('fleet_health.battery_health.good_minimum_percent', 80);
        $monitorMinimum = (float) config('fleet_health.battery_health.monitor_minimum_percent', 60);
        [$state, $label] = match (true) {
            $value >= $goodMinimum => ['good', 'Good'],
            $value >= $monitorMinimum => ['monitor', 'Monitor'],
            default => ['service_review', 'Service Review'],
        };

        return [
            'applicable' => true,
            'state' => $state,
            'label' => $label,
            'value' => $value,
            'updated_at' => $vehicle->battery_health_updated_at?->toIso8601String(),
            'source' => 'Manual Battery Health Entry',
        ];
    }

    /** @return array<string, mixed> */
    public function maintenanceAdvisory(Vehicle $vehicle): array
    {
        $maintenance = $vehicle->relationLoaded('lastCompletedMaintenance')
            ? $vehicle->lastCompletedMaintenance
            : $vehicle->lastCompletedMaintenance()->first();
        $dateRemaining = $maintenance?->next_schedule
            ? (int) round(now()->startOfDay()->diffInDays($maintenance->next_schedule->copy()->startOfDay(), false))
            : null;

        if ($dateRemaining === null) {
            return $this->maintenanceResult('not_enough_data', 'Not Enough Data', $maintenance, null);
        }

        $dueSoonDays = (int) config('fleet_health.maintenance.due_soon_days', 30);
        $isOverdue = $dateRemaining < 0;
        $isDue = $dateRemaining === 0;
        $isDueSoon = $dateRemaining > 0 && $dateRemaining <= $dueSoonDays;

        [$state, $label] = match (true) {
            $isOverdue => ['overdue', 'Overdue'],
            $isDue => ['due', 'Due'],
            $isDueSoon => ['due_soon', 'Due Soon'],
            default => ['not_due', 'Not Due'],
        };

        return $this->maintenanceResult($state, $label, $maintenance, $dateRemaining);
    }

    /** @return array<string, mixed> */
    public function summarize(Vehicle $vehicle): array
    {
        $vehicle->loadMissing([
            'lastCompletedMaintenance',
            'latestPreTripChecklist',
            'latestPostTripChecklist',
            'activeSanitationLog',
        ]);

        $registration = $this->expiryAdvisory($vehicle->registration_expiry);
        $insurance = $this->expiryAdvisory($vehicle->insurance_expiry);
        $battery = $this->batteryHealth($vehicle);
        $maintenance = $this->maintenanceAdvisory($vehicle);
        $reasons = [];
        $attention = false;
        $advisory = false;

        if ($vehicle->readiness_status === 'unavailable' || $vehicle->status === 'Out of Service') {
            $reasons[] = 'Vehicle is unavailable.';
        }

        if (in_array($vehicle->readiness_status, ['maintenance', 'cleaning'], true)) {
            $attention = true;
            $reasons[] = "Readiness is {$vehicle->readiness_label}.";
        } elseif ($vehicle->readiness_status === null && $vehicle->status === 'Maintenance') {
            $attention = true;
            $reasons[] = 'Lifecycle status is Maintenance.';
        } elseif (in_array($vehicle->readiness_status, ['charging', 'refueling', 'in_use'], true)) {
            $advisory = true;
            $reasons[] = "Vehicle is currently {$vehicle->readiness_label}.";
        }

        foreach (['Registration' => $registration, 'Insurance' => $insurance] as $name => $compliance) {
            if ($compliance['state'] === 'expired') {
                $attention = true;
                $reasons[] = "{$name} has expired.";
            } elseif (str_starts_with($compliance['state'], 'expiring_')) {
                $advisory = true;
                $reasons[] = "{$name} {$compliance['label']}.";
            }
        }

        if (in_array($maintenance['state'], ['due', 'overdue'], true)) {
            $attention = true;
            $reasons[] = "Maintenance is {$maintenance['label']}.";
        } elseif ($maintenance['state'] === 'due_soon') {
            $advisory = true;
            $reasons[] = 'Maintenance is due soon.';
        }

        if ($battery['state'] === 'service_review') {
            $attention = true;
            $reasons[] = 'Battery health requires service review.';
        } elseif (in_array($battery['state'], ['monitor', 'not_recorded'], true)) {
            $advisory = true;
            $reasons[] = "Battery health is {$battery['label']}.";
        }

        if ($vehicle->latestPreTripChecklist?->result === 'failed') {
            $attention = true;
            $reasons[] = 'Latest pre-trip checklist failed.';
        }

        if ($vehicle->latestPostTripChecklist?->maintenance_required || $vehicle->latestPostTripChecklist?->issue_reported) {
            $attention = true;
            $reasons[] = 'Latest post-trip checklist reported an operational issue.';
        }

        if ($vehicle->activeSanitationLog !== null) {
            $attention = true;
            $reasons[] = 'An active sanitation record requires completion.';
        }

        $unavailable = $vehicle->readiness_status === 'unavailable' || $vehicle->status === 'Out of Service';
        [$state, $label] = match (true) {
            $unavailable => ['unavailable', 'Unavailable'],
            $attention => ['attention_required', 'Attention Required'],
            $advisory => ['ready_with_advisory', 'Ready with Advisory'],
            default => ['operationally_ready', 'Operationally Ready'],
        };

        return [
            'state' => $state,
            'label' => $label,
            'reasons' => $reasons,
            'readiness' => ['state' => $vehicle->readiness_status, 'label' => $vehicle->readiness_label],
            'lifecycle_status' => $vehicle->status,
            'maintenance' => $maintenance,
            'registration' => $registration,
            'insurance' => $insurance,
            'battery_health' => $battery,
            'latest_pre_trip' => $vehicle->latestPreTripChecklist?->result_label ?? 'Not Recorded',
            'latest_post_trip' => $vehicle->latestPostTripChecklist === null
                ? 'Not Recorded'
                : ($vehicle->latestPostTripChecklist->issue_reported ? 'Issue Reported' : 'No Issue Reported'),
            'sanitation' => $vehicle->activeSanitationLog === null ? 'Clear' : 'Active',
        ];
    }

    /** @return array{state: string, label: string, days_remaining: ?int, date: ?string} */
    private function expiryResult(string $state, string $label, ?int $daysRemaining, ?CarbonInterface $date): array
    {
        return [
            'state' => $state,
            'label' => $label,
            'days_remaining' => $daysRemaining,
            'date' => $date?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function maintenanceResult(
        string $state,
        string $label,
        mixed $maintenance,
        ?int $daysRemaining,
    ): array {
        return [
            'state' => $state,
            'label' => $label,
            'last_service_date' => $maintenance?->completion_date?->toDateString(),
            'last_service_odometer' => $maintenance?->odometer === null ? null : (float) $maintenance->odometer,
            'next_schedule' => $maintenance?->next_schedule?->toDateString(),
            'days_remaining' => $daysRemaining,
            'source' => 'Maintenance Record',
        ];
    }

    /** @return array{state: string, label: string, days_remaining: ?int, date: ?string} */
    public function driverLicenseAdvisory(Driver $driver): array
    {
        return $this->expiryAdvisory($driver->license_expiry);
    }
}
