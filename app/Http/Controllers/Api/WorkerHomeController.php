<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Worker Home', description: 'Worker home screen feed (recommended, urgent, nearby jobs)')]
class WorkerHomeController extends Controller
{
    /**
     * Return the authenticated worker's home screen data.
     */
    #[OA\Get(
        path: '/api/worker/home',
        tags: ['Worker Home'],
        summary: 'Get worker home screen data',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Home screen job feed and summary counts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'profile_completed', type: 'boolean'),
                        new OA\Property(property: 'worker', type: 'object'),
                        new OA\Property(property: 'summary', type: 'object'),
                        new OA\Property(property: 'active_jobs', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'recommended_jobs', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'urgent_jobs', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'nearby_jobs', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'recent_jobs', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this page'),
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

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can access this page.',
            ], 403);
        }

        $profile = WorkerProfile::query()
            ->with('galleryImages')
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null || !$profile->profile_completed) {
            return response()->json([
                'success' => true,
                'profile_completed' => false,

                'worker' => [
                    'user' => $user,
                    'profile' => $profile,
                ],

                'summary' => [
                    'pending_applications' => 0,
                    'active_jobs' => 0,
                    'available_jobs' => 0,
                ],

                'active_jobs' => [],
                'recommended_jobs' => [],
                'urgent_jobs' => [],
                'nearby_jobs' => [],
                'recent_jobs' => [],
            ]);
        }

        // Hide only applications that are still active.
        // Declined/withdrawn applications should not permanently remove an
        // otherwise-open job from the worker marketplace.
        $appliedJobIds = JobApplication::query()
            ->where('worker_id', $user->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->pluck('job_id');

        $pendingApplicationsCount = JobApplication::query()
            ->where('worker_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $activeJobsCount = Job::query()
            ->where('accepted_worker_id', $user->id)
            ->whereIn('status', [
                'accepted',
                'in_progress',
            ])
            ->count();

        $availableJobsCount = Job::query()
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereNull('accepted_worker_id')
            ->whereNotIn('id', $appliedJobIds)
            ->count();

        /*
        |--------------------------------------------------------------------------
        | Active jobs
        |--------------------------------------------------------------------------
        |
        | Jobs already assigned to this worker.
        |
        */

        $activeJobs = Job::query()
            ->with([
                'homeowner:id,full_name,profile_photo,location,is_verified',
            ])
            ->where('accepted_worker_id', $user->id)
            ->whereIn('status', [
                'accepted',
                'in_progress',
            ])
            ->orderBy('start_date')
            ->orderBy('start_time')
            ->get()
            ->map(
                fn (Job $job): array => $this->formatJob($job)
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Recommended jobs
        |--------------------------------------------------------------------------
        */

        $recommendedJobs = Job::query()
            ->with([
                'homeowner:id,full_name,profile_photo,location,is_verified',
            ])
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereNull('accepted_worker_id')
            ->whereNotIn('id', $appliedJobIds)
            ->orderByRaw(
                'CASE WHEN district = ? THEN 0 ELSE 1 END',
                [$profile->district]
            )
            ->orderByDesc('is_urgent')
            ->latest()
            ->limit(10)
            ->get()
            ->map(
                fn (Job $job): array => $this->formatJob($job)
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Urgent jobs
        |--------------------------------------------------------------------------
        */

        $urgentJobs = Job::query()
            ->with([
                'homeowner:id,full_name,profile_photo,location,is_verified',
            ])
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereNull('accepted_worker_id')
            ->where('is_urgent', true)
            ->whereNotIn('id', $appliedJobIds)
            ->latest()
            ->limit(10)
            ->get()
            ->map(
                fn (Job $job): array => $this->formatJob($job)
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Nearby jobs
        |--------------------------------------------------------------------------
        |
        | Nearby currently means the same district.
        |
        */

        $nearbyJobs = Job::query()
            ->with([
                'homeowner:id,full_name,profile_photo,location,is_verified',
            ])
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereNull('accepted_worker_id')
            ->where('district', $profile->district)
            ->whereNotIn('id', $appliedJobIds)
            ->latest()
            ->limit(10)
            ->get()
            ->map(
                fn (Job $job): array => $this->formatJob($job)
            )
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Recent jobs
        |--------------------------------------------------------------------------
        */

        $recentJobs = Job::query()
            ->with([
                'homeowner:id,full_name,profile_photo,location,is_verified',
            ])
            ->where('status', 'open')
            ->where('visibility', 'public')
            ->whereNull('accepted_worker_id')
            ->whereNotIn('id', $appliedJobIds)
            ->latest()
            ->limit(10)
            ->get()
            ->map(
                fn (Job $job): array => $this->formatJob($job)
            )
            ->values();

        return response()->json([
            'success' => true,
            'profile_completed' => true,

            'worker' => [
                'user' => $user,
                'profile' => $profile,
            ],

            'summary' => [
                'pending_applications' => $pendingApplicationsCount,
                'active_jobs' => $activeJobsCount,
                'available_jobs' => $availableJobsCount,
            ],

            'active_jobs' => $activeJobs,
            'recommended_jobs' => $recommendedJobs,
            'urgent_jobs' => $urgentJobs,
            'nearby_jobs' => $nearbyJobs,
            'recent_jobs' => $recentJobs,
        ]);
    }

    /**
     * Format a job for the Flutter application.
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

            'posted_at' => $job->created_at,

            'homeowner' => $homeowner === null
                ? null
                : [
                    'id' => $homeowner->id,
                    'full_name' => $homeowner->full_name,
                    'profile_photo' => $homeowner->profile_photo,
                    'location' => $homeowner->location,
                    'is_verified' => $homeowner->is_verified,
                ],
        ];
    }
}