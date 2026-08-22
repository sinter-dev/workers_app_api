<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HiringRequest;
use App\Models\Job;
use App\Models\JobApplication;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Job Cancellation', description: 'Worker withdrawal from and homeowner cancellation of assigned jobs')]
class JobCancellationController extends Controller
{
    #[OA\Patch(
        path: '/api/worker/active-jobs/{job}/withdraw',
        tags: ['Job Cancellation'],
        summary: 'Withdraw from an assigned job (worker)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 100),
                    new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Withdrawn'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Job not assigned to this worker'),
            new OA\Response(response: 422, description: 'Job can no longer be withdrawn from'),
        ]
    )]
    public function workerWithdraw(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if ($user === null) return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if ($user->role !== 'worker') return response()->json(['success' => false, 'message' => 'Only workers can withdraw from assigned jobs.'], 403);
        if ($job->accepted_worker_id !== $user->id) return response()->json(['success' => false, 'message' => 'This job is not assigned to you.'], 403);
        if (!in_array($job->status, ['accepted', 'in_progress', 'awaiting_confirmation'], true)) {
            return response()->json(['success' => false, 'message' => 'This job can no longer be withdrawn from.'], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $result = DB::transaction(function () use ($job, $user, $validated) {
                $locked = Job::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
                $wasPublic = ($locked->visibility ?? 'public') === 'public';

                JobApplication::query()
                    ->where('job_id', $locked->id)
                    ->where('worker_id', $user->id)
                    ->whereIn('status', ['accepted', 'pending'])
                    ->update(['status' => 'withdrawn', 'responded_at' => now()]);

                HiringRequest::query()
                    ->where('job_id', $locked->id)
                    ->where('worker_id', $user->id)
                    ->whereIn('status', ['accepted', 'pending'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                $locked->update([
                    'accepted_worker_id' => null,
                    // A public vacancy becomes available again. A private/direct
                    // offer ends because it was created for this specific worker.
                    'status' => $wasPublic ? 'open' : 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => 'worker',
                    'cancellation_reason' => $validated['reason'],
                    'cancellation_note' => $validated['note'] ?? null,
                    'started_at' => $wasPublic ? null : $locked->started_at,
                    'completion_requested_at' => null,
                ]);

                return $locked->fresh();
            });

            AppNotificationService::send(
                $result->homeowner_id,
                'job_withdrawn_by_worker',
                'jobs',
                'Worker withdrew from job',
                $user->full_name . ' withdrew from ' . $result->title . '. Reason: ' . $validated['reason'] . '.',
                'homeowner_job',
                $result->id
            );

            return response()->json([
                'success' => true,
                'message' => ($result->status === 'open')
                    ? 'You withdrew from the job. The vacancy is open again for the homeowner.'
                    : 'You withdrew from the job successfully.',
                'job' => $result,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to withdraw from this job.'], 500);
        }
    }

    #[OA\Patch(
        path: '/api/homeowner/active-jobs/{job}/cancel',
        tags: ['Job Cancellation'],
        summary: 'Cancel an assigned job (homeowner)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', maxLength: 100),
                    new OA\Property(property: 'note', type: 'string', nullable: true, maxLength: 1000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Job cancelled'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
            new OA\Response(response: 422, description: 'Job can no longer be cancelled'),
        ]
    )]
    public function homeownerCancel(Request $request, Job $job): JsonResponse
    {
        $user = $request->user();
        if ($user === null) return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        if ($user->role !== 'homeowner') return response()->json(['success' => false, 'message' => 'Only homeowners can cancel assigned jobs.'], 403);
        if ($job->homeowner_id !== $user->id) return response()->json(['success' => false, 'message' => 'You cannot cancel this job.'], 403);
        if (!in_array($job->status, ['accepted', 'in_progress', 'awaiting_confirmation'], true)) {
            return response()->json(['success' => false, 'message' => 'This job can no longer be cancelled.'], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $cancelled = DB::transaction(function () use ($job, $validated) {
                $locked = Job::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();

                JobApplication::query()
                    ->where('job_id', $locked->id)
                    ->where('status', 'accepted')
                    ->update(['status' => 'declined', 'responded_at' => now()]);

                HiringRequest::query()
                    ->where('job_id', $locked->id)
                    ->whereIn('status', ['accepted', 'pending'])
                    ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

                $locked->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => 'homeowner',
                    'cancellation_reason' => $validated['reason'],
                    'cancellation_note' => $validated['note'] ?? null,
                    'completion_requested_at' => null,
                ]);

                return $locked->fresh();
            });

            if ($cancelled->accepted_worker_id !== null) {
                AppNotificationService::send(
                    $cancelled->accepted_worker_id,
                    'job_cancelled_by_homeowner',
                    'jobs',
                    'Job cancelled',
                    $user->full_name . ' cancelled ' . $cancelled->title . '. Reason: ' . $validated['reason'] . '.',
                    'worker_active_job',
                    $cancelled->id
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Job cancelled successfully. The cancellation has been recorded.',
                'job' => $cancelled,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['success' => false, 'message' => 'Unable to cancel this job.'], 500);
        }
    }
}