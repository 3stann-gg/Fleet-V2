<?php

namespace App\Services;

use App\Models\Maintenance;
use Illuminate\Support\Collection;

class MaintenanceClassificationService
{
    public const OTHER_CATEGORY = 'Other / Not Classified';

    public const CATEGORIES = [
        'Preventive Maintenance',
        'Corrective Maintenance',
        'Emergency Repair',
        'Inspection',
        self::OTHER_CATEGORY,
    ];

    /**
     * @param  Collection<int, Maintenance>  $maintenances
     * @return Collection<int, Maintenance>
     */
    public function enrich(Collection $maintenances): Collection
    {
        return $maintenances->each(fn (Maintenance $maintenance): Maintenance => $this->enrichOne($maintenance));
    }

    public function enrichOne(Maintenance $maintenance): Maintenance
    {
        $maintenance->setAttribute('maintenance_category', $this->category($maintenance));

        foreach ($this->dueContext($maintenance) as $attribute => $value) {
            $maintenance->setAttribute($attribute, $value);
        }

        $vehicle = $maintenance->vehicle;
        $maintenance->setAttribute('vehicle_service_context', [
            'purchase_date' => $vehicle?->purchase_date?->toDateString(),
            'current_odometer' => $vehicle?->current_odometer === null
                ? null
                : (float) $vehicle->current_odometer,
            'basis' => 'Category is derived from the recorded request and service types. Due context is derived from maintenance status and recorded dates. Vehicle purchase date and odometer are display context only.',
        ]);

        return $maintenance;
    }

    public function category(Maintenance $maintenance): string
    {
        if ($maintenance->request_type === 'Emergency') {
            return 'Emergency Repair';
        }

        return match (mb_strtolower(trim((string) $maintenance->maintenance_type))) {
            'preventive maintenance', 'oil change' => 'Preventive Maintenance',
            'corrective maintenance', 'corrective repair' => 'Corrective Maintenance',
            'emergency repair' => 'Emergency Repair',
            'inspection' => 'Inspection',
            default => self::OTHER_CATEGORY,
        };
    }

    /**
     * @return array{maintenance_due_state: string, maintenance_due_label: string, maintenance_due_date: ?string, maintenance_due_basis: string, maintenance_days_until_due: ?int}
     */
    public function dueContext(Maintenance $maintenance): array
    {
        if ($maintenance->status === 'Cancelled') {
            return $this->dueResult('not_applicable', 'Not Applicable', null, 'Cancelled maintenance record', null);
        }

        $usesNextSchedule = $maintenance->status === 'Completed';
        $dueDate = $usesNextSchedule
            ? $maintenance->next_schedule
            : $maintenance->maintenance_date;
        $basis = $usesNextSchedule
            ? 'Next scheduled service date'
            : 'Scheduled maintenance date';

        if ($dueDate === null) {
            return $this->dueResult('not_scheduled', 'Not Scheduled', null, $basis, null);
        }

        $days = (int) round(
            now()->startOfDay()->diffInDays($dueDate->copy()->startOfDay(), false)
        );

        [$state, $label] = match (true) {
            $days < 0 => ['overdue', 'Overdue'],
            $days === 0 => ['due', 'Due'],
            default => ['upcoming', 'Upcoming'],
        };

        return $this->dueResult($state, $label, $dueDate->toDateString(), $basis, $days);
    }

    /**
     * @return array{maintenance_due_state: string, maintenance_due_label: string, maintenance_due_date: ?string, maintenance_due_basis: string, maintenance_days_until_due: ?int}
     */
    private function dueResult(
        string $state,
        string $label,
        ?string $date,
        string $basis,
        ?int $days,
    ): array {
        return [
            'maintenance_due_state' => $state,
            'maintenance_due_label' => $label,
            'maintenance_due_date' => $date,
            'maintenance_due_basis' => $basis,
            'maintenance_days_until_due' => $days,
        ];
    }
}
