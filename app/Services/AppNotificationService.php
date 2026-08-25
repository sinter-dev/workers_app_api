<?php

namespace App\Services;

use App\Models\AppNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

class AppNotificationService
{
    public static function send(
        int $userId,
        string $type,
        string $category,
        string $title,
        string $body,
        ?string $actionType = null,
        ?int $actionId = null,
        array $data = []
    ): AppNotification {
        /*
         * MySQL remains the source of truth.
         * Always save the in-app notification first.
         */
        $notification = AppNotification::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'action_type' => $actionType,
            'action_id' => $actionId,
            'data' => $data ?: null,
        ]);

        /*
         * Firebase is an additional delivery channel.
         * A temporary Firebase problem must never stop the main app workflow.
         */
        try {
            app(FirebasePushService::class)->sendToUser(
                $userId,
                $title,
                $body,
                [
                    'notification_id' => $notification->id,
                    'type' => $type,
                    'category' => $category,
                    'action_type' => $actionType,
                    'action_id' => $actionId,
                ] + $data
            );
        } catch (Throwable $error) {
            Log::error(
                'Push notification could not be delivered.',
                [
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'type' => $type,
                    'category' => $category,
                    'action_type' => $actionType,
                    'action_id' => $actionId,
                    'exception' => $error::class,
                    'message' => $error->getMessage(),
                ]
            );
        }

        return $notification;
    }
}
