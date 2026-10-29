<?php

namespace App\Services;

use App\Models\FleetNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MaintenanceNotificationReconciliationService
{
    public function __construct(
        private MaintenanceNotificationAuthorityEvaluator $authorityEvaluator
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
        $evaluations = $this->authorityEvaluator
            ->evaluateEventKeys($eventKeys);
        $resolutionTimestamp = $resolvedAt ?? now();
        $results = [];

        foreach ($evaluations as $eventKey => $evaluation) {
            if (! $evaluation['verified']) {
                $results[$eventKey] = $this->unverifiedResult($eventKey);

                continue;
            }

            if ($evaluation['authoritative']) {
                $results[$eventKey] = $this->authoritativeResult($eventKey);

                continue;
            }

            $resolutionReason = $evaluation['resolution_reason'];

            if ($resolutionReason === null) {
                $results[$eventKey] = $this->unverifiedResult($eventKey);

                continue;
            }

            $resolvedCount = $this->resolveNotifications(
                $eventKey,
                $resolutionReason,
                $resolutionTimestamp
            );

            $results[$eventKey] = [
                'event_key' => $eventKey,
                'verified' => true,
                'authoritative' => false,
                'resolution_reason' => $resolutionReason,
                'resolved_count' => $resolvedCount,
            ];
        }

        return $results;
    }

    private function resolveNotifications(
        string $eventKey,
        string $resolutionReason,
        CarbonInterface $resolvedAt
    ): int {
        return DB::transaction(function () use (
            $eventKey,
            $resolutionReason,
            $resolvedAt
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
        });
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
