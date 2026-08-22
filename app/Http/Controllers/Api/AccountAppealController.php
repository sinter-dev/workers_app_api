<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountAppeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Account Appeals', description: 'Suspended users checking status and submitting appeals (accessible even when suspended)')]
class AccountAppealController extends Controller
{
    #[OA\Get(
        path: '/api/account/status',
        tags: ['Account Appeals'],
        summary: 'Get the authenticated user\'s account status and latest appeal',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Account status',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'account_status', type: 'string', example: 'active'),
                        new OA\Property(property: 'account_status_reason', type: 'string', nullable: true),
                        new OA\Property(property: 'account_status_changed_at', type: 'string', nullable: true),
                        new OA\Property(property: 'latest_appeal', type: 'object', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'account_status' => $user->account_status ?? 'active',
            'account_status_reason' => $user->account_status_reason,
            'account_status_changed_at' => $user->account_status_changed_at,
            'latest_appeal' => AccountAppeal::query()
                ->where('user_id', $user->id)
                ->latest()
                ->first(),
        ]);
    }

    #[OA\Post(
        path: '/api/account/appeals',
        tags: ['Account Appeals'],
        summary: 'Submit a suspension appeal',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', minLength: 20, maxLength: 2000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Appeal submitted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Account not suspended, or an appeal is already pending'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if (($user->account_status ?? 'active') !== 'suspended') {
            return response()->json([
                'success' => false,
                'message' => 'Only suspended accounts can submit a suspension appeal.',
            ], 422);
        }

        $pending = AccountAppeal::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($pending !== null) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an appeal waiting for administrator review.',
                'appeal' => $pending,
            ], 422);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $appeal = AccountAppeal::query()->create([
            'user_id' => $user->id,
            'message' => trim($validated['message']),
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your appeal has been submitted. An administrator will review it.',
            'appeal' => $appeal,
        ], 201);
    }
}