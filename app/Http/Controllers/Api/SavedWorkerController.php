<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedWorker;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Saved Workers', description: 'Homeowner\'s bookmarked/favorited worker list')]
class SavedWorkerController extends Controller
{
    /**
     * Return all workers saved by the authenticated homeowner.
     */
    #[OA\Get(
        path: '/api/homeowner/saved-workers',
        tags: ['Saved Workers'],
        summary: 'List saved workers',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Saved workers',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'saved_workers', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can view saved workers'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $homeowner = $request->user();

        if ($homeowner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($homeowner->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can view saved workers.',
            ], 403);
        }

        $savedWorkers = SavedWorker::query()
            ->with([
                'worker:id,full_name,profile_photo,location,is_verified,created_at',
                'worker.workerProfile:id,user_id,age,religion,gender,district,work_type,bio,experience_years,availability,rating,total_reviews,jobs_completed,identity_verified,background_checked,police_clearance,medical_clearance,featured,active',
            ])
            ->where('homeowner_id', $homeowner->id)
            ->whereHas('worker', fn ($q) => $q->where('is_verified', true))
            ->whereHas('worker.workerProfile', function ($q): void {
                $q->where('profile_completed', true)
                    ->where('active', true)
                    ->where('verification_status', 'approved')
                    ->where('identity_verified', true);
            })
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'saved_workers' => $savedWorkers,
        ]);
    }

    /**
     * Save a worker for the authenticated homeowner.
     */
    #[OA\Post(
        path: '/api/homeowner/saved-workers/{worker}',
        tags: ['Saved Workers'],
        summary: 'Save (bookmark) a worker',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'worker', in: 'path', required: true, description: 'Worker user ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Already saved'),
            new OA\Response(response: 201, description: 'Worker saved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can save workers'),
            new OA\Response(response: 422, description: 'Invalid or unavailable worker'),
        ]
    )]
    public function store(
        Request $request,
        User $worker
    ): JsonResponse {
        $homeowner = $request->user();

        if ($homeowner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($homeowner->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can save workers.',
            ], 403);
        }

        if ($worker->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'The selected account is not a worker.',
            ], 422);
        }

        if (
            $worker->workerProfile === null
            || !$worker->workerProfile->active
            || !$worker->workerProfile->profile_completed
            || $worker->workerProfile->verification_status !== 'approved'
            || !$worker->workerProfile->identity_verified
            || !$worker->is_verified
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This worker profile is not available.',
            ], 422);
        }

        try {
            $savedWorker = SavedWorker::query()
                ->firstOrCreate([
                    'homeowner_id' => $homeowner->id,
                    'worker_id' => $worker->id,
                ]);

            return response()->json([
                'success' => true,
                'message' => $savedWorker->wasRecentlyCreated
                    ? 'Worker saved successfully.'
                    : 'This worker is already saved.',
                'is_saved' => true,
                'saved_worker' => $savedWorker,
            ], $savedWorker->wasRecentlyCreated ? 201 : 200);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to save the worker.',
            ], 500);
        }
    }

    /**
     * Remove a worker from the homeowner's saved list.
     */
    #[OA\Delete(
        path: '/api/homeowner/saved-workers/{worker}',
        tags: ['Saved Workers'],
        summary: 'Remove a worker from saved list',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'worker', in: 'path', required: true, description: 'Worker user ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removed (or was not saved)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can remove saved workers'),
        ]
    )]
    public function destroy(
        Request $request,
        User $worker
    ): JsonResponse {
        $homeowner = $request->user();

        if ($homeowner === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($homeowner->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can remove saved workers.',
            ], 403);
        }

        $savedWorker = SavedWorker::query()
            ->where('homeowner_id', $homeowner->id)
            ->where('worker_id', $worker->id)
            ->first();

        if ($savedWorker === null) {
            return response()->json([
                'success' => true,
                'message' => 'Worker is not currently saved.',
                'is_saved' => false,
            ]);
        }

        try {
            $savedWorker->delete();

            return response()->json([
                'success' => true,
                'message' => 'Worker removed from saved workers.',
                'is_saved' => false,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to remove the saved worker.',
            ], 500);
        }
    }
}