<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceQuote;
use App\Models\ServiceRequest;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Service Quotes', description: 'Submitting, viewing, and withdrawing quotes on a service request')]
class ServiceQuoteController extends Controller
{
    /**
     * Provider: submit a quote on an open service request.
     */
    #[OA\Post(
        path: '/api/provider/service-requests/{serviceRequest}/quotes',
        tags: ['Service Quotes'],
        summary: 'Submit a quote on a service request',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', example: 50000),
                    new OA\Property(property: 'estimated_duration', type: 'string', nullable: true, example: '2 hours'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Quote submitted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker or company accounts can submit quotes'),
            new OA\Response(response: 422, description: 'Request no longer open, or a quote was already submitted'),
        ]
    )]
    public function store(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $provider = $request->user();

        if ($provider === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!in_array($provider->role, ['worker', 'company'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only worker or company accounts can submit quotes.',
            ], 403);
        }

        if ($serviceRequest->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'This request is no longer open for quotes.',
            ], 422);
        }

        $alreadyQuoted = ServiceQuote::query()
            ->where('service_request_id', $serviceRequest->id)
            ->where('provider_id', $provider->id)
            ->where('status', '!=', 'withdrawn')
            ->exists();

        if ($alreadyQuoted) {
            return response()->json([
                'success' => false,
                'message' => 'You have already submitted a quote on this request.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:500'],
            'estimated_duration' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $quote = ServiceQuote::query()->create([
            'service_request_id' => $serviceRequest->id,
            'provider_id' => $provider->id,
            'amount' => $validated['amount'],
            'estimated_duration' => $validated['estimated_duration'] ?? null,
            'message' => $validated['message'] ?? null,
            'status' => 'pending',
        ]);

        AppNotificationService::send(
            $serviceRequest->homeowner_id,
            'service_quote_received',
            'service_requests',
            'New quote received',
            $provider->full_name . ' sent a quote for "' . $serviceRequest->title . '".',
            'service_request',
            $serviceRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Quote submitted.',
            'quote' => $quote,
        ], 201);
    }

    /**
     * Homeowner: list quotes received on one of their service requests.
     */
    #[OA\Get(
        path: '/api/homeowner/service-requests/{serviceRequest}/quotes',
        tags: ['Service Quotes'],
        summary: 'List quotes received on a service request',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'serviceRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Quotes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'quotes', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
        ]
    )]
    public function homeownerIndex(Request $request, ServiceRequest $serviceRequest): JsonResponse
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

        $quotes = $serviceRequest->quotes()
            ->with('provider:id,full_name,phone,profile_photo,role,is_verified')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'quotes' => $quotes,
        ]);
    }

    /**
     * Provider: list quotes they have submitted.
     */
    #[OA\Get(
        path: '/api/provider/service-quotes',
        tags: ['Service Quotes'],
        summary: 'List the authenticated provider\'s submitted quotes',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Quotes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'quotes', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function providerIndex(Request $request): JsonResponse
    {
        $provider = $request->user();

        if ($provider === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $quotes = ServiceQuote::query()
            ->with('serviceRequest:id,title,status,district,service_category_id')
            ->where('provider_id', $provider->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'quotes' => $quotes,
        ]);
    }

    /**
     * Provider: withdraw a pending quote.
     */
    #[OA\Patch(
        path: '/api/provider/service-quotes/{quote}/withdraw',
        tags: ['Service Quotes'],
        summary: 'Withdraw a pending quote',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'quote', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Quote withdrawn'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not your quote'),
            new OA\Response(response: 422, description: 'Only a pending quote can be withdrawn'),
        ]
    )]
    public function withdraw(Request $request, ServiceQuote $quote): JsonResponse
    {
        $provider = $request->user();

        if ($provider === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($quote->provider_id !== $provider->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        if ($quote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only a pending quote can be withdrawn.',
            ], 422);
        }

        $quote->forceFill([
            'status' => 'withdrawn',
            'responded_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Quote withdrawn.',
        ]);
    }

    /**
     * Homeowner: accept one quote, booking that provider and
     * auto-declining every other pending quote on this request.
     */
    #[OA\Patch(
        path: '/api/homeowner/service-quotes/{quote}/accept',
        tags: ['Service Quotes'],
        summary: 'Accept a quote, booking the provider',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'quote', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Provider booked, other quotes auto-declined'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
            new OA\Response(response: 422, description: 'Request is not open, or quote is not pending'),
        ]
    )]
    public function accept(Request $request, ServiceQuote $quote): JsonResponse
    {
        $serviceRequest = $quote->serviceRequest;

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
                'message' => 'This request is no longer open.',
            ], 422);
        }

        if ($quote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This quote is no longer pending.',
            ], 422);
        }

        DB::transaction(function () use ($serviceRequest, $quote) {
            $quote->forceFill([
                'status' => 'accepted',
                'responded_at' => now(),
            ])->save();

            $serviceRequest->forceFill([
                'status' => 'booked',
                'provider_id' => $quote->provider_id,
                'booked_at' => now(),
            ])->save();

            ServiceQuote::query()
                ->where('service_request_id', $serviceRequest->id)
                ->where('id', '!=', $quote->id)
                ->where('status', 'pending')
                ->get()
                ->each(function (ServiceQuote $otherQuote) {
                    $otherQuote->forceFill([
                        'status' => 'declined',
                        'responded_at' => now(),
                    ])->save();

                    AppNotificationService::send(
                        $otherQuote->provider_id,
                        'service_quote_declined',
                        'service_requests',
                        'Quote not selected',
                        'The homeowner chose another provider for this request.',
                        'service_request',
                        $otherQuote->service_request_id
                    );
                });
        });

        AppNotificationService::send(
            $quote->provider_id,
            'service_quote_accepted',
            'service_requests',
            'Quote accepted',
            'Your quote for "' . $serviceRequest->title . '" was accepted. You are now booked.',
            'service_request',
            $serviceRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Provider booked.',
            'service_request' => $serviceRequest->fresh(),
        ]);
    }

    /**
     * Homeowner: decline a single quote without accepting another.
     */
    #[OA\Patch(
        path: '/api/homeowner/service-quotes/{quote}/decline',
        tags: ['Service Quotes'],
        summary: 'Decline a single quote',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'quote', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Quote declined'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this request'),
            new OA\Response(response: 422, description: 'Quote is not pending'),
        ]
    )]
    public function decline(Request $request, ServiceQuote $quote): JsonResponse
    {
        $serviceRequest = $quote->serviceRequest;

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

        if ($quote->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This quote is no longer pending.',
            ], 422);
        }

        $quote->forceFill([
            'status' => 'declined',
            'responded_at' => now(),
        ])->save();

        AppNotificationService::send(
            $quote->provider_id,
            'service_quote_declined',
            'service_requests',
            'Quote declined',
            'Your quote for "' . $serviceRequest->title . '" was declined.',
            'service_request',
            $serviceRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Quote declined.',
        ]);
    }
}
