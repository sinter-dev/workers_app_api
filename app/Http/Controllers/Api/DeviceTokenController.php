<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Device Tokens', description: 'Push-notification (FCM) device token registration')]
class DeviceTokenController extends Controller
{
    #[OA\Post(
        path: '/api/device-tokens',
        tags: ['Device Tokens'],
        summary: 'Register a push notification device token',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['token'],
                properties: [
                    new OA\Property(property: 'token', type: 'string', maxLength: 4096),
                    new OA\Property(property: 'platform', type: 'string', enum: ['android', 'ios', 'web'], nullable: true),
                    new OA\Property(property: 'device_name', type: 'string', nullable: true),
                    new OA\Property(property: 'device_id', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token registered'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
        ]);

        $deviceToken = DB::transaction(function () use ($user, $validated) {
            // An FCM token belongs to only one logged-in account at a time.
            DeviceToken::query()
                ->where('token', $validated['token'])
                ->where('user_id', '!=', $user->id)
                ->delete();

            $values = [
                'user_id' => $user->id,
                'platform' => $validated['platform'] ?? 'android',
                'device_name' => $validated['device_name'] ?? null,
                'device_id' => $validated['device_id'] ?? null,
                'last_used_at' => now(),
            ];

            if (!empty($validated['device_id'])) {
                return DeviceToken::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'device_id' => $validated['device_id'],
                    ],
                    $values + ['token' => $validated['token']]
                );
            }

            return DeviceToken::query()->updateOrCreate(
                ['token' => $validated['token']],
                $values
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Device token registered successfully.',
            'device_token' => $deviceToken,
        ]);
    }

    #[OA\Delete(
        path: '/api/device-tokens',
        tags: ['Device Tokens'],
        summary: 'Remove a device token (by token or device_id)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'token', type: 'string', nullable: true, description: 'Required if device_id is omitted'),
                    new OA\Property(property: 'device_id', type: 'string', nullable: true, description: 'Required if token is omitted'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Token(s) removed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'token' => ['required_without:device_id', 'nullable', 'string', 'max:4096'],
            'device_id' => ['required_without:token', 'nullable', 'string', 'max:255'],
        ]);

        $query = DeviceToken::query()->where('user_id', $user->id);

        if (!empty($validated['device_id'])) {
            $query->where('device_id', $validated['device_id']);
        } else {
            $query->where('token', $validated['token']);
        }

        $deleted = $query->delete();

        return response()->json([
            'success' => true,
            'message' => 'Device token removed successfully.',
            'deleted' => $deleted,
        ]);
    }

    #[OA\Get(
        path: '/api/device-tokens',
        tags: ['Device Tokens'],
        summary: 'List the authenticated user\'s registered device tokens',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Device tokens',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'device_tokens', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'device_tokens' => DeviceToken::query()
                ->where('user_id', $request->user()->id)
                ->latest('last_used_at')
                ->get(),
        ]);
    }
}