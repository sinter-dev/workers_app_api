<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Homeowner Service Requests', description: 'Posting and managing one-off service bookings (e.g. plumbing, cleaning) as a homeowner')]
#[OA\Schema(
    schema: 'ServiceRequestBody',
    required: ['service_category_id', 'title', 'description', 'address', 'district'],
    properties: [
        new OA\Property(property: 'service_category_id', type: 'integer'),
        new OA\Property(property: 'title', type: 'string', maxLength: 150, example: 'Leaking kitchen pipe'),
        new OA\Property(property: 'description', type: 'string', maxLength: 3000),
        new OA\Property(property: 'address', type: 'string'),
        new OA\Property(property: 'district', type: 'string'),
        new OA\Property(property: 'latitude', type: 'number', nullable: true),
        new OA\Property(property: 'longitude', type: 'number', nullable: true),
        new OA\Property(property: 'preferred_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'preferred_time', type: 'string', nullable: true, example: 'Afternoon'),
    ]
)]
class HomeownerServiceRequestController extends Controller
{
    #[OA\Get(
        path: '/api/homeowner/service-requests',
        tags: ['Homeowner Service Requests'],
        summary: 'List the authenticated homeowner\'s service requests',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service requests',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'service_requests', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can access this'),
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

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can access this.',
            ], 403);
        }

        $requests = ServiceRequest::query()
            ->with([
                'serviceCategory:id,name,slug,icon',
                'provider:id,full_name,phone,profile_photo',
            ])
            ->withCount('quotes')
            ->where('homeowner_id', $user->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'service_requests' => $requests,
        ]);
    }

    #[OA\Post(
        path: '/api/homeowner/service-requests',
        tags: ['Homeowner Service Requests'],
        summary: 'Post a new service request',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ServiceRequestBody')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Service request posted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can post service requests'),
            new OA\Response(response: 422, description: 'Validation error, or the category is not bookable on demand'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can post service requests.',
            ], 403);
        }

        $validated = $request->validate([
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:3000'],
            'address' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:150'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_time' => ['nullable', 'string', 'max:100'],
        ]);

        $category = ServiceCategory::query()
            ->where('id', $validated['service_category_id'])
            ->where('active', true)
            ->where('transaction_type', 'on_demand_service')
            ->first();

        if ($category === null) {
            return response()->json([
                'success' => false,
                'message' => 'That category is not available for one-off service bookings.',
            ], 422);
        }

        $serviceRequest = ServiceRequest::query()->create([
            'homeowner_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'address' => $validated['address'],
            'district' => $validated['district'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'preferred_date' => $validated['preferred_date'] ?? null,
            'preferred_time' => $validated['preferred_time'] ?? null,
            'status' => 'open',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service request posted.',
            'service_request' => $serviceRequest,
        ], 201);
    }

    #[OA\Get(
        path: '/api/homeowner/service-requests/{serviceRequest}',
        tags: ['Homeowner Service Requests'],
        summary: 'View a single service request',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service request details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
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

        if ($serviceRequest->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'service_request' => $serviceRequest->loadCount('quotes')->load([
                'serviceCategory:id,name,slug,icon',
                'provider:id,full_name,phone,profile_photo',
            ]),
        ]);
    }

    #[OA\Put(
        path: '/api/homeowner/service-requests/{serviceRequest}',
        tags: ['Homeowner Service Requests'],
        summary: 'Update a service request (only while still open)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ServiceRequestBody')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
            new OA\Response(response: 422, description: 'Validation error, or request is no longer open'),
        ]
    )]
    public function update(Request $request, ServiceRequest $serviceRequest): JsonResponse
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

        if ($serviceRequest->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Only an open request can be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:3000'],
            'address' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:150'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'preferred_time' => ['nullable', 'string', 'max:100'],
        ]);

        $category = ServiceCategory::query()
            ->where('id', $validated['service_category_id'])
            ->where('active', true)
            ->where('transaction_type', 'on_demand_service')
            ->first();

        if ($category === null) {
            return response()->json([
                'success' => false,
                'message' => 'That category is not available for one-off service bookings.',
            ], 422);
        }

        $serviceRequest->update([
            'service_category_id' => $category->id,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'address' => $validated['address'],
            'district' => $validated['district'],
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'preferred_date' => $validated['preferred_date'] ?? null,
            'preferred_time' => $validated['preferred_time'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Service request updated.',
            'service_request' => $serviceRequest->fresh(),
        ]);
    }

    #[OA\Delete(
        path: '/api/homeowner/service-requests/{serviceRequest}',
        tags: ['Homeowner Service Requests'],
        summary: 'Delete a service request (only while still open, with no quotes)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
            new OA\Response(response: 422, description: 'Request already has quotes or is no longer open'),
        ]
    )]
    public function destroy(Request $request, ServiceRequest $serviceRequest): JsonResponse
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

        if ($serviceRequest->status !== 'open' || $serviceRequest->quotes()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Only an open request with no quotes yet can be deleted. Cancel it instead.',
            ], 422);
        }

        $serviceRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service request deleted.',
        ]);
    }
}
