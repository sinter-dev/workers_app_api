<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\Payment;
use App\Models\Review;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Worker Dashboard', description: 'Worker dashboard: statistics, earnings, applications, activity')]
class WorkerDashboardController extends Controller
{
    /**
     * Return all information required by the worker dashboard.
     */
    #[OA\Get(
        path: '/api/worker/dashboard',
        tags: ['Worker Dashboard'],
        summary: 'Get worker dashboard data',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dashboard statistics, earnings, and activity',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'profile_completed', type: 'boolean'),
                        new OA\Property(property: 'worker', type: 'object'),
                        new OA\Property(property: 'statistics', type: 'object'),
                        new OA\Property(property: 'earnings', type: 'object'),
                        new OA\Property(property: 'incoming_requests', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pending_applications', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'active_jobs', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'recent_activity', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this dashboard'),
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
                'message' => 'Only worker accounts can access this dashboard.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Worker profile
        |--------------------------------------------------------------------------
        */

        $profile = WorkerProfile::query()
            ->with('galleryImages')
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => true,
                'profile_completed' => false,
                'worker' => null,
                'statistics' => $this->emptyStatistics(),
                'earnings' => $this->emptyEarnings(),
                'pending_applications' => [],
                'active_jobs' => [],
                'recent_activity' => [],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        $pendingApplicationsCount = JobApplication::query()
            ->where('worker_id', $user->id)
            ->where('status', 'pending')
            ->count();

        $acceptedApplicationsCount = JobApplication::query()
            ->where('worker_id', $user->id)
            ->where('status', 'accepted')
            ->count();

        $activeJobsCount = Job::query()
            ->where('accepted_worker_id', $user->id)
            ->whereIn('status', [
                'accepted',
                'in_progress',
            ])
            ->count();

        $completedJobsCount = Job::query()
            ->where('accepted_worker_id', $user->id)
            ->where('status', 'completed')
            ->count();

        $reviewCount = Review::query()
            ->where('worker_id', $user->id)
            ->count();

        $averageRating = Review::query()
            ->where('worker_id', $user->id)
            ->avg('rating');

        /*
        |--------------------------------------------------------------------------
        | Earnings
        |--------------------------------------------------------------------------
        */

        $totalPaid = Payment::query()
            ->where('worker_id', $user->id)
            ->where('status', 'paid')
            ->sum('amount');

        $thisMonthPaid = Payment::query()
            ->where('worker_id', $user->id)
            ->where('status', 'paid')
            ->whereYear('paid_at', now()->year)
            ->whereMonth('paid_at', now()->month)
            ->sum('amount');

        $pendingPayments = Payment::query()
            ->where('worker_id', $user->id)
            ->where('status', 'pending')
            ->sum('amount');

        /*
        |--------------------------------------------------------------------------
        | Pending applications
        |--------------------------------------------------------------------------
        */

