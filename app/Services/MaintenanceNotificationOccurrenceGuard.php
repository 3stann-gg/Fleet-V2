<?php

namespace App\Services;

use App\Models\Maintenance;
use App\Models\Vehicle;
use Closure;
use Illuminate\Support\Facades\DB;

class MaintenanceNotificationOccurrenceGuard
{
    public function __construct(
        private MaintenanceNotificationAuthorityEvaluator $authorityEvaluator,
        private MySqlDeadlockRetryService $deadlockRetryService
    ) {}

    public function run(string $eventKey, Closure $notificationOperation): mixed
    {
        if (! str_starts_with($eventKey, 'maintenance_due:')) {
            return $notificationOperation();
        }

        if (DB::transactionLevel() > 0) {
            try {
                return $this->runWithLockedSource($eventKey, $notificationOperation);
            } catch (MaintenanceAuthorityChangedException) {
                throw new MaintenanceNotificationSourceNotAuthoritativeException(
                    'source_vehicle_changed'
                );
            }
        }

        for ($sourceAttempt = 1; $sourceAttempt <= 3; $sourceAttempt++) {
            try {
                return $this->deadlockRetryService->run(
                    fn (): mixed => $this->runWithLockedSource(
                        $eventKey,
                        $notificationOperation
                    )
                );
            } catch (MaintenanceAuthorityChangedException) {
                if ($sourceAttempt === 3) {
                    throw new MaintenanceNotificationSourceNotAuthoritativeException(
                        'source_vehicle_changed'
                    );
                }
            }
        }

        throw new \LogicException('Maintenance notification authority retry ended unexpectedly.');
    }

    private function runWithLockedSource(
        string $eventKey,
        Closure $notificationOperation
    ): mixed {
        $vehicleId = $this->authorityEvaluator->discoverVehicleId($eventKey);

        if ($vehicleId === null) {
            throw new MaintenanceNotificationSourceNotAuthoritativeException(
                'source_unverified'
            );
        }

        $evaluation = $this->authorityEvaluator->evaluateEventKeyForUpdate(
            $eventKey,
            $vehicleId
        );

        if (! $evaluation['verified'] || ! $evaluation['authoritative']) {
            throw new MaintenanceNotificationSourceNotAuthoritativeException(
                $evaluation['resolution_reason'] ?? 'source_unverified'
            );
        }

        $lockedMaintenance = $evaluation['locked_maintenance'] ?? null;
        $lockedVehicle = $evaluation['locked_vehicle'] ?? null;

        if (
            ! $lockedMaintenance instanceof Maintenance
            || ! $lockedVehicle instanceof Vehicle
        ) {
            throw new MaintenanceNotificationSourceNotAuthoritativeException(
                'source_unverified'
            );
        }

        return $notificationOperation($lockedMaintenance, $lockedVehicle);
    }
}
