<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use DateTimeImmutable;
use Illuminate\Support\Collection;

class MaintenanceNotificationAuthorityEvaluator
{
    public function __construct(
        private MaintenanceScheduleResolver $maintenanceScheduleResolver
    ) {}

    /**
     * @return array{
     *     event_key: string,
     *     verified: bool,
     *     authoritative: bool|null,
     *     maintenance_id: int|null,
     *     vehicle_id: int|null,
     *     schedule_date: string|null,
     *     current_schedule_date: string|null,
     *     resolution_reason: string|null
     * }
     */
    public function evaluateEventKey(string $eventKey): array
    {
        return $this->evaluateEventKeys([$eventKey])[$eventKey];
    }

    /**
     * Evaluate authority from rows locked in the current transaction.
     *
     * @return array{
     *     event_key: string,
     *     verified: bool,
     *     authoritative: bool|null,
     *     maintenance_id: int|null,
     *     vehicle_id: int|null,
     *     schedule_date: string|null,
     *     current_schedule_date: string|null,
     *     resolution_reason: string|null
     * }
     */
    public function evaluateEventKeyForUpdate(string $eventKey, int $expectedVehicleId): array
    {
        $identity = $this->parseEventKey($eventKey);

        if ($identity === null) {
            return $this->unverifiedEvaluation($eventKey);
        }

        $maintenance = $this->loadMaintenanceForUpdate(
            $identity['maintenance_id'],
            $expectedVehicleId
        );

        if (! $maintenance instanceof Maintenance || ! $maintenance->vehicle) {
            return $this->unverifiedEvaluation($eventKey, $identity);
        }

        return $this->evaluateMaintenance($eventKey, $identity, $maintenance);
    }

    /**
     * @param  iterable<int, string>  $eventKeys
     * @return array<string, array{
     *     event_key: string,
     *     verified: bool,
     *     authoritative: bool|null,
     *     maintenance_id: int|null,
     *     vehicle_id: int|null,
     *     schedule_date: string|null,
     *     current_schedule_date: string|null,
     *     resolution_reason: string|null
     * }>
     */
    public function evaluateEventKeys(iterable $eventKeys): array
    {
        $parsedEvents = $this->parseEventKeys($eventKeys);
        $maintenances = $this->loadMaintenances($parsedEvents);

        return $this->evaluateParsedEvents($parsedEvents, $maintenances);
    }

    /**
     * @param  array<string, array{maintenance_id: int, schedule_date: string}|null>  $parsedEvents
     * @param  Collection<int, Maintenance>  $maintenances
     * @return array<string, array<string, bool|int|string|null>>
     */
    private function evaluateParsedEvents(array $parsedEvents, Collection $maintenances): array
    {
        $evaluations = [];

        foreach ($parsedEvents as $eventKey => $identity) {
            if ($identity === null) {
                $evaluations[$eventKey] = $this->unverifiedEvaluation(
                    $eventKey
                );

                continue;
            }

            $maintenance = $maintenances->get($identity['maintenance_id']);

            if (! $maintenance instanceof Maintenance || ! $maintenance->vehicle) {
                $evaluations[$eventKey] = $this->unverifiedEvaluation(
                    $eventKey,
                    $identity
                );

                continue;
            }

            $evaluations[$eventKey] = $this->evaluateMaintenance(
                $eventKey,
                $identity,
                $maintenance
            );
        }

        return $evaluations;
    }

    public function isCanonicalEventKey(string $eventKey): bool
    {
        return $this->parseEventKey($eventKey) !== null;
    }

    public function discoverVehicleId(string $eventKey): ?int
    {
        $identity = $this->parseEventKey($eventKey);

        if ($identity === null) {
            return null;
        }

        $vehicleId = Maintenance::query()
            ->whereKey($identity['maintenance_id'])
            ->value('vehicle_id');

        return $vehicleId !== null ? (int) $vehicleId : null;
    }

    /**
     * @param  iterable<int, string>  $eventKeys
     * @return array<string, array{maintenance_id: int, schedule_date: string}|null>
     */
    private function parseEventKeys(iterable $eventKeys): array
    {
        $parsedEvents = [];

        foreach ($eventKeys as $eventKey) {
            if (array_key_exists($eventKey, $parsedEvents)) {
                continue;
            }

            $parsedEvents[$eventKey] = $this->parseEventKey($eventKey);
        }

        return $parsedEvents;
    }

    /**
     * @return array{maintenance_id: int, schedule_date: string}|null
     */
    private function parseEventKey(string $eventKey): ?array
    {
        if (! preg_match('/\Amaintenance_due:([1-9]\d*):(\d{4}-\d{2}-\d{2})\z/', $eventKey, $matches)) {
            return null;
        }

        $scheduleDate = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $matches[2]
        );

        if (
            $scheduleDate === false ||
            $scheduleDate->format('Y-m-d') !== $matches[2]
        ) {
            return null;
        }

