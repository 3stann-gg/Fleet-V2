<?php

namespace App\Services;

use App\Models\FleetNotification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class FleetNotificationKeyedCreationService
{
    public function __construct(
        private FleetNotificationSchemaReadinessService $schemaReadiness,
        private MaintenanceNotificationOccurrenceGuard $maintenanceOccurrenceGuard
    ) {}

    /**
     * Create or update an active occurrence in a notification transaction.
     *
     * When an ambient transaction exists, Laravel nests this boundary and the
     * outer workflow owns final commit, rollback, lock order, and any bounded
     * deadlock retry. Otherwise this service owns the short transaction. Source
     * workflows must acquire locks before notification locks and must not retry
     * arbitrary exceptions or notification side effects independently.
     */
    public function createOrUpdateActive(
        User $user,
        string $title,
        string $message,
        string $eventKey,
        ?string $link
    ): FleetNotification {
        $this->schemaReadiness->assertOccurrenceActivationReady();

        return $this->maintenanceOccurrenceGuard->run(
            $eventKey,
            fn (): FleetNotification => $this->createOrUpdateWithActiveIdentity(
                $user,
                $title,
                $message,
                $eventKey,
                $link
            )
        );
    }

    private function createOrUpdateWithActiveIdentity(
        User $user,
        string $title,
        string $message,
        string $eventKey,
        ?string $link
    ): FleetNotification {

        try {
            return DB::transaction(
                fn (): FleetNotification => $this->createOrUpdateWithinTransaction(
                    $user,
                    $title,
                    $message,
                    $eventKey,
                    $link
                )
            );
        } catch (UniqueConstraintViolationException $exception) {
            if (! $this->isExpectedActiveEventUniqueConflict($exception)) {
                throw $exception;
            }

            return DB::transaction(function () use (
                $user,
                $eventKey,
                $title,
                $message,
                $link,
                $exception
            ): FleetNotification {
                $lockedWinner = $this->lockActiveOccurrence(
                    $user,
                    $eventKey
                );

                if ($lockedWinner === null) {
                    throw $exception;
                }

                $this->assertValidActiveOccurrence(
                    $lockedWinner,
                    $user,
                    $eventKey
                );

                return $this->updateDisplayFields(
                    $lockedWinner,
                    $title,
                    $message,
                    $link
                );
            });
        }
    }

    public function isExpectedActiveEventUniqueConflict(
        UniqueConstraintViolationException $exception
    ): bool {
        $message = Str::lower($exception->getMessage());
        $driverErrorCode = (int) ($exception->errorInfo[1] ?? 0);

        if (
            $driverErrorCode === 1062
            && str_contains(
                $message,
                FleetNotificationSchemaReadinessService::ACTIVE_EVENT_UNIQUE_INDEX
            )
        ) {
            return true;
        }

        return in_array($driverErrorCode, [19, 2067], true)
            && str_contains(
                $message,
                'fleet_notifications.user_id, fleet_notifications.active_event_key'
            );
    }

    private function createOrUpdateWithinTransaction(
        User $user,
        string $title,
        string $message,
        string $eventKey,
        ?string $link
    ): FleetNotification {
        $existing = $this->lockActiveOccurrence($user, $eventKey);

        if ($existing) {
            $this->assertValidActiveOccurrence($existing, $user, $eventKey);

            return $this->updateDisplayFields(
                $existing,
                $title,
                $message,
                $link
            );
        }

        $notification = new FleetNotification([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'status' => 'Unread',
            'event_key' => $eventKey,
            'link' => $link,
        ]);
        $notification->forceFill([
            'active_event_key' => $eventKey,
        ]);
        $notification->save();

        return $notification->refresh();
    }

    protected function lockActiveOccurrence(
        User $user,
        string $eventKey
    ): ?FleetNotification {
        return FleetNotification::query()
            ->where('user_id', $user->id)
            ->where('active_event_key', $eventKey)
            ->lockForUpdate()
            ->first();
    }

    private function assertValidActiveOccurrence(
        FleetNotification $notification,
        User $user,
        string $eventKey
    ): void {
        if (
            (int) $notification->user_id !== (int) $user->id
            || $notification->event_key !== $eventKey
            || $notification->active_event_key !== $eventKey
            || $notification->resolved_at !== null
        ) {
            throw new LogicException(
                'The selected notification is not a valid active occurrence.'
            );
        }
    }

    private function updateDisplayFields(
        FleetNotification $notification,
        string $title,
        string $message,
        ?string $link
    ): FleetNotification {
        $updates = [];

        if ($notification->title !== $title) {
            $updates['title'] = $title;
        }

        if ($notification->message !== $message) {
            $updates['message'] = $message;
        }

        if ($link && $notification->link !== $link) {
            $updates['link'] = $link;
        }

        if ($updates !== []) {
            $notification->update($updates);
            $notification->refresh();
        }

        return $notification;
    }
}
