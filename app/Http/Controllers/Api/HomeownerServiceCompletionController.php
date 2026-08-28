<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Homeowner Service Completion', description: 'Homeowner confirmation that a booked service was completed')]
class HomeownerServiceCompletionController extends Controller
{
    #[OA\Patch(
        path: '/api/homeowner/service-requests/{serviceRequest}/complete',
        tags: ['Homeowner Service Completion'],
        summary: 'Confirm a service was completed by the provider',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service marked completed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
            new OA\Response(response: 422, description: 'Request is not awaiting confirmation'),
        ]
    )]
    public function confirm(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($serviceRequest->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        if ($serviceRequest->status !== 'awaiting_confirmation') {
            return response()->json([
                'success' => false,
                'message' => 'This request is not awaiting confirmation.',
            ], 422);
        }

        $serviceRequest->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        AppNotificationService::send(
            $serviceRequest->provider_id,
            'service_completion_confirmed',
            'service_requests',
            'Service confirmed complete',
            'The homeowner confirmed "' . $serviceRequest->title . '" is complete.',
            'service_request',
            $serviceRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Service marked as completed.',
            'service_request' => $serviceRequest->fresh(),
        ]);
    }
}
