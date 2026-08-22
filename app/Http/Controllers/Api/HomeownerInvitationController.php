<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Homeowner Invitations', description: 'Homeowner directly inviting a specific worker to an existing open job')]
class HomeownerInvitationController extends Controller
{
    #[OA\Post(
        path: '/api/homeowner/jobs/{job}/invite-worker',
        tags: ['Homeowner Invitations'],
        summary: 'Invite a specific worker to apply for an open job',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['worker_id'],
                properties: [
                    new OA\Property(property: 'worker_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Invitation sent'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the job owner'),
            new OA\Response(response: 422, description: 'Job no longer open, or invalid worker'),
        ]
    )]
    public function store(Request $request, Job $job): JsonResponse
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
                'message' => 'Only homeowner accounts can invite workers.',
            ], 403);
        }

        if ($job->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot invite a worker to this job.',
            ], 403);
        }

        if ($job->status !== 'open' || $job->accepted_worker_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This job is no longer open for invitations.',
            ], 422);
        }

        $validated = $request->validate([
            'worker_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'message' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $worker = User::query()
            ->whereKey($validated['worker_id'])
            ->where('role', 'worker')
            ->first();

        if ($worker === null) {
            return response()->json([
                'success' => false,
                'message' => 'The selected worker could not be found.',
            ], 404);
        }

        $workerProfile = WorkerProfile::query()
            ->where('user_id', $worker->id)
            ->first();

        if ($workerProfile === null || !$workerProfile->profile_completed) {
            return response()->json([
                'success' => false,
                'message' => 'This worker has not completed their profile.',
            ], 422);
        }

        if ($workerProfile->verification_status !== 'approved' || !$workerProfile->identity_verified || !$worker->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved workers can receive job invitations.',
            ], 403);
        }

        if ($workerProfile->availability !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'This worker is currently unavailable.',
            ], 422);
        }

        $existingApplication = JobApplication::query()
            ->where('job_id', $job->id)
            ->where('worker_id', $worker->id)
            ->first();

        if ($existingApplication !== null) {
            $message = $existingApplication->invited_by_homeowner
                ? 'You have already invited this worker to the selected job.'
                : 'This worker has already applied for the selected job.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'application' => $existingApplication,
            ], 422);
        }

        $application = JobApplication::query()->create([
            'job_id' => $job->id,
            'worker_id' => $worker->id,
            'invited_by_homeowner' => true,
            'message' => isset($validated['message'])
                ? trim($validated['message'])
                : null,
            'expected_salary' => null,
            'status' => 'pending',
            'responded_at' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Worker invitation sent successfully.',
            'application' => $application->load([
                'job',
                'worker:id,full_name,profile_photo,location,is_verified',
            ]),
        ], 201);
    }
}