<?php

namespace Tests\MySqlConcurrency\Support;

use App\Services\MaintenanceNotificationAuthorityEvaluator;
use App\Services\MaintenanceScheduleResolver;
use InvalidArgumentException;

final class BarrierMaintenanceNotificationAuthorityEvaluator extends MaintenanceNotificationAuthorityEvaluator
{
    /** @var array<int, int> */
    private array $expectedVehicleIds = [];

    public function __construct(
        MaintenanceScheduleResolver $maintenanceScheduleResolver,
        private string $barrier,
        private string $participant,
        private ?string $beforePhase = null,
        private ?string $afterPhase = null,
        private int $checkpointAttempt = 1,
    ) {
        parent::__construct($maintenanceScheduleResolver);

        if (
            $this->barrier === ''
            || $this->participant === ''
            || $this->checkpointAttempt < 1
            || ($this->beforePhase === null && $this->afterPhase === null)
            || ($this->beforePhase !== null && $this->afterPhase !== null)
        ) {
            throw new InvalidArgumentException('A valid, single authority checkpoint is required.');
        }
    }

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
    public function evaluateEventKeyForUpdate(string $eventKey, int $expectedVehicleId): array
    {
        $this->expectedVehicleIds[] = $expectedVehicleId;
        $attempt = count($this->expectedVehicleIds);

        if ($attempt === $this->checkpointAttempt && $this->beforePhase !== null) {
            $this->checkpoint($this->beforePhase);
        }

        $evaluation = parent::evaluateEventKeyForUpdate($eventKey, $expectedVehicleId);

        if ($attempt === $this->checkpointAttempt && $this->afterPhase !== null) {
            $this->checkpoint($this->afterPhase);
        }

        return $evaluation;
    }

    /** @return array<int, int> */
    public function expectedVehicleIds(): array
    {
        return $this->expectedVehicleIds;
    }

    private function checkpoint(string $phase): void
    {
        DatabaseBarrier::arriveAndWait(
            $this->barrier.'-'.$phase,
            $this->participant,
            2,
        );
    }
}
