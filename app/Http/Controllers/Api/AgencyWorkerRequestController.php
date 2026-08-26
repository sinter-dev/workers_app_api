<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgencyWorkerRequest;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Agency Worker Requests', description: 'Connecting an existing worker to an agency: worker-initiated join requests, or agency-initiated invitations by phone')]
class AgencyWorkerRequestController extends Controller
{
    /**
     * Worker: list requests concerning them (both ones they sent
     * to join an agency, and ones an agency sent inviting them).
     */
    #[OA\Get(
        path: '/api/worker/agency-requests',
        tags: ['Agency Worker Requests'],
        summary: 'List the authenticated worker\'s agency requests',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Agency requests'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this'),
        ]
    )]
    public function workerIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can access this.',
            ], 403);
        }

        $requests = AgencyWorkerRequest::query()
            ->with('agency:id,full_name,phone,profile_photo,location')
            ->where('worker_id', $user->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'requests' => $requests,
        ]);
    }

    /**
     * Agency: list requests concerning them (both ones they sent
     * inviting a worker, and ones a worker sent asking to join).
     */
    #[OA\Get(
        path: '/api/agency/worker-requests',
        tags: ['Agency Worker Requests'],
        summary: 'List the authenticated agency\'s worker requests',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Worker requests'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only agency accounts can access this'),
        ]
    )]
    public function agencyIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can access this.',
            ], 403);
        }

        $requests = AgencyWorkerRequest::query()
            ->with('worker:id,full_name,phone,profile_photo,location')
            ->where('agency_id', $user->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'requests' => $requests,
        ]);
    }

    /**
     * Worker: request to join an agency.
     */
    #[OA\Post(
        path: '/api/worker/agency-requests',
        tags: ['Agency Worker Requests'],
        summary: 'Request to join an agency',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['agency_id'],
                properties: [
                    new OA\Property(property: 'agency_id', type: 'integer'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Join request sent'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this'),
            new OA\Response(response: 422, description: 'Invalid agency, already managed by an agency, or a pending request already exists'),
        ]
    )]
    public function storeAsWorker(Request $request): JsonResponse
    {
        $worker = $request->user();

        if ($worker === null || $worker->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can access this.',
            ], 403);
        }

        $validated = $request->validate([
            'agency_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $agency = User::query()
            ->where('id', $validated['agency_id'])
            ->where('role', 'agency')
            ->first();

        if ($agency === null) {
            return response()->json([
                'success' => false,
                'message' => 'The selected account is not an agency.',
            ], 422);
        }

        $workerProfile = WorkerProfile::query()->where('user_id', $worker->id)->first();

        if ($workerProfile?->agency_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'You are already managed by an agency.',
            ], 422);
        }

        $existingPending = AgencyWorkerRequest::query()
            ->where('worker_id', $worker->id)
            ->where('agency_id', $agency->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending request with this agency.',
            ], 422);
        }

        $agencyWorkerRequest = AgencyWorkerRequest::query()->create([
            'agency_id' => $agency->id,
            'worker_id' => $worker->id,
            'initiated_by' => 'worker',
            'status' => 'pending',
            'message' => $validated['message'] ?? null,
        ]);

        AppNotificationService::send(
            $agency->id,
            'agency_join_request',
            'agency',
            'New worker request',
            $worker->full_name . ' wants to join your agency.',
            'agency_worker_request',
            $agencyWorkerRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Request sent to the agency.',
            'request' => $agencyWorkerRequest,
        ], 201);
    }

    /**
     * Agency: invite an existing worker by phone number.
     */
    #[OA\Post(
        path: '/api/agency/worker-requests',
        tags: ['Agency Worker Requests'],
        summary: 'Invite an existing worker to join, by phone number',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '+256700000000'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Invitation sent'),
            new OA\Response(response: 403, description: 'Only agency accounts can access this'),
            new OA\Response(response: 422, description: 'No worker with that phone number, already managed by an agency, or a pending request already exists'),
        ]
    )]
    public function storeAsAgency(Request $request): JsonResponse
    {
        $agency = $request->user();

        if ($agency === null || $agency->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can access this.',
            ], 403);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $worker = User::query()
            ->where('phone', $validated['phone'])
            ->where('role', 'worker')
            ->first();

        if ($worker === null) {
            return response()->json([
                'success' => false,
                'message' => 'No worker account found with that phone number.',
            ], 422);
        }

        $workerProfile = WorkerProfile::query()->where('user_id', $worker->id)->first();

        if ($workerProfile?->agency_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'That worker is already managed by an agency.',
            ], 422);
        }

        $existingPending = AgencyWorkerRequest::query()
            ->where('worker_id', $worker->id)
            ->where('agency_id', $agency->id)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending invitation with this worker.',
            ], 422);
        }

        $agencyWorkerRequest = AgencyWorkerRequest::query()->create([
            'agency_id' => $agency->id,
            'worker_id' => $worker->id,
            'initiated_by' => 'agency',
            'status' => 'pending',
            'message' => $validated['message'] ?? null,
        ]);

        AppNotificationService::send(
            $worker->id,
            'agency_invitation',
            'agency',
            'Agency invitation',
            $agency->full_name . ' invited you to join their agency.',
            'agency_worker_request',
            $agencyWorkerRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Invitation sent to the worker.',
            'request' => $agencyWorkerRequest,
        ], 201);
    }

    /**
     * Accept a pending request. Only the receiving party may
     * accept — i.e. the agency accepts a worker-initiated
     * request, and the worker accepts an agency-initiated one.
     */
    #[OA\Post(
        path: '/api/agency-worker-requests/{agencyWorkerRequest}/accept',
        tags: ['Agency Worker Requests'],
        summary: 'Accept a pending agency-worker request (whichever party is the recipient)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agencyWorkerRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request accepted, worker linked to agency'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the recipient of this request'),
            new OA\Response(response: 422, description: 'Request is not pending, or worker is already managed by an agency'),
        ]
    )]
    public function accept(Request $request, AgencyWorkerRequest $agencyWorkerRequest): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $isRecipient = $this->isRecipient($user, $agencyWorkerRequest);

        if (!$isRecipient) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the recipient of this request.',
            ], 403);
        }

        if ($agencyWorkerRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This request is no longer pending.',
            ], 422);
        }

        $workerProfile = WorkerProfile::query()
            ->where('user_id', $agencyWorkerRequest->worker_id)
            ->first();

        if ($workerProfile?->agency_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This worker is already managed by an agency.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($agencyWorkerRequest, $workerProfile) {
                $agencyWorkerRequest->forceFill([
                    'status' => 'accepted',
                    'responded_at' => now(),
                ])->save();

                $workerProfile?->update([
                    'agency_id' => $agencyWorkerRequest->agency_id,
                ]);
            });
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to accept this request.',
            ], 500);
        }

        $notifyUserId = $agencyWorkerRequest->initiated_by === 'worker'
            ? $agencyWorkerRequest->worker_id
            : $agencyWorkerRequest->agency_id;

        AppNotificationService::send(
            $notifyUserId,
            'agency_worker_request_accepted',
            'agency',
            'Request accepted',
            'Your agency connection request has been accepted.',
            'agency_worker_request',
            $agencyWorkerRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Request accepted. The worker is now linked to the agency.',
            'request' => $agencyWorkerRequest->fresh(),
        ]);
    }

    /**
     * Decline a pending request. Only the receiving party may decline.
     */
    #[OA\Post(
        path: '/api/agency-worker-requests/{agencyWorkerRequest}/decline',
        tags: ['Agency Worker Requests'],
        summary: 'Decline a pending agency-worker request (whichever party is the recipient)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agencyWorkerRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request declined'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the recipient of this request'),
            new OA\Response(response: 422, description: 'Request is not pending'),
        ]
    )]
    public function decline(Request $request, AgencyWorkerRequest $agencyWorkerRequest): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$this->isRecipient($user, $agencyWorkerRequest)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the recipient of this request.',
            ], 403);
        }

        if ($agencyWorkerRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This request is no longer pending.',
            ], 422);
        }

        $agencyWorkerRequest->forceFill([
            'status' => 'declined',
            'responded_at' => now(),
        ])->save();

        $notifyUserId = $agencyWorkerRequest->initiated_by === 'worker'
            ? $agencyWorkerRequest->worker_id
            : $agencyWorkerRequest->agency_id;

        AppNotificationService::send(
            $notifyUserId,
            'agency_worker_request_declined',
            'agency',
            'Request declined',
            'Your agency connection request was declined.',
            'agency_worker_request',
            $agencyWorkerRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Request declined.',
        ]);
    }

    /**
     * Withdraw a pending request. Only the party who sent it may withdraw it.
     */
    #[OA\Post(
        path: '/api/agency-worker-requests/{agencyWorkerRequest}/withdraw',
        tags: ['Agency Worker Requests'],
        summary: 'Withdraw a pending agency-worker request (whichever party sent it)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agencyWorkerRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request withdrawn'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the sender of this request'),
            new OA\Response(response: 422, description: 'Request is not pending'),
        ]
    )]
    public function withdraw(Request $request, AgencyWorkerRequest $agencyWorkerRequest): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $isSender = $this->isSender($user, $agencyWorkerRequest);

        if (!$isSender) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the sender of this request.',
            ], 403);
        }

        if ($agencyWorkerRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This request is no longer pending.',
            ], 422);
        }

        $agencyWorkerRequest->forceFill([
            'status' => 'withdrawn',
            'responded_at' => now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Request withdrawn.',
        ]);
    }

    private function isRecipient(User $user, AgencyWorkerRequest $agencyWorkerRequest): bool
    {
        if ($agencyWorkerRequest->initiated_by === 'worker') {
            return $user->role === 'agency' && $user->id === $agencyWorkerRequest->agency_id;
        }

        return $user->role === 'worker' && $user->id === $agencyWorkerRequest->worker_id;
    }

    private function isSender(User $user, AgencyWorkerRequest $agencyWorkerRequest): bool
    {
        if ($agencyWorkerRequest->initiated_by === 'worker') {
            return $user->role === 'worker' && $user->id === $agencyWorkerRequest->worker_id;
        }

        return $user->role === 'agency' && $user->id === $agencyWorkerRequest->agency_id;
    }
}
