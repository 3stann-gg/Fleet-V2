<?php

namespace App\Services;

use App\Models\FleetNotification;
use Carbon\CarbonInterface;

class MaintenanceNotificationReconciliationService
{
    public function __construct(
        private MaintenanceNotificationAuthorityEvaluator $authorityEvaluator,
        private MySqlDeadlockRetryService $deadlockRetryService
    ) {}

    /**
     * @return array{
     *     event_key: string,
     *     verified: bool,
     *     authoritative: bool|null,
     *     resolution_reason: string|null,
     *     resolved_count: int
     * }
     */
    public function reconcileEventKey(
        string $eventKey,
        ?CarbonInterface $resolvedAt = null
    ): array {
        return $this->reconcileEventKeys([$eventKey], $resolvedAt)[$eventKey];
    }

    /**
     * @param  iterable<int, string>  $eventKeys
     * @return array<string, array{
     *     event_key: string,
     *     verified: bool,
     *     authoritative: bool|null,
     *     resolution_reason: string|null,
     *     resolved_count: int
     * }>
     */
    public function reconcileEventKeys(
        iterable $eventKeys,
        ?CarbonInterface $resolvedAt = null
    ): array {
        $eventKeys = collect($eventKeys)->unique()->values();
        $resolutionTimestamp = $resolvedAt ?? now();
        $results = [];

        foreach ($eventKeys as $eventKey) {
            for ($sourceAttempt = 1; $sourceAttempt <= 3; $sourceAttempt++) {
                $vehicleId = $this->authorityEvaluator
                    ->discoverVehicleId($eventKey);

                if ($vehicleId === null) {
                    $results[$eventKey] = $this->unverifiedResult($eventKey);

                    break;
                }

                try {
                    $results[$eventKey] = $this->deadlockRetryService->run(
                        fn (): array => $this->reconcileLockedEvent(
                            $eventKey,
                            $vehicleId,
                            $resolutionTimestamp
                        )
                    );

                    break;
                } catch (MaintenanceAuthorityChangedException $exception) {
                    if ($sourceAttempt === 3) {
                        throw $exception;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @return array{
     *     event_key: string,
     *     verified: bool,
     *     authoritative: bool|null,
     *     resolution_reason: string|null,
     *     resolved_count: int
     * }
     */
    private function reconcileLockedEvent(
        string $eventKey,
        int $vehicleId,
        CarbonInterface $resolutionTimestamp
    ): array {
        $evaluation = $this->authorityEvaluator
            ->evaluateEventKeyForUpdate($eventKey, $vehicleId);

        if (! $evaluation['verified']) {
            return $this->unverifiedResult($eventKey);
        }

        if ($evaluation['authoritative']) {
            return $this->authoritativeResult($eventKey);
        }

        $resolutionReason = $evaluation['resolution_reason'];

        if ($resolutionReason === null) {
            return $this->unverifiedResult($eventKey);
        }

        return [
            'event_key' => $eventKey,
            'verified' => true,
            'authoritative' => false,
            'resolution_reason' => $resolutionReason,
            'resolved_count' => $this->resolveNotifications(
                $eventKey,
                $resolutionReason,
                $resolutionTimestamp
            ),
        ];
    }

    private function resolveNotifications(
        string $eventKey,
        string $resolutionReason,
        CarbonInterface $resolvedAt
    ): int {
        $notifications = FleetNotification::query()
            ->where('event_key', $eventKey)
            ->whereNull('resolved_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $resolvedCount = 0;

        foreach ($notifications as $notification) {
            $oldValues = [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
                'event_key' => $notification->event_key,
                'status' => $notification->status,
                'resolved_at' => null,
                'resolution_reason' => $notification->resolution_reason,
                'active_event_key' => $notification->active_event_key,
            ];

            $updated = FleetNotification::query()
                ->whereKey($notification->getKey())
                ->whereNull('resolved_at')
                ->update([
                    'resolved_at' => $resolvedAt,
                    'resolution_reason' => $resolutionReason,
                    'active_event_key' => null,
                ]);

            if ($updated !== 1) {
                continue;
            }

            AuditLogService::logSystem(
                module: 'Fleet Notifications',
                action: 'Notification Resolved',
                description: 'Resolved an obsolete maintenance notification event.',
                record: $notification,
                oldValues: $oldValues,
                newValues: [
                    'notification_id' => $notification->id,
                    'user_id' => $notification->user_id,
                    'event_key' => $notification->event_key,
                    'status' => $notification->status,
                    'resolved_at' => $resolvedAt->format('Y-m-d H:i:s'),
                    'resolution_reason' => $resolutionReason,
                    'active_event_key' => null,
                ]
            );

            $resolvedCount++;
        }

        return $resolvedCount;
    }

    /**
     * @return array{
     *     event_key: string,
     *     verified: false,
     *     authoritative: null,
     *     resolution_reason: null,
     *     resolved_count: 0
     * }
     */
    private function unverifiedResult(string $eventKey): array
    {
        return [
            'event_key' => $eventKey,
            'verified' => false,
            'authoritative' => null,
            'resolution_reason' => null,
            'resolved_count' => 0,
        ];
    }

    /**
     * @return array{
     *     event_key: string,
     *     verified: true,
     *     authoritative: true,
     *     resolution_reason: null,
     *     resolved_count: 0
     * }
     */
    private function authoritativeResult(string $eventKey): array
    {
        return [
            'event_key' => $eventKey,
            'verified' => true,
            'authoritative' => true,
            'resolution_reason' => null,
            'resolved_count' => 0,
        ];
    }
}
