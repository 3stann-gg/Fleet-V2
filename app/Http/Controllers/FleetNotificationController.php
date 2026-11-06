<?php

namespace App\Http\Controllers;

use App\Models\FleetNotification;
use App\Models\User;
use App\Services\FleetNotificationCheckService;
use App\Services\FleetNotificationService;
use Illuminate\Http\Request;

class FleetNotificationController extends Controller
{
    /**
     * Get notifications for the current user.
     */
    public function index(
        Request $request,
        FleetNotificationCheckService $checker
    ) {
        $user =
            $request->user();

        abort_unless(
            $user !== null,
            401
        );

        /*
        |--------------------------------------------------------------------------
        | Generate / check notification conditions
        |--------------------------------------------------------------------------
        */
        $checker->check();

        /*
        |--------------------------------------------------------------------------
        | Own notifications only
        |--------------------------------------------------------------------------
        */
        $notifications =
            collect();

        $activeNotifications =
            FleetNotification::query()
                ->where(
                    'user_id',
                    $user->id
                )
                ->whereNull(
                    'resolved_at'
                )
                ->lazyByIdDesc(
                    50,
                    'id'
                );

        foreach (
            $activeNotifications as $notification
        ) {
            if (
                ! $this->userCanAccessNotification(
                    $user,
                    $notification
                )
            ) {
                continue;
            }

            $notifications->push(
                $notification
            );

            if ($notifications->count() === 30) {
                break;
            }
        }

        return response()->json([
            'notifications' =>
                $notifications
                    ->map(function ($notification) {
                        return [
                            'id' =>
                                $notification->id,

                            'user_id' =>
                                $notification->user_id,

                            'title' =>
                                $notification->title,

                            'message' =>
                                $notification->message,

                            'status' =>
                                $notification->status,

                            'event_key' =>
                                $notification->event_key,

                            'link' =>
                                $notification->link,

                            'created_at' =>
                                $notification->created_at
                                    ?->toIso8601String(),

                            'created_at_timestamp' =>
                                $notification->created_at
                                    ?->getTimestamp(),
                        ];
                    })
                    ->values(),

            'unread_count' =>
                $notifications
                    ->where(
                        'status',
                        'Unread'
                    )
                    ->count(),
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function markRead(
        Request $request,
        FleetNotification $notification
    ) {
        $user =
            $request->user();

        abort_unless(
            $user !== null,
            401
        );

        /*
        |--------------------------------------------------------------------------
        | Ownership Guard
        |--------------------------------------------------------------------------
        */
        abort_unless(
            (int)
            $notification->user_id ===
            (int)
            $user->id,
            403
        );

        /*
        |--------------------------------------------------------------------------
        | RBAC Guard
        |--------------------------------------------------------------------------
        */
        abort_unless(
            FleetNotificationService
                ::userCanAccessLink(
                    $user,
                    $notification->link
                ),
            403
        );

        if (
            $user->hasRole(
                'driver'
            )
        ) {
            abort_unless(
                FleetNotificationService
                    ::driverOwnsNotification(
                        $user,
                        $notification
                    ),
                403
            );
        }

        $notification->update([
            'status' =>
                'Read',
        ]);

        return response()->json([
            'success' =>
                true,

            'message' =>
                'Notification marked as read.',
        ]);
    }

    /**
     * Mark all accessible notifications
     * for current user as read.
     */
    public function markAllRead(
        Request $request
    ) {
        $user =
            $request->user();

        abort_unless(
            $user !== null,
            401
        );

        /*
        |--------------------------------------------------------------------------
        | Fetch own unread notifications
        |--------------------------------------------------------------------------
        */
        FleetNotification::query()
            ->where(
                'user_id',
                $user->id
            )
            ->where(
                'status',
                'Unread'
            )
            ->whereNull(
                'resolved_at'
            )
            ->chunkById(
                100,
                function ($notifications) use ($user) {
                    $accessibleIds =
                        $notifications
                            ->filter(
                                fn ($notification) => $this->userCanAccessNotification(
                                    $user,
                                    $notification
                                )
                            )
                            ->pluck('id');

                    if ($accessibleIds->isEmpty()) {
                        return;
                    }

                    FleetNotification::query()
                        ->where(
                            'user_id',
                            $user->id
                        )
                        ->where(
                            'status',
                            'Unread'
                        )
                        ->whereNull(
                            'resolved_at'
                        )
                        ->whereIn(
                            'id',
                            $accessibleIds
                        )
                        ->update([
                            'status' => 'Read',
                        ]);
                }
            );

        return response()->json([
            'success' => true,

            'message' => 'All notifications marked as read.',
        ]);
    }

    private function userCanAccessNotification(
        User $user,
        FleetNotification $notification
    ): bool {
        if (
            ! FleetNotificationService::userCanAccessLink(
                $user,
                $notification->link
            )
        ) {
            return false;
        }

        if (
            $user->hasRole(
                'driver'
            )
        ) {
            return FleetNotificationService::driverOwnsNotification(
                $user,
                $notification
            );
        }

        return true;
    }
}
