<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountAppeal;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Admin Account Appeals', description: 'Administrator review of suspended users\' appeals')]
class AdminAccountAppealController extends Controller
{
    #[OA\Get(
        path: '/api/admin/account-appeals',
        tags: ['Admin Account Appeals'],
        summary: 'List account appeals',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected'], default: 'pending')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Appeals',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'appeals', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');

        $query = AccountAppeal::query()
            ->with('user:id,full_name,phone,email,role,account_status,account_status_reason,account_status_changed_at')
            ->latest();

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        return response()->json([
            'success' => true,
            'appeals' => $query->get(),
        ]);
    }

    #[OA\Post(
        path: '/api/admin/account-appeals/{appeal}/approve',
        tags: ['Admin Account Appeals'],
        summary: 'Approve an appeal (restores the account to active)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'appeal', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'response', type: 'string', nullable: true, maxLength: 1500),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Appeal approved, account restored'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 422, description: 'Appeal already reviewed'),
        ]
    )]
    public function approve(Request $request, AccountAppeal $appeal): JsonResponse
    {
        if ($appeal->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This appeal has already been reviewed.'], 422);
        }

        $validated = $request->validate([
            'response' => ['nullable', 'string', 'max:1500'],
        ]);

        $appeal->forceFill([
            'status' => 'approved',
            'admin_response' => $validated['response'] ?? 'Your appeal was approved and your account has been restored.',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        $appeal->user->forceFill([
            'account_status' => 'active',
            'account_status_reason' => null,
            'account_status_changed_at' => now(),
            'account_status_changed_by' => $request->user()->id,
        ])->save();

        AppNotificationService::send(
            $appeal->user_id,
            'appeal_approved',
            'system',
            'Appeal approved',
            $appeal->admin_response,
            'account_status'
        );

        return response()->json(['success' => true, 'message' => 'Appeal approved and account restored.']);
    }

    #[OA\Post(
        path: '/api/admin/account-appeals/{appeal}/reject',
        tags: ['Admin Account Appeals'],
        summary: 'Reject an appeal (account remains suspended)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'appeal', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['response'],
                properties: [
                    new OA\Property(property: 'response', type: 'string', minLength: 5, maxLength: 1500),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Appeal rejected'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 422, description: 'Appeal already reviewed, or validation error'),
        ]
    )]
    public function reject(Request $request, AccountAppeal $appeal): JsonResponse
    {
        if ($appeal->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'This appeal has already been reviewed.'], 422);
        }

        $validated = $request->validate([
            'response' => ['required', 'string', 'min:5', 'max:1500'],
        ]);

        $appeal->forceFill([
            'status' => 'rejected',
            'admin_response' => trim($validated['response']),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        AppNotificationService::send(
            $appeal->user_id,
            'appeal_rejected',
            'system',
            'Appeal reviewed',
            $appeal->admin_response,
            'account_status'
        );

        return response()->json(['success' => true, 'message' => 'Appeal rejected. The account remains suspended.']);
    }
}