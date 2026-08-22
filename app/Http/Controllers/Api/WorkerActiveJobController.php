<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\HiringRequest;
use App\Models\User;
use App\Models\HomeownerProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Worker Active Jobs', description: 'Managing jobs the worker has been assigned (start, complete)')]
class WorkerActiveJobController extends Controller
{
    /**
     * Show one job assigned to the authenticated worker.
     */
    #[OA\Get(
        path: '/api/worker/active-jobs/{job}',
        tags: ['Worker Active Jobs'],
        summary: 'View an assigned/active job',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not assigned to this job'),
        ]
    )]
    public function show(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can access active jobs.',
            ], 403);
        }

        if ($job->accepted_worker_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'This job is not assigned to you.',
            ], 403);
        }

        if (!in_array($job->status, [
            'accepted',
            'in_progress',
            'awaiting_confirmation',
            'completed',
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'This job is not available in the worker workflow.',
            ], 422);
        }

        $job->load([
            'homeowner:id,full_name,phone,email,profile_photo,location,is_verified',
        ]);

        return response()->json([
            'success' => true,
            'job' => $this->formatJob($job),
        ]);
    }

    /**
     * Start an accepted job.
     */
    #[OA\Patch(
        path: '/api/worker/active-jobs/{job}/start',
        tags: ['Worker Active Jobs'],
        summary: 'Mark an accepted job as started (in progress)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job marked in progress'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not assigned to this job'),
            new OA\Response(response: 422, description: 'Job is not in an accepted state'),
        ]
    )]
    public function start(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can start jobs.',
            ], 403);
        }

        if ($job->accepted_worker_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'This job is not assigned to you.',
            ], 403);
        }

        if ($job->status !== 'accepted') {
            return response()->json([
                'success' => false,
                'message' => 'Only accepted jobs can be started.',
            ], 422);
        }

        $job->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        AppNotificationService::send(
            $this->homeownerUserId($job, $user->id),
            'job_started',
            'jobs',
            'Job started',
            $user->full_name . ' started work on ' . $job->title . '.',
            'homeowner_active_job',
            $job->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Job started successfully.',
            'job' => $job->fresh(),
        ]);
    }

    /**
     * Mark an in-progress job as finished by the worker.
     *
     * The homeowner must still confirm completion.
     */
    #[OA\Patch(
        path: '/api/worker/active-jobs/{job}/complete',
        tags: ['Worker Active Jobs'],
        summary: 'Mark a job as completed by the worker (pending homeowner confirmation)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job marked awaiting confirmation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not assigned to this job'),
            new OA\Response(response: 422, description: 'Job is not in progress'),
        ]
    )]
    public function complete(
        Request $request,
        Job $job
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can finish jobs.',
            ], 403);
        }

        if ($job->accepted_worker_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'This job is not assigned to you.',
            ], 403);
        }

        if ($job->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Only jobs in progress can be marked as finished.',
            ], 422);
        }

        try {
            $updatedJob = DB::transaction(function () use ($job) {
                $job->update([
                    'status' => 'awaiting_confirmation',
                    'completion_requested_at' => now(),
                ]);

                return $job->fresh();
            });

            AppNotificationService::send(
                $this->homeownerUserId($updatedJob, $user->id),
                'completion_requested',
                'jobs',
                'Work marked finished',
                $user->full_name . ' marked ' . $updatedJob->title . ' as finished. Please confirm completion.',
                'homeowner_active_job',
                $updatedJob->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Work finished. Waiting for homeowner confirmation.',
                'job' => $updatedJob,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to mark this job as finished.',
            ], 500);
        }
    }


    /**
     * Resolve the actual homeowner USER id used by notifications.
     */
    private function homeownerUserId(Job $job, int $workerId): int
    {
        $candidate = (int) $job->homeowner_id;

        if ($candidate > 0 && User::query()
            ->whereKey($candidate)
            ->where('role', 'homeowner')
            ->exists()) {
            return $candidate;
        }

        $requestHomeownerId = (int) (HiringRequest::query()
            ->where('job_id', $job->id)
            ->where('worker_id', $workerId)
            ->latest('id')
            ->value('homeowner_id') ?? 0);

        if ($requestHomeownerId > 0 && User::query()
            ->whereKey($requestHomeownerId)
            ->where('role', 'homeowner')
            ->exists()) {
            return $requestHomeownerId;
        }

        $profileUserId = (int) (HomeownerProfile::query()
            ->whereKey($candidate)
            ->value('user_id') ?? 0);

        if ($profileUserId > 0) {
            return $profileUserId;
        }

        return $candidate;
    }

    /**
     * Format active job data for Flutter.
     */
    private function formatJob(Job $job): array
    {
        $homeowner = $job->homeowner;

        return [
            'id' => $job->id,
            'title' => $job->title,
            'category' => $job->category,
            'description' => $job->description,

            'address' => $job->address,
            'district' => $job->district,
            'latitude' => $job->latitude,
            'longitude' => $job->longitude,

            'start_date' => $job->start_date,
            'start_time' => $job->start_time,
            'duration' => $job->duration,

            'budget_type' => $job->budget_type,
            'budget_amount' => $job->budget_amount,

            'status' => $job->status,
            'is_urgent' => $job->is_urgent,
            'accepted_at' => $job->accepted_at,
            'started_at' => $job->started_at,
            'completion_requested_at' => $job->completion_requested_at,
            'completed_at' => $job->completed_at,
            'created_at' => $job->created_at,

            'homeowner' => $homeowner === null
                ? null
                : [
                    'id' => $homeowner->id,
                    'full_name' => $homeowner->full_name,
                    'phone' => $homeowner->phone,
                    'email' => $homeowner->email,
                    'profile_photo' => $homeowner->profile_photo,
                    'location' => $homeowner->location,
                    'is_verified' => $homeowner->is_verified,
                ],
        ];
    }
}