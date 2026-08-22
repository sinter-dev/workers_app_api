<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Notifications', description: 'In-app notifications for the authenticated user')]
class NotificationController extends Controller
{
    #[OA\Get(
        path: '/api/notifications',
        tags: ['Notifications'],
        summary: 'List notifications (most recent 100)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'unread_only', in: 'query', schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Notifications',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'unread_count', type: 'integer'),
                        new OA\Property(property: 'notifications', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $q = AppNotification::query()->where('user_id', $request->user()->id)->latest();
        if ($request->boolean('unread_only')) {
            $q->whereNull('read_at');
        }
        $items = $q->limit(100)->get();

        return response()->json([
            'success' => true,
            'unread_count' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            'notifications' => $items,
        ]);
    }

    #[OA\Get(
        path: '/api/notifications/unread-count',
        tags: ['Notifications'],
        summary: 'Get the unread notification count',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Unread count',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'unread_count', type: 'integer'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'unread_count' => AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    #[OA\Post(
        path: '/api/notifications/{notification}/read',
        tags: ['Notifications'],
        summary: 'Mark a single notification as read',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marked as read'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this notification'),
        ]
    )]
    public function read(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['success' => true, 'notification' => $notification->fresh()]);
    }

    #[OA\Post(
        path: '/api/notifications/read-all',
        tags: ['Notifications'],
        summary: 'Mark all notifications as read',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'All marked as read'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function readAll(Request $request): JsonResponse
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    #[OA\Delete(
        path: '/api/notifications/{notification}',
        tags: ['Notifications'],
        summary: 'Delete a notification',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this notification'),
        ]
    )]
    public function destroy(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->delete();

        return response()->json(['success' => true]);
    }
}