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

            Log::error('FCM_AUTOMATIC_PUSH_RESULT', [
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
            Log::error('FCM_AUTOMATIC_PUSH_EXCEPTION', [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'type' => $type,
                'category' => $category,
                'action_type' => $actionType,
                'action_id' => $actionId,
                'exception' => $error::class,
                'message' => $error->getMessage(),
            ]);
        }

        return $notification;
    }
}
