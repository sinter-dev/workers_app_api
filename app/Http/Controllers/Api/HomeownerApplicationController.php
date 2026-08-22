<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\WorkerProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Homeowner Applications', description: 'Reviewing and deciding on worker applications for a homeowner\'s job')]
class HomeownerApplicationController extends Controller
{
    /**
     * List all applications for one homeowner job.
     */
    #[OA\Get(
        path: '/api/homeowner/jobs/{job}/applications',
        tags: ['Homeowner Applications'],
        summary: 'List applications for a job',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'job', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Applications list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'applications', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
        ]
    )]
    public function index(
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
                'message' => 'Only homeowner accounts can view applications.',
            ], 403);
        }

        if ($job->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot view applications for this job.',
            ], 403);
        }

        $applications = JobApplication::query()
            ->with([
                'worker.workerProfile.galleryImages',
            ])
            ->where('job_id', $job->id)
            ->latest()
            ->get()
            ->map(function (JobApplication $application): array {
                $worker = $application->worker;
                $profile = $worker?->workerProfile;

                return [
                    'id' => $application->id,
                    'job_id' => $application->job_id,
                    'worker_id' => $application->worker_id,
                    'message' => $application->message,
                    'expected_salary' => $application->expected_salary,
                    'status' => $application->status,
                    'created_at' => $application->created_at,

                    'worker' => $worker === null
                        ? null
                        : [
                            'id' => $worker->id,
                            'full_name' => $worker->full_name,
                            'phone' => $worker->phone,
                            'email' => $worker->email,
                            'profile_photo' => $worker->profile_photo,
                            'location' => $worker->location,
                            'is_verified' => $worker->is_verified,
                        ],

                    'profile' => $profile === null
                        ? null
                        : [
                            'age' => $profile->age,
                            'religion' => $profile->religion,
                            'gender' => $profile->gender,
                            'district' => $profile->district,
                            'work_type' => $profile->work_type,
                            'bio' => $profile->bio,
                            'experience_years' => $profile->experience_years,
                            'availability' => $profile->availability,
                            'rating' => $profile->rating,
                            'total_reviews' => $profile->total_reviews,
                            'jobs_completed' => $profile->jobs_completed,
                            'identity_verified' => $profile->identity_verified,
                            'gallery_images' => $profile->galleryImages,
                        ],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'job' => $job,
            'total_applications' => $applications->count(),
            'applications' => $applications,
        ]);
    }

    /**
     * Accept one worker application.
     */
    #[OA\Patch(
        path: '/api/homeowner/applications/{application}/accept',
        tags: ['Homeowner Applications'],
        summary: 'Accept a worker\'s application',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Application accepted, other applications auto-declined'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
            new OA\Response(response: 422, description: 'Application is not pending or job no longer open'),
        ]
    )]
    public function accept(
        Request $request,
        JobApplication $application
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
                'message' => 'Only homeowner accounts can accept applications.',
            ], 403);
        }

        $application->load('job');

        $job = $application->job;

        if ($job === null || $job->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot accept this application.',
            ], 403);
        }

        if (
            $job->status !== 'open'
            || $job->accepted_worker_id !== null
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This job already has an accepted worker.',
            ], 422);
        }

        if ($application->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending applications can be accepted.',
            ], 422);
        }

        try {
            $result = DB::transaction(function () use (
                $application,
                $job
            ) {
                $workerProfile = WorkerProfile::query()
                    ->where('user_id', $application->worker_id)
                    ->lockForUpdate()
                    ->first();

                if ($workerProfile === null) {
                    return [
                        'success' => false,
                        'message' => 'The worker profile could not be found.',
                    ];
                }

                if ($workerProfile->availability !== 'available') {
                    return [
                        'success' => false,
                        'message' => 'This worker is currently unavailable.',
                    ];
                }

                $application->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);

                JobApplication::query()
                    ->where('job_id', $job->id)
                    ->where('id', '!=', $application->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'declined',
                    ]);

                $job->update([
                    'accepted_worker_id' => $application->worker_id,
                    'status' => 'accepted',
                ]);


                return [
                    'success' => true,
                    'application' => $application->fresh([
                        'worker',
                        'job',
                    ]),
                    'job' => $job->fresh(),
                    'worker_profile' => $workerProfile->fresh(),
                ];
            });

            if ($result['success'] !== true) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'],
                ], 422);
            }

            AppNotificationService::send(
                $result['application']->worker_id,
                'application_accepted',
                'applications',
                'Application accepted',
                'Your application for ' . ($result['job']->title ?? 'the job') . ' was accepted.',
                'worker_active_job',
                $result['job']->id
            );

            return response()->json([
                'success' => true,
                'message' => 'Worker accepted successfully.',
                'application' => $result['application'],
                'job' => $result['job'],
                'worker_profile' => $result['worker_profile'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to accept this application.',
            ], 500);
        }
    }

    /**
     * Decline one pending worker application.
     */
    #[OA\Patch(
        path: '/api/homeowner/applications/{application}/decline',
        tags: ['Homeowner Applications'],
        summary: 'Decline a worker\'s application',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'application', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Application declined'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the owner of this job'),
            new OA\Response(response: 422, description: 'Application is not pending'),
        ]
    )]
    public function decline(
        Request $request,
        JobApplication $application
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
                'message' => 'Only homeowner accounts can decline applications.',
            ], 403);
        }

        $application->load('job');

        $job = $application->job;

        if ($job === null || $job->homeowner_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot decline this application.',
            ], 403);
        }

        if ($application->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending applications can be declined.',
            ], 422);
        }

        $application->update([
            'status' => 'declined',
        ]);

        AppNotificationService::send(
            $application->worker_id,
            'application_declined',
            'applications',
            'Application declined',
            'Your application for ' . ($job->title ?? 'the job') . ' was declined.',
            'worker_applications',
            $job->id,
            ['application_id' => $application->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Application declined successfully.',
            'application' => $application->fresh([
                'worker',
                'job',
            ]),
        ]);
    }
}