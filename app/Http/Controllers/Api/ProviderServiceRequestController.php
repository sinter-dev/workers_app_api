<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Provider Service Requests', description: 'Workers and companies browsing open service requests to quote on')]
class ProviderServiceRequestController extends Controller
{
    #[OA\Get(
        path: '/api/provider/service-requests',
        tags: ['Provider Service Requests'],
        summary: 'Browse open service requests (worker or company accounts)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'service_category_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'district', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Open service requests, with has_quoted indicating if this provider already quoted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'service_requests', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pagination', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker or company accounts can browse service requests'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array($user->role, ['worker', 'company'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only worker or company accounts can browse service requests.',
            ], 403);
        }

        $validated = $request->validate([
            'service_category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'district' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = ServiceRequest::query()
            ->with([
                'serviceCategory:id,name,slug,icon',
                'homeowner:id,full_name,location',
            ])
            ->withCount('quotes')
            ->where('status', 'open');

        if (!empty($validated['service_category_id'])) {
            $query->where('service_category_id', $validated['service_category_id']);
        }

        if (!empty($validated['district'])) {
            $query->where('district', $validated['district']);
        }

        $requests = $query
            ->latest()
            ->paginate(
                $validated['per_page'] ?? 20,
                ['*'],
                'page',
                $validated['page'] ?? 1
            );

        $alreadyQuotedIds = $user->serviceQuotes()
            ->whereIn('service_request_id', collect($requests->items())->pluck('id'))
            ->pluck('service_request_id')
            ->all();

        $items = collect($requests->items())->map(function (ServiceRequest $serviceRequest) use ($alreadyQuotedIds) {
            $serviceRequest->setAttribute(
                'has_quoted',
                in_array($serviceRequest->id, $alreadyQuotedIds, true)
            );

            return $serviceRequest;
        });

        return response()->json([
            'success' => true,
            'service_requests' => $items,
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'from' => $requests->firstItem(),
                'to' => $requests->lastItem(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/provider/service-requests/{serviceRequest}',
        tags: ['Provider Service Requests'],
        summary: 'View a single open service request',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service request details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker or company accounts can view this'),
            new OA\Response(response: 404, description: 'Not found or no longer open'),
        ]
    )]
    public function show(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array($user->role, ['worker', 'company'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only worker or company accounts can view this.',
            ], 403);
        }

        if ($serviceRequest->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'This request is no longer open.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'service_request' => $serviceRequest->load([
                'serviceCategory:id,name,slug,icon',
                'homeowner:id,full_name,location',
            ]),
            'has_quoted' => $user->serviceQuotes()
                ->where('service_request_id', $serviceRequest->id)
                ->exists(),
        ]);
    }
}
