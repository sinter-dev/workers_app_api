<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\WorkerProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Homeowner Job Completion', description: 'Homeowner confirmation that an assigned job was completed')]
class HomeownerJobCompletionController extends Controller
{
    /**
     * Confirm that the assigned worker completed the job.
     */
    #[OA\Patch(
        path: '/api/homeowner/jobs/{job}/confirm-completion',
        tags: ['Homeowner Job Completion'],
        summary: 'Confirm a job was completed by the assigned worker',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Job marked completed'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can confirm job completion'),
            new OA\Response(response: 422, description: 'Job is not awaiting confirmation'),
        ]
    )]
    public function confirm(
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

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can confirm job completion.',
            ], 403);
        }

        if ($job->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot confirm completion for this job.',
            ], 403);
        }

        if ($job->status !== 'awaiting_confirmation') {
            return response()->json([
                'success' => false,
                'message' => 'This job is not awaiting completion confirmation.',
            ], 422);
        }

        if ($job->accepted_worker_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'This job does not have an assigned worker.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($job) {
                $workerProfile = WorkerProfile::query()
                    ->where('user_id', $job->accepted_worker_id)
                    ->lockForUpdate()
                    ->first();

                $job->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                if ($workerProfile !== null) {
                    $workerProfile->increment('jobs_completed');

                    $workerProfile->update([
                        'availability' => 'available',
                    ]);
                }

                return [
                    'job' => $job->fresh([
                        'homeowner',
                        'worker',
                    ]),
                    'worker_profile' => $workerProfile?->fresh(),
                ];
            });

            AppNotificationService::send(
                $job->accepted_worker_id,
                'completion_confirmed',
                'jobs',
                'Job completed',
                $user->full_name . ' confirmed completion of ' . $job->title . '.',
                'worker_active_job',
                $job->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Job completion confirmed successfully.',
                'job' => $result['job'],
                'worker_profile' => $result['worker_profile'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to confirm job completion.',
            ], 500);
        }
    }
}