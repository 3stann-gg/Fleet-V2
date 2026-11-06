<?php

namespace Tests\MySqlConcurrency\Support;

use App\Models\FleetNotification;
use App\Models\User;
use App\Services\FleetNotificationKeyedCreationService;
use App\Services\FleetNotificationSchemaReadinessService;
use App\Services\MaintenanceNotificationOccurrenceGuard;
use Illuminate\Database\UniqueConstraintViolationException;

final class BarrierFleetNotificationKeyedCreationService extends FleetNotificationKeyedCreationService
{
    private int $lookupCount = 0;

    private bool $expectedActiveCollisionObserved = false;

    public function __construct(
        FleetNotificationSchemaReadinessService $schemaReadiness,
        private string $barrier,
        private string $participant,
        private int $participants,
        private bool $mutateWinner = false
    ) {
        parent::__construct(
            $schemaReadiness,
            app(MaintenanceNotificationOccurrenceGuard::class)
        );
    }

    protected function lockActiveOccurrence(User $user, string $eventKey): ?FleetNotification
    {
        $this->lookupCount++;

        if ($this->lookupCount === 1) {
            if (! $this->mutateWinner) {
                DatabaseBarrier::arriveAndWait(
                    $this->barrier.'-lookup',
                    $this->participant,
                    $this->participants
                );
            }

            return null;
        }

        if ($this->mutateWinner) {
            DatabaseBarrier::arriveAndWait(
                $this->barrier.'-collision',
                $this->participant,
                2
            );
            DatabaseBarrier::arriveAndWait(
                $this->barrier.'-mutated',
                $this->participant,
                2
            );
        }

        return parent::lockActiveOccurrence($user, $eventKey);
    }

    public function isExpectedActiveEventUniqueConflict(
        UniqueConstraintViolationException $exception
    ): bool {
        $isExpectedConflict = parent::isExpectedActiveEventUniqueConflict($exception);

        if ($isExpectedConflict) {
            $this->expectedActiveCollisionObserved = true;
        }

        return $isExpectedConflict;
    }

    public function activeCollisionRecoveryObserved(): bool
    {
        return $this->expectedActiveCollisionObserved;
    }
}
