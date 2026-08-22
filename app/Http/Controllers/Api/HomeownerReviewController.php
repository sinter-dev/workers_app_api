<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Review;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Homeowner Reviews', description: 'Homeowner leaving/editing a review for a completed job\'s worker')]
#[OA\Schema(
    schema: 'ReviewRequest',
    required: ['rating'],
    properties: [
        new OA\Property(property: 'rating', type: 'integer', minimum: 1, maximum: 5),
        new OA\Property(property: 'comment', type: 'string', nullable: true),
    ]
)]
class HomeownerReviewController extends Controller
{
    /**
     * Create a review for a completed job.
     */
    #[OA\Post(
        path: '/api/homeowner/jobs/{job}/review',
        tags: ['Homeowner Reviews'],
        summary: 'Leave a review for a completed job',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ReviewRequest')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Review created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
            new OA\Response(response: 422, description: 'Job not completed, no assigned worker, or already reviewed'),
        ]
    )]
    public function store(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        $authorizationResponse = $this->authorizeHomeownerJob(
            user: $user,
            job: $job,
        );

        if ($authorizationResponse !== null) {
            return $authorizationResponse;
        }

        if ($job->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Only completed jobs can be reviewed.',
            ], 422);
        }

        if ($job->accepted_worker_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'This job does not have an assigned worker.',
            ], 422);
        }

        $existingReview = Review::query()
            ->where('job_id', $job->id)
            ->where('homeowner_id', $user->id)
            ->first();

        if ($existingReview !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This job has already been reviewed.',
                'review' => $existingReview,
            ], 422);
        }

        $validated = $this->validateReview($request);

        try {
            $result = DB::transaction(function () use (
                $validated,
                $job,
                $user
            ) {
                $review = Review::query()->create([
                    'job_id' => $job->id,
                    'worker_id' => $job->accepted_worker_id,
                    'homeowner_id' => $user->id,
                    'rating' => $validated['rating'],
                    'comment' => $validated['comment'] ?? null,
                ]);

                $workerProfile = $this->recalculateWorkerRating(
                    workerId: $job->accepted_worker_id,
                );

                return [
                    'review' => $review->fresh([
                        'worker',
                        'homeowner',
                        'job',
                    ]),
                    'worker_profile' => $workerProfile,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Review submitted successfully.',
                'review' => $result['review'],
                'worker_profile' => $result['worker_profile'],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to submit this review.',
            ], 500);
        }
    }

    /**
     * Show the homeowner's review for one completed job.
     */
    #[OA\Get(
        path: '/api/homeowner/jobs/{job}/review',
        tags: ['Homeowner Reviews'],
        summary: 'Get the homeowner\'s review for a job',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Review (or null if none exists)'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
        ]
    )]
    public function show(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        $authorizationResponse = $this->authorizeHomeownerJob(
            user: $user,
            job: $job,
        );

        if ($authorizationResponse !== null) {
            return $authorizationResponse;
        }

        $review = Review::query()
            ->with([
                'worker',
                'homeowner',
                'job',
            ])
            ->where('job_id', $job->id)
            ->where('homeowner_id', $user->id)
            ->first();

        return response()->json([
            'success' => true,
            'has_review' => $review !== null,
            'review' => $review,
        ]);
    }

    /**
     * Update an existing review.
     */
    #[OA\Put(
        path: '/api/homeowner/jobs/{job}/review',
        tags: ['Homeowner Reviews'],
        summary: 'Update an existing review',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ReviewRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Review updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
            new OA\Response(response: 404, description: 'No review exists yet'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        $authorizationResponse = $this->authorizeHomeownerJob(
            user: $user,
            job: $job,
        );

        if ($authorizationResponse !== null) {
            return $authorizationResponse;
        }

        if ($job->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Only completed jobs can have reviews updated.',
            ], 422);
        }

        $review = Review::query()
            ->where('job_id', $job->id)
            ->where('homeowner_id', $user->id)
            ->first();

        if ($review === null) {
            return response()->json([
                'success' => false,
                'message' => 'No review exists for this job.',
            ], 404);
        }

        $validated = $this->validateReview($request);

        try {
            $result = DB::transaction(function () use (
                $review,
                $validated
            ) {
                $review->update([
                    'rating' => $validated['rating'],
                    'comment' => $validated['comment'] ?? null,
                ]);

                $workerProfile = $this->recalculateWorkerRating(
                    workerId: $review->worker_id,
                );

                return [
                    'review' => $review->fresh([
                        'worker',
                        'homeowner',
                        'job',
                    ]),
                    'worker_profile' => $workerProfile,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Review updated successfully.',
                'review' => $result['review'],
                'worker_profile' => $result['worker_profile'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update this review.',
            ], 500);
        }
    }

    /**
     * Delete an existing review.
     */
    #[OA\Delete(
        path: '/api/homeowner/jobs/{job}/review',
        tags: ['Homeowner Reviews'],
        summary: 'Delete a review',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Review deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
            new OA\Response(response: 404, description: 'No review exists'),
        ]
    )]
    public function destroy(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        $authorizationResponse = $this->authorizeHomeownerJob(
            user: $user,
            job: $job,
        );

        if ($authorizationResponse !== null) {
            return $authorizationResponse;
        }

        $review = Review::query()
            ->where('job_id', $job->id)
            ->where('homeowner_id', $user->id)
            ->first();

        if ($review === null) {
            return response()->json([
                'success' => false,
                'message' => 'No review exists for this job.',
            ], 404);
        }

        try {
            $result = DB::transaction(function () use ($review) {
                $workerId = $review->worker_id;

                $review->delete();

                $workerProfile = $this->recalculateWorkerRating(
                    workerId: $workerId,
                );

                return [
                    'worker_profile' => $workerProfile,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Review deleted successfully.',
                'worker_profile' => $result['worker_profile'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to delete this review.',
            ], 500);
        }
    }

    /**
     * Validate review input.
     */
    private function validateReview(Request $request): array
    {
        return $request->validate([
            'rating' => [
                'required',
                'integer',
                'min:1',
                'max:5',
            ],

            'comment' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);
    }

    /**
     * Confirm the request belongs to the job's homeowner.
     */
    private function authorizeHomeownerJob(
        mixed $user,
        Job $job
    ): ?JsonResponse {
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can manage reviews.',
            ], 403);
        }

        if ($job->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot manage the review for this job.',
            ], 403);
        }

        return null;
    }

    /**
     * Recalculate the worker's rating and review total.
     */
    private function recalculateWorkerRating(
        int $workerId
    ): ?WorkerProfile {
        $workerProfile = WorkerProfile::query()
            ->where('user_id', $workerId)
            ->lockForUpdate()
            ->first();

        if ($workerProfile === null) {
            return null;
        }

        $reviewsQuery = Review::query()
            ->where('worker_id', $workerId);

        $totalReviews = $reviewsQuery->count();

        $averageRating = $totalReviews > 0
            ? round(
                (float) $reviewsQuery->avg('rating'),
                2
            )
            : 0;

        $workerProfile->update([
            'rating' => $averageRating,
            'total_reviews' => $totalReviews,
        ]);

        return $workerProfile->fresh();
    }
}