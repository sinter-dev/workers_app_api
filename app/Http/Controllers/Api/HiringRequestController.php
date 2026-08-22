<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HiringRequest;
use App\Models\Job;
use App\Models\JobApplication;
use App\Models\User;
use App\Models\WorkerProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Hiring Requests', description: 'Direct hiring flow: homeowners send hiring offers to specific workers')]
class HiringRequestController extends Controller
{
    /**
     * Homeowner: list hiring requests they have sent.
     */
    #[OA\Get(
        path: '/api/hiring/homeowner',
        tags: ['Hiring Requests'],
        summary: 'List hiring requests sent by the authenticated homeowner',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hiring requests',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'hiring_requests', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only homeowners can access this'),
        ]
    )]
    public function homeownerIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowners can access sent hiring requests.',
            ], 403);
        }

        $requests = HiringRequest::query()
            ->where('homeowner_id', $user->id)
            ->with([
                'job:id,title,district,budget_amount,budget_type,status',
                'worker:id,full_name,phone,profile_photo',
            ])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'hiring_requests' => $requests->items(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'has_more_pages' => $requests->hasMorePages(),
            ],
        ]);
    }

    /**
     * Worker: list hiring requests received.
     */
    #[OA\Get(
        path: '/api/hiring/worker',
        tags: ['Hiring Requests'],
        summary: 'List hiring requests received by the authenticated worker',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hiring requests',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'hiring_requests', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only workers can access this'),
        ]
    )]
    public function workerIndex(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only workers can access received hiring requests.',
            ], 403);
        }

        $requests = HiringRequest::query()
            ->where('worker_id', $user->id)
            ->with([
                'job:id,title,description,district,budget_amount,budget_type,status',
                'homeowner:id,full_name,phone,profile_photo',
            ])
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'hiring_requests' => $requests->items(),
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'has_more_pages' => $requests->hasMorePages(),
            ],
        ]);
    }

    /**
     * Homeowner: return open jobs that can be used to hire a worker.
     */
    #[OA\Get(
        path: '/api/hiring/available-jobs',
        tags: ['Hiring Requests'],
        summary: 'List the homeowner\'s open jobs available for direct hiring',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Open jobs',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'jobs', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only homeowners can access this'),
        ]
    )]
    public function availableJobs(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowners can access this list.',
            ], 403);
        }

        $jobs = Job::query()
            ->where('homeowner_id', $user->id)
            ->where('status', 'open')
            ->latest()
            ->get([
                'id',
                'title',
                'district',
                'budget_amount',
                'budget_type',
                'work_arrangement',
                'created_at',
            ]);

        return response()->json([
            'success' => true,
            'jobs' => $jobs,
        ]);
    }

    /**
     * Homeowner: send a hiring request to a worker.
     */
    #[OA\Post(
        path: '/api/hiring/requests',
        tags: ['Hiring Requests'],
        summary: 'Send a hiring request for an existing open job',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['job_id', 'worker_id'],
                properties: [
                    new OA\Property(property: 'job_id', type: 'integer'),
                    new OA\Property(property: 'worker_id', type: 'integer'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 1000),
                    new OA\Property(property: 'offered_amount', type: 'number', nullable: true),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Hiring request sent'),
            new OA\Response(response: 403, description: 'Not the job owner, or worker not approved'),
            new OA\Response(response: 422, description: 'Job not open, invalid worker, or request already exists'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $homeowner = $request->user();

        if ($homeowner->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowners can send hiring requests.',
            ], 403);
        }

        $validated = $request->validate([
            'job_id' => [
                'required',
                'integer',
                'exists:jobs,id',
            ],
            'worker_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'message' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'offered_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'start_date' => [
                'nullable',
                'date',
                'after_or_equal:today',
            ],
        ]);

        $job = Job::query()
            ->where('id', $validated['job_id'])
            ->where('homeowner_id', $homeowner->id)
            ->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'message' => 'The selected job does not belong to you.',
            ], 403);
        }

        if ($job->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Only open jobs can be used for hiring.',
            ], 422);
        }

        $worker = User::query()
            ->where('id', $validated['worker_id'])
            ->where('role', 'worker')
            ->first();

        if (!$worker) {
            return response()->json([
                'success' => false,
                'message' => 'The selected account is not a worker.',
            ], 422);
        }

        $approvedProfile = WorkerProfile::query()
            ->where('user_id', $worker->id)
            ->where('profile_completed', true)
            ->where('active', true)
            ->where('verification_status', 'approved')
            ->where('identity_verified', true)
            ->first();

        if ($approvedProfile === null || !$worker->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved workers can receive hiring requests.',
            ], 403);
        }

        $alreadyExists = HiringRequest::query()
            ->where('job_id', $job->id)
            ->where('worker_id', $worker->id)
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'success' => false,
                'message' => 'A hiring request has already been sent to this worker for this job.',
            ], 422);
        }

        $hiringRequest = HiringRequest::create([
            'job_id' => $job->id,
            'homeowner_id' => $homeowner->id,
            'worker_id' => $worker->id,
            'status' => 'pending',
            'message' => $validated['message'] ?? null,
            'offered_amount' =>
                $validated['offered_amount'] ??
                $job->budget_amount,
            'start_date' => $validated['start_date'] ?? null,
        ]);

        $hiringRequest->load([
            'job',
            'homeowner:id,full_name,phone,profile_photo',
            'worker:id,full_name,phone,profile_photo',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Hiring request sent successfully.',
            'hiring_request' => $hiringRequest,
        ], 201);
    }

    /**
     * Homeowner: send a quick hiring request without completing a job form.
     *
     * A minimal private job is created so the request can continue through
     * the existing accept -> active job -> complete workflow. The homeowner
     * and worker can agree on salary, schedule and other details in chat.
     */
    #[OA\Post(
        path: '/api/hiring/quick-requests',
        tags: ['Hiring Requests'],
        summary: 'Send a quick hiring request (auto-creates a minimal private job)',
        security: [['sanctum' => []]],
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
            new OA\Response(response: 201, description: 'Quick hiring request sent'),
            new OA\Response(response: 403, description: 'Only homeowners, or worker not approved'),
            new OA\Response(response: 422, description: 'Invalid worker or request already exists'),
        ]
    )]
    public function storeQuick(Request $request): JsonResponse
    {
        $homeowner = $request->user();

        if ($homeowner->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowners can send quick hiring requests.',
            ], 403);
        }

        $validated = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $worker = User::query()
            ->whereKey($validated['worker_id'])
            ->where('role', 'worker')
            ->first();

        if ($worker === null) {
            return response()->json([
                'success' => false,
                'message' => 'The selected account is not a worker.',
            ], 422);
        }

        $approvedProfile = WorkerProfile::query()
            ->where('user_id', $worker->id)
            ->where('profile_completed', true)
            ->where('active', true)
            ->where('verification_status', 'approved')
            ->where('identity_verified', true)
            ->first();

        if ($approvedProfile === null || !$worker->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved workers can receive hiring requests.',
            ], 403);
        }

        $alreadyPending = HiringRequest::query()
            ->where('homeowner_id', $homeowner->id)
            ->where('worker_id', $worker->id)
            ->where('status', 'pending')
            ->whereHas('job', function ($query): void {
                $query->where('visibility', 'private')
                    ->where('category', 'Quick Hire');
            })
            ->exists();

        if ($alreadyPending) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending quick hiring request for this worker.',
            ], 422);
        }

        $location = trim((string) ($homeowner->location ?? ''));
        if ($location === '') {
            $location = 'To be agreed';
        }

        $result = DB::transaction(function () use ($homeowner, $worker, $location): array {
            $job = Job::query()->create([
                'homeowner_id' => $homeowner->id,
                'accepted_worker_id' => null,
                'title' => 'Quick Hire - ' . $worker->full_name,
                'category' => 'Quick Hire',
                'description' => 'The homeowner would like to hire this worker directly. Salary, schedule, services and other job details can be agreed in chat.',
                'address' => $location,
                'district' => $location,
                'start_date' => now()->toDateString(),
                'duration' => 'To be agreed',
                'work_arrangement' => null,
                'budget_type' => 'fixed',
                'budget_amount' => 0,
                'status' => 'open',
                'visibility' => 'private',
                'is_urgent' => false,
            ]);

            $hiringRequest = HiringRequest::query()->create([
                'job_id' => $job->id,
                'homeowner_id' => $homeowner->id,
                'worker_id' => $worker->id,
                'status' => 'pending',
                'message' => 'I would like to hire you. We can agree on the job details in chat.',
                'offered_amount' => null,
                'start_date' => null,
            ]);

            return [
                'job' => $job,
                'request' => $hiringRequest,
            ];
        });

        $hiringRequest = $result['request'];
        $hiringRequest->load([
            'job',
            'homeowner:id,full_name,phone,profile_photo',
            'worker:id,full_name,phone,profile_photo',
        ]);

        AppNotificationService::send($worker->id, 'hiring_offer', 'offers', 'New Hire Now request', $homeowner->full_name . ' wants to hire you.', 'hiring_request', $hiringRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Quick hiring request sent successfully.',
            'hiring_request' => $hiringRequest,
        ], 201);
    }

    /**
     * Homeowner: create a private job and send it directly to one worker.
     */
    #[OA\Post(
        path: '/api/hiring/direct-offers',
        tags: ['Hiring Requests'],
        summary: 'Create a private job and send a direct offer to one worker',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['worker_id', 'title', 'description', 'address', 'district', 'start_date', 'duration', 'budget_type', 'offered_amount', 'service_ids'],
                properties: [
                    new OA\Property(property: 'worker_id', type: 'integer'),
                    new OA\Property(property: 'title', type: 'string', maxLength: 150),
                    new OA\Property(property: 'description', type: 'string', maxLength: 2000),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'district', type: 'string'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'duration', type: 'string'),
                    new OA\Property(property: 'work_arrangement', type: 'string', nullable: true),
                    new OA\Property(property: 'budget_type', type: 'string', enum: ['fixed', 'daily', 'monthly']),
                    new OA\Property(property: 'offered_amount', type: 'number'),
                    new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 1000),
                    new OA\Property(property: 'service_ids', type: 'array', items: new OA\Items(type: 'integer'), minItems: 1, maxItems: 20),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Direct offer sent'),
            new OA\Response(response: 403, description: 'Only homeowners, or worker not approved'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeDirect(Request $request): JsonResponse
    {
        $homeowner = $request->user();

        if ($homeowner->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowners can send direct job offers.',
            ], 403);
        }

        $validated = $request->validate([
            'worker_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'address' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:100'],
            'start_date' => ['required', 'date', 'after_or_equal:today'],
            'duration' => ['required', 'string', 'max:100'],
            'work_arrangement' => ['nullable', 'string', 'max:50'],
            'budget_type' => ['required', Rule::in(['fixed', 'daily', 'monthly'])],
            'offered_amount' => ['required', 'numeric', 'min:0'],
            'message' => ['nullable', 'string', 'max:1000'],
            'service_ids' => ['required', 'array', 'min:1', 'max:20'],
            'service_ids.*' => ['required', 'integer', 'distinct', 'exists:service_categories,id'],
        ]);

        $worker = User::query()
            ->whereKey($validated['worker_id'])
            ->where('role', 'worker')
            ->first();

        if ($worker === null) {
            return response()->json([
                'success' => false,
                'message' => 'The selected account is not a worker.',
            ], 422);
        }

        $approvedProfile = WorkerProfile::query()
            ->where('user_id', $worker->id)
            ->where('profile_completed', true)
            ->where('active', true)
            ->where('verification_status', 'approved')
            ->where('identity_verified', true)
            ->first();

        if ($approvedProfile === null || !$worker->is_verified) {
            return response()->json([
                'success' => false,
                'message' => 'Only approved workers can receive hiring requests.',
            ], 403);
        }

        $categoryNames = \App\Models\ServiceCategory::query()
            ->whereIn('id', $validated['service_ids'])
            ->where('active', true)
            ->pluck('name');

        if ($categoryNames->count() !== count(array_unique($validated['service_ids']))) {
            return response()->json([
                'success' => false,
                'message' => 'Please select only active service categories.',
            ], 422);
        }

        $result = DB::transaction(function () use ($homeowner, $worker, $validated, $categoryNames): array {
            $job = Job::query()->create([
                'homeowner_id' => $homeowner->id,
                'accepted_worker_id' => null,
                'title' => $validated['title'],
                'category' => $categoryNames->first() ?? 'Domestic Work',
                'description' => $validated['description'],
                'address' => $validated['address'],
                'district' => $validated['district'],
                'start_date' => $validated['start_date'],
                'duration' => $validated['duration'],
                'work_arrangement' => $validated['work_arrangement'] ?? null,
                'budget_type' => $validated['budget_type'],
                'budget_amount' => $validated['offered_amount'],
                'status' => 'open',
                'visibility' => 'private',
                'is_urgent' => false,
            ]);

            $job->serviceCategories()->sync($validated['service_ids']);

            $hiringRequest = HiringRequest::query()->create([
                'job_id' => $job->id,
                'homeowner_id' => $homeowner->id,
                'worker_id' => $worker->id,
                'status' => 'pending',
                'message' => $validated['message'] ?? null,
                'offered_amount' => $validated['offered_amount'],
                'start_date' => $validated['start_date'],
            ]);

            return ['job' => $job, 'request' => $hiringRequest];
        });

        $hiringRequest = $result['request'];
        $hiringRequest->load([
            'job.serviceCategories:id,name,slug,icon',
            'homeowner:id,full_name,phone,profile_photo',
            'worker:id,full_name,phone,profile_photo',
        ]);

        AppNotificationService::send($worker->id, 'hiring_offer', 'offers', 'New custom job offer', $homeowner->full_name . ' wants to hire you.', 'hiring_request', $hiringRequest->id);

        return response()->json([
            'success' => true,
            'message' => 'Direct job offer sent successfully.',
            'hiring_request' => $hiringRequest,
        ], 201);
    }

    /**
     * Show one hiring request.
     */
    #[OA\Get(
        path: '/api/hiring/requests/{hiringRequest}',
        tags: ['Hiring Requests'],
        summary: 'View a single hiring request',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hiringRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Hiring request details'),
            new OA\Response(response: 403, description: 'Not a party to this request'),
        ]
    )]
    public function show(
        Request $request,
        HiringRequest $hiringRequest
    ): JsonResponse {
        $user = $request->user();

        $isAllowed =
            $hiringRequest->homeowner_id === $user->id ||
            $hiringRequest->worker_id === $user->id;

        if (!$isAllowed) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot access this hiring request.',
            ], 403);
        }

        $hiringRequest->load([
            'job',
            'homeowner:id,full_name,phone,profile_photo',
            'worker:id,full_name,phone,profile_photo',
        ]);

        return response()->json([
            'success' => true,
            'hiring_request' => $hiringRequest,
        ]);
    }

    /**
     * Worker: accept a pending hiring request.
     */
    #[OA\Post(
        path: '/api/hiring/requests/{hiringRequest}/accept',
        tags: ['Hiring Requests'],
        summary: 'Accept a pending hiring request (worker)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hiringRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Hiring request accepted, job assigned'),
            new OA\Response(response: 403, description: 'Not the recipient of this request'),
            new OA\Response(response: 422, description: 'Request is not pending'),
        ]
    )]
    public function accept(
        Request $request,
        HiringRequest $hiringRequest
    ): JsonResponse {
        $worker = $request->user();

        if (
            $worker->role !== 'worker' ||
            $hiringRequest->worker_id !== $worker->id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot accept this hiring request.',
            ], 403);
        }

        $result = DB::transaction(function () use (
            $hiringRequest,
            $worker
        ): array {
            $lockedRequest = HiringRequest::query()
                ->whereKey($hiringRequest->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRequest === null || $lockedRequest->status !== 'pending') {
                return [
                    'success' => false,
                    'message' => 'Only pending hiring requests can be accepted.',
                ];
            }

            $job = Job::query()
                ->whereKey($lockedRequest->job_id)
                ->lockForUpdate()
                ->first();

            if (
                $job === null ||
                $job->status !== 'open' ||
                $job->accepted_worker_id !== null
            ) {
                return [
                    'success' => false,
                    'message' => 'This job is no longer available.',
                ];
            }

            $workerProfile = WorkerProfile::query()
                ->where('user_id', $worker->id)
                ->lockForUpdate()
                ->first();

            if ($workerProfile === null || !$workerProfile->profile_completed) {
                return [
                    'success' => false,
                    'message' => 'Complete your worker profile before accepting jobs.',
                ];
            }

            $lockedRequest->update([
                'status' => 'accepted',
                'accepted_at' => now(),
                'declined_at' => null,
                'cancelled_at' => null,
            ]);

            $job->update([
                'accepted_worker_id' => $worker->id,
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);


            // If this worker also applied normally, keep both systems aligned.
            JobApplication::query()
                ->where('job_id', $job->id)
                ->where('worker_id', $worker->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'accepted',
                    'responded_at' => now(),
                ]);

            // The job is now filled. Decline competing pending applications.
            JobApplication::query()
                ->where('job_id', $job->id)
                ->where('worker_id', '!=', $worker->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'declined',
                    'responded_at' => now(),
                ]);

            HiringRequest::query()
                ->where('job_id', $job->id)
                ->where('id', '!=', $lockedRequest->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'declined',
                    'declined_at' => now(),
                ]);

            return [
                'success' => true,
                'hiring_request' => $lockedRequest->fresh(),
            ];
        });

        if ($result['success'] !== true) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], 422);
        }

        $acceptedRequest = $result['hiring_request'];
        $acceptedRequest->load([
            'job',
            'homeowner:id,full_name,phone,profile_photo',
            'worker:id,full_name,phone,profile_photo',
        ]);

        AppNotificationService::send(
            $acceptedRequest->homeowner_id,
            'hiring_offer_accepted',
            'offers',
            'Job offer accepted',
            $acceptedRequest->worker->full_name . ' accepted your offer for ' . ($acceptedRequest->job->title ?? 'the job') . '.',
            'homeowner_active_job',
            $acceptedRequest->job_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Hiring request accepted successfully.',
            'hiring_request' => $acceptedRequest,
        ]);
    }

    /**
     * Worker: decline a pending hiring request.
     */
    #[OA\Post(
        path: '/api/hiring/requests/{hiringRequest}/decline',
        tags: ['Hiring Requests'],
        summary: 'Decline a pending hiring request (worker)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hiringRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Hiring request declined'),
            new OA\Response(response: 403, description: 'Not the recipient of this request'),
            new OA\Response(response: 422, description: 'Request is not pending'),
        ]
    )]
    public function decline(
        Request $request,
        HiringRequest $hiringRequest
    ): JsonResponse {
        $worker = $request->user();

        if (
            $worker->role !== 'worker' ||
            $hiringRequest->worker_id !== $worker->id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot decline this hiring request.',
            ], 403);
        }

        if (!$hiringRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending hiring requests can be declined.',
            ], 422);
        }

        $hiringRequest->update([
            'status' => 'declined',
            'declined_at' => now(),
        ]);

        $hiringRequest->loadMissing(['job:id,title', 'worker:id,full_name']);

        AppNotificationService::send(
            $hiringRequest->homeowner_id,
            'hiring_offer_declined',
            'offers',
            'Job offer declined',
            $worker->full_name . ' declined your offer for ' . ($hiringRequest->job->title ?? 'the job') . '.',
            'homeowner_hiring_request',
            $hiringRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Hiring request declined.',
            'hiring_request' => $hiringRequest->fresh(),
        ]);
    }

    /**
     * Homeowner: cancel a pending hiring request.
     */
    #[OA\Post(
        path: '/api/hiring/requests/{hiringRequest}/cancel',
        tags: ['Hiring Requests'],
        summary: 'Cancel a pending hiring request (homeowner)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hiringRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Hiring request cancelled'),
            new OA\Response(response: 403, description: 'Not the sender of this request'),
            new OA\Response(response: 422, description: 'Request is not pending'),
        ]
    )]
    public function cancel(
        Request $request,
        HiringRequest $hiringRequest
    ): JsonResponse {
        $homeowner = $request->user();

        if (
            $homeowner->role !== 'homeowner' ||
            $hiringRequest->homeowner_id !== $homeowner->id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot cancel this hiring request.',
            ], 403);
        }

        if (!$hiringRequest->isPending()) {
            return response()->json([
                'success' => false,
                'message' => 'Only pending hiring requests can be cancelled.',
            ], 422);
        }

        $hiringRequest->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $hiringRequest->loadMissing(['job:id,title']);

        AppNotificationService::send(
            $hiringRequest->worker_id,
            'hiring_offer_cancelled',
            'offers',
            'Job offer cancelled',
            $homeowner->full_name . ' cancelled the offer for ' . ($hiringRequest->job->title ?? 'the job') . '.',
            'worker_hiring_request',
            $hiringRequest->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Hiring request cancelled.',
            'hiring_request' => $hiringRequest->fresh(),
        ]);
    }

    /**
     * Homeowner: mark an accepted/in-progress hire as completed.
     */
    #[OA\Post(
        path: '/api/hiring/requests/{hiringRequest}/complete',
        tags: ['Hiring Requests'],
        summary: 'Mark a hire as completed (homeowner)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'hiringRequest', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marked completed'),
            new OA\Response(response: 403, description: 'Not the sender of this request'),
            new OA\Response(response: 422, description: 'Request is not in an accepted/active state'),
        ]
    )]
    public function complete(
        Request $request,
        HiringRequest $hiringRequest
    ): JsonResponse {
        $homeowner = $request->user();

        if (
            $homeowner->role !== 'homeowner' ||
            $hiringRequest->homeowner_id !== $homeowner->id
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot complete this hiring request.',
            ], 403);
        }

        if (
            !in_array(
                $hiringRequest->status,
                ['accepted', 'in_progress'],
                true
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This hiring request cannot be completed.',
            ], 422);
        }

        DB::transaction(function () use ($hiringRequest): void {
            $lockedRequest = HiringRequest::query()
                ->whereKey($hiringRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            $job = Job::query()
                ->whereKey($lockedRequest->job_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRequest->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $job->update([
                'accepted_worker_id' => $lockedRequest->worker_id,
                'status' => 'completed',
            ]);

            $workerProfile = WorkerProfile::query()
                ->where('user_id', $lockedRequest->worker_id)
                ->lockForUpdate()
                ->first();

            if ($workerProfile !== null) {
                $workerProfile->update([
                    'availability' => 'available',
                    'jobs_completed' => $workerProfile->jobs_completed + 1,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Job marked as completed.',
            'hiring_request' => $hiringRequest->fresh(),
        ]);
    }
}