<?php

namespace App\Services;

use App\Models\FleetNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class FleetNotificationActiveEventService
{
    public function __construct(
        private MaintenanceNotificationAuthorityEvaluator $authorityEvaluator,
        private FleetNotificationKeyedCreationService $keyedCreationService
    ) {}

    public function assignMaintenanceEventKey(
        FleetNotification $notification,
        string $canonicalEventKey
    ): FleetNotification {
        $this->ensureCanonicalMaintenanceEventKey($canonicalEventKey);

        try {
            return DB::transaction(function () use (
                $notification,
                $canonicalEventKey
            ): FleetNotification {
                $currentNotification = FleetNotification::query()
                    ->lockForUpdate()
                    ->findOrFail($notification->getKey());

                if ($currentNotification->event_key !== $canonicalEventKey) {
                    throw new InvalidArgumentException(
                        'The active event key must match the notification event key.'
                    );
                }

                if ($currentNotification->resolved_at !== null) {
                    throw new LogicException(
                        'Resolved notifications cannot be assigned an active event key.'
                    );
                }

                if ($currentNotification->active_event_key === $canonicalEventKey) {
                    return $currentNotification;
                }

                if ($currentNotification->active_event_key !== null) {
                    throw new LogicException(
                        'The notification already has a different active event key.'
                    );
                }

                $currentNotification->active_event_key = $canonicalEventKey;
                $currentNotification->save();

                return $currentNotification->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (
                ! $this->keyedCreationService
                    ->isExpectedActiveEventUniqueConflict($exception)
            ) {
                throw $exception;
            }

            $winner = DB::transaction(fn (): ?FleetNotification => FleetNotification::query()
                ->where('user_id', $notification->user_id)
                ->where('event_key', $canonicalEventKey)
                ->where('active_event_key', $canonicalEventKey)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->first()
            );

            if ($winner === null) {
                throw $exception;
            }

            throw new LogicException(
                'An active notification occurrence already exists for this user and event key.',
                0,
                $exception
            );
        }
    }

    private function ensureCanonicalMaintenanceEventKey(
        string $canonicalEventKey
    ): void {
        if (! $this->authorityEvaluator->isCanonicalEventKey($canonicalEventKey)) {
            throw new InvalidArgumentException(
                'The active event key is not a canonical maintenance event key.'
            );
        }
    }
}