        return [
            'maintenance_id' => (int) $matches[1],
            'schedule_date' => $matches[2],
        ];
    }

    /**
     * @param  array<string, array{maintenance_id: int, schedule_date: string}|null>  $parsedEvents
     * @return Collection<int, Maintenance>
     */
    private function loadMaintenances(array $parsedEvents): Collection
    {
        $maintenanceIds = collect($parsedEvents)
            ->filter()
            ->pluck('maintenance_id')
            ->unique()
            ->values();

        if ($maintenanceIds->isEmpty()) {
            return collect();
        }

        return Maintenance::query()
            ->with('vehicle.lastCompletedMaintenance')
            ->whereKey($maintenanceIds)
            ->get()
            ->keyBy(fn (Maintenance $maintenance): int => (int) $maintenance->getKey());
    }

    private function loadMaintenanceForUpdate(
        int $maintenanceId,
        int $expectedVehicleId
    ): ?Maintenance {
        $vehicle = Vehicle::query()
            ->whereKey($expectedVehicleId)
            ->lockForUpdate()
            ->first();

        if (! $vehicle instanceof Vehicle) {
            return null;
        }

        $maintenance = Maintenance::query()
            ->whereKey($maintenanceId)
            ->lockForUpdate()
            ->first();

        if (! $maintenance instanceof Maintenance) {
            return null;
        }

        if ((int) $maintenance->vehicle_id !== $expectedVehicleId) {
            throw new MaintenanceAuthorityChangedException(
                'The maintenance source changed vehicles during authority locking.'
            );
        }

        $completedMaintenances = Maintenance::query()
            ->where('vehicle_id', $expectedVehicleId)
            ->where('status', 'Completed')
            ->whereNotNull('completion_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $authoritativeMaintenance = $completedMaintenances
            ->sort(function (Maintenance $left, Maintenance $right): int {
                $dateComparison = $right->completion_date
                    <=> $left->completion_date;

                return $dateComparison !== 0
                    ? $dateComparison
                    : $right->getKey() <=> $left->getKey();
            })
            ->first();

        $vehicle->setRelation('lastCompletedMaintenance', $authoritativeMaintenance);
        $maintenance->setRelation('vehicle', $vehicle);

        return $maintenance;
    }

    /**
     * @param  array{maintenance_id: int, schedule_date: string}  $identity
     * @return array{
     *     event_key: string,
     *     verified: true,
     *     authoritative: bool,
     *     maintenance_id: int,
     *     vehicle_id: int,
     *     schedule_date: string,
     *     current_schedule_date: string|null,
     *     resolution_reason: string|null
     * }
     */
    private function evaluateMaintenance(
        string $eventKey,
        array $identity,
        Maintenance $maintenance
    ): array {
        $resolutionReason = $this->resolutionReason(
            $maintenance,
            $identity['schedule_date']
        );

        return [
            'event_key' => $eventKey,
            'verified' => true,
            'authoritative' => $resolutionReason === null,
            'maintenance_id' => (int) $maintenance->getKey(),
            'vehicle_id' => (int) $maintenance->vehicle_id,
            'schedule_date' => $identity['schedule_date'],
            'current_schedule_date' => $maintenance->next_schedule
                ?->toDateString(),
            'resolution_reason' => $resolutionReason,
        ];
    }

    private function resolutionReason(
        Maintenance $maintenance,
        string $eventScheduleDate
    ): ?string {
        if ($maintenance->status === 'Cancelled') {
            return 'maintenance_cancelled';
        }

        if ($maintenance->next_schedule === null) {
            return 'schedule_cleared';
        }

        if ($maintenance->next_schedule->toDateString() !== $eventScheduleDate) {
            return 'schedule_changed';
        }

        if (in_array($maintenance->status, ['Scheduled', 'In Progress'], true)) {
            return null;
        }

        if ($maintenance->status !== 'Completed') {
            return 'source_no_longer_eligible';
        }

        $context = $this->maintenanceScheduleResolver->resolve(
            $maintenance->vehicle
        );

        if ($context['maintenance_id'] === $maintenance->getKey()) {
            return null;
        }

        if ($context['maintenance_id'] !== null) {
            return 'superseded_by_newer_completion';
        }

        return 'source_no_longer_eligible';
    }

    /**
     * @param  array{maintenance_id: int, schedule_date: string}|null  $identity
     * @return array{
     *     event_key: string,
     *     verified: false,
     *     authoritative: null,
     *     maintenance_id: int|null,
     *     vehicle_id: null,
     *     schedule_date: string|null,
     *     current_schedule_date: null,
     *     resolution_reason: null
     * }
     */
    private function unverifiedEvaluation(
        string $eventKey,
        ?array $identity = null
    ): array {
        return [
            'event_key' => $eventKey,
            'verified' => false,
            'authoritative' => null,
            'maintenance_id' => $identity['maintenance_id'] ?? null,
            'vehicle_id' => null,
            'schedule_date' => $identity['schedule_date'] ?? null,
            'current_schedule_date' => null,
            'resolution_reason' => null,
        ];
    }
}
