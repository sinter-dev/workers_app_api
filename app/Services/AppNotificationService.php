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
         * MySQL remains the source of truth. Always save the in-app
         * notification first.
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
         * Firebase is an additional delivery channel. A temporary Firebase
         * problem must never stop the core Workers App workflow.
         *
         * IMPORTANT:
         * We now log the Firebase delivery summary so production messaging
         * problems can be diagnosed without breaking the message request.
         */
        try {
            $pushResult = app(FirebasePushService::class)->sendToUser(
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

            Log::error('Firebase push delivery result.', [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'type' => $type,
                'category' => $category,
                'action_type' => $actionType,
                'action_id' => $actionId,
                'devices' => $pushResult['devices'] ?? null,
                'sent' => $pushResult['sent'] ?? null,
                'failed' => $pushResult['failed'] ?? null,
                'removed' => $pushResult['removed'] ?? null,
            ]);
        } catch (Throwable $error) {
            Log::warning(
                'Push notification could not be delivered.',
                [
                    'notification_id' => $notification->id,
                    'user_id' => $userId,
                    'type' => $type,
                    'category' => $category,
                    'action_type' => $actionType,
                    'action_id' => $actionId,
                    'message' => $error->getMessage(),
                ]
            );
        }

        return $notification;
    }
}