        $pendingApplications = JobApplication::query()
            ->with([
                'job.homeowner:id,full_name,profile_photo,location,is_verified',
            ])
            ->where('worker_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->limit(10)
            ->get()
            ->map(function (JobApplication $application): array {
                $job = $application->job;
                $homeowner = $job?->homeowner;

                return [
                    'id' => $application->id,
                    'status' => $application->status,
                    'message' => $application->message,
                    'expected_salary' => $application->expected_salary,
                    'applied_at' => $application->created_at,

                    'job' => $job === null
                        ? null
                        : [
                            'id' => $job->id,
                            'title' => $job->title,
                            'category' => $job->category,
                            'description' => $job->description,
                            'address' => $job->address,
                            'district' => $job->district,
                            'start_date' => $job->start_date,
                            'start_time' => $job->start_time,
                            'duration' => $job->duration,
                            'budget_type' => $job->budget_type,
                            'budget_amount' => $job->budget_amount,
                            'status' => $job->status,
                            'is_urgent' => $job->is_urgent,

                            'homeowner' => $homeowner === null
                                ? null
                                : [
                                    'id' => $homeowner->id,
                                    'full_name' => $homeowner->full_name,
                                    'profile_photo' => $homeowner->profile_photo,
                                    'location' => $homeowner->location,
                                    'is_verified' => $homeowner->is_verified,
                                ],
                        ],
                ];
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Active jobs
        |--------------------------------------------------------------------------
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
            ->limit(10)
            ->get()
            ->map(function (Job $job): array {
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
            })
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Recent activity
        |--------------------------------------------------------------------------
        */

        $recentActivity = $this->buildRecentActivity(
            workerId: $user->id,
        );

        return response()->json([
            'success' => true,
            'profile_completed' => (bool) $profile->profile_completed,

            'worker' => [
                'user' => $user,
                'profile' => $profile,
            ],

            'statistics' => [
                'jobs_completed' => $completedJobsCount,
                'rating' => round((float) ($averageRating ?? 0), 2),
                'total_reviews' => $reviewCount,
                'pending_applications' => $pendingApplicationsCount,
                'accepted_applications' => $acceptedApplicationsCount,
                'active_jobs' => $activeJobsCount,
            ],

            'earnings' => [
                'currency' => 'UGX',
                'this_month' => (float) $thisMonthPaid,
                'pending' => (float) $pendingPayments,
                'total' => (float) $totalPaid,
            ],

            /*
             * The current database has no direct worker invitation table yet.
             * We therefore return an empty array instead of fake requests.
             */
            'incoming_requests' => [],

            'pending_applications' => $pendingApplications,
            'active_jobs' => $activeJobs,
            'recent_activity' => $recentActivity,
        ]);
    }

    /**
     * Build a combined recent-activity list.
     */
    private function buildRecentActivity(int $workerId): array
    {
        $activities = [];

        $payments = Payment::query()
            ->with('homeowner:id,full_name')
            ->where('worker_id', $workerId)
            ->latest()
            ->limit(5)
            ->get();

        foreach ($payments as $payment) {
            $activities[] = [
                'type' => 'payment',
                'title' => $payment->status === 'paid'
                    ? 'Payment received'
                    : 'Payment updated',
                'subtitle' => sprintf(
                    'UGX %s%s',
                    number_format((float) $payment->amount, 0),
                    $payment->homeowner !== null
                        ? ' from '.$payment->homeowner->full_name
                        : ''
                ),
                'status' => $payment->status,
                'occurred_at' => $payment->paid_at
                    ?? $payment->updated_at,
            ];
        }

        $reviews = Review::query()
            ->with('homeowner:id,full_name')
            ->where('worker_id', $workerId)
            ->latest()
            ->limit(5)
            ->get();

        foreach ($reviews as $review) {
            $activities[] = [
                'type' => 'review',
                'title' => $review->rating.'-star review received',
                'subtitle' => $review->homeowner !== null
                    ? $review->homeowner->full_name.' left you a review'
                    : 'You received a new review',
                'rating' => $review->rating,
                'comment' => $review->comment,
                'occurred_at' => $review->created_at,
            ];
        }

        $completedJobs = Job::query()
            ->where('accepted_worker_id', $workerId)
            ->where('status', 'completed')
            ->latest('updated_at')
            ->limit(5)
            ->get();

        foreach ($completedJobs as $job) {
            $activities[] = [
                'type' => 'job_completed',
                'title' => 'Job completed',
                'subtitle' => $job->title,
                'job_id' => $job->id,
                'occurred_at' => $job->updated_at,
            ];
        }

        usort(
            $activities,
            function (array $first, array $second): int {
                return strtotime((string) $second['occurred_at'])
                    <=> strtotime((string) $first['occurred_at']);
            }
        );

        return array_slice($activities, 0, 10);
    }

    /**
     * Empty statistics returned before profile completion.
     */
    private function emptyStatistics(): array
    {
        return [
            'jobs_completed' => 0,
            'rating' => 0,
            'total_reviews' => 0,
            'pending_applications' => 0,
            'accepted_applications' => 0,
            'active_jobs' => 0,
        ];
    }

    /**
     * Empty earnings returned before profile completion.
     */
    private function emptyEarnings(): array
    {
        return [
            'currency' => 'UGX',
            'this_month' => 0,
            'pending' => 0,
            'total' => 0,
        ];
    }
}