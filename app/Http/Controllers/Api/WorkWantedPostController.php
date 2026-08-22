<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\WorkWantedPost;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Work Wanted Posts', description: '"Looking for Work" posts: workers advertise availability, homeowners browse them')]
#[OA\Schema(
    schema: 'WorkWantedPostRequest',
    required: ['title', 'district', 'work_type', 'living_preference', 'available_immediately', 'willing_to_relocate', 'service_ids'],
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 120),
        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 1500),
        new OA\Property(property: 'district', type: 'string'),
        new OA\Property(property: 'work_type', type: 'string', enum: ['full_time', 'part_time', 'either']),
        new OA\Property(property: 'living_preference', type: 'string', enum: ['live_in', 'live_out', 'either']),
        new OA\Property(property: 'expected_salary_min', type: 'number', nullable: true),
        new OA\Property(property: 'expected_salary_max', type: 'number', nullable: true),
        new OA\Property(property: 'available_from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'available_immediately', type: 'boolean'),
        new OA\Property(property: 'willing_to_relocate', type: 'boolean'),
        new OA\Property(property: 'service_ids', type: 'array', items: new OA\Items(type: 'integer'), minItems: 1, maxItems: 20),
    ]
)]
class WorkWantedPostController extends Controller
{
    #[OA\Get(
        path: '/api/worker/work-wanted',
        tags: ['Work Wanted Posts'],
        summary: 'Get the authenticated worker\'s own post (if any)',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Post, or null if none exists',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'post', type: 'object', nullable: true),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only worker accounts can use this feature'),
        ]
    )]
    public function mine(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'worker') return $this->roleError('worker');

        $post = WorkWantedPost::with('services:id,name,slug,icon')
            ->where('worker_id', $request->user()->id)->latest()->first();

        return response()->json(['success' => true, 'post' => $post]);
    }

    #[OA\Post(
        path: '/api/worker/work-wanted',
        tags: ['Work Wanted Posts'],
        summary: 'Create a "Looking for Work" post (approved workers only)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/WorkWantedPostRequest')
        ),
        responses: [
            new OA\Response(response: 201, description: 'Post created and active'),
            new OA\Response(response: 403, description: 'Only approved worker accounts can use this feature'),
            new OA\Response(response: 422, description: 'Validation error or an active post already exists'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'worker') return $this->roleError('worker');
        if (!$this->isApprovedWorker($request->user()->id)) {
            return response()->json(['success' => false, 'message' => 'Your worker profile must be approved before you can post Looking for Work.'], 403);
        }
        $data = $this->validated($request);

        $existing = WorkWantedPost::where('worker_id', $request->user()->id)
            ->whereIn('status', ['active', 'paused'])->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a Looking for Work post. Edit or reactivate that post instead.',
            ], 422);
        }

        $post = DB::transaction(function () use ($request, $data) {
            $serviceIds = $data['service_ids']; unset($data['service_ids']);
            $data['worker_id'] = $request->user()->id;
            $data['status'] = 'active';
            $post = WorkWantedPost::create($data);
            $post->services()->sync($serviceIds);
            return $post;
        });

        return response()->json([
            'success' => true,
            'message' => 'Your Looking for Work post is now active.',
            'post' => $post->load('services:id,name,slug,icon'),
        ], 201);
    }

    #[OA\Put(
        path: '/api/worker/work-wanted/{post}',
        tags: ['Work Wanted Posts'],
        summary: 'Update the worker\'s own "Looking for Work" post',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/WorkWantedPostRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Post updated'),
            new OA\Response(response: 403, description: 'Not your post'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, WorkWantedPost $post): JsonResponse
    {
        if ($request->user()->role !== 'worker' || $post->worker_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You cannot edit this post.'], 403);
        }
        $data = $this->validated($request);
        DB::transaction(function () use ($post, $data): void {
            $serviceIds = $data['service_ids']; unset($data['service_ids']);
            $post->update($data);
            $post->services()->sync($serviceIds);
        });
        return response()->json([
            'success' => true, 'message' => 'Looking for Work post updated.',
            'post' => $post->fresh()->load('services:id,name,slug,icon'),
        ]);
    }

    #[OA\Patch(
        path: '/api/worker/work-wanted/{post}/status',
        tags: ['Work Wanted Posts'],
        summary: 'Change the status of the worker\'s post',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'post', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'paused', 'hired', 'closed']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Status updated'),
            new OA\Response(response: 403, description: 'Not your post'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function status(Request $request, WorkWantedPost $post): JsonResponse
    {
        if ($request->user()->role !== 'worker' || $post->worker_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You cannot change this post.'], 403);
        }
        $data = $request->validate(['status' => 'required|in:active,paused,hired,closed']);
        $post->update(['status' => $data['status']]);
        return response()->json(['success' => true, 'message' => 'Post status updated.', 'post' => $post->fresh()->load('services:id,name,slug,icon')]);
    }

    #[OA\Get(
        path: '/api/homeowner/work-wanted',
        tags: ['Work Wanted Posts'],
        summary: 'Browse active "Looking for Work" posts (homeowner only)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'district', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'work_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated posts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'posts', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pagination', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only homeowner accounts can use this feature'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'homeowner') return $this->roleError('homeowner');

        $query = WorkWantedPost::query()
            ->with([
                'services:id,name,slug,icon',
                'worker:id,full_name,profile_photo,location,is_verified',
                'worker.workerProfile:user_id,experience_years,rating,total_reviews,availability,district',
            ])
            ->where('status', 'active')
            ->whereHas('worker.workerProfile', function ($q): void {
                $q->where('profile_completed', true)
                    ->where('active', true)
                    ->where('verification_status', 'approved')
                    ->where('identity_verified', true);
            })
            ->whereHas('worker', fn ($q) => $q->where('is_verified', true));

        if ($request->filled('district')) $query->where('district', $request->string('district'));
        if ($request->filled('service_id')) {
            $serviceId = (int) $request->input('service_id');
            $query->whereHas('services', fn ($q) => $q->where('service_categories.id', $serviceId));
        }
        if ($request->filled('work_type')) $query->where('work_type', $request->string('work_type'));

        $posts = $query->latest()->paginate(min(max((int) $request->input('per_page', 20), 1), 50));
        return response()->json([
            'success' => true,
            'posts' => $posts->items(),
            'pagination' => [
                'current_page' => $posts->currentPage(), 'last_page' => $posts->lastPage(),
                'total' => $posts->total(), 'has_more_pages' => $posts->hasMorePages(),
            ],
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'description' => 'nullable|string|max:1500',
            'district' => 'required|string|max:100',
            'work_type' => 'required|in:full_time,part_time,either',
            'living_preference' => 'required|in:live_in,live_out,either',
            'expected_salary_min' => 'nullable|numeric|min:0',
            'expected_salary_max' => 'nullable|numeric|min:0|gte:expected_salary_min',
            'available_from' => 'nullable|date',
            'available_immediately' => 'required|boolean',
            'willing_to_relocate' => 'required|boolean',
            'service_ids' => 'required|array|min:1|max:20',
            'service_ids.*' => 'required|integer|distinct|exists:service_categories,id',
        ]);

        $activeCount = ServiceCategory::whereIn('id', $data['service_ids'])->where('active', true)->count();
        if ($activeCount !== count(array_unique($data['service_ids']))) {
            abort(response()->json(['success' => false, 'message' => 'Please select only active service categories.'], 422));
        }
        if ($data['available_immediately']) $data['available_from'] = null;
        return $data;
    }

    private function isApprovedWorker(int $userId): bool
    {
        return WorkerProfile::query()
            ->where('user_id', $userId)
            ->where('profile_completed', true)
            ->where('active', true)
            ->where('verification_status', 'approved')
            ->where('identity_verified', true)
            ->whereHas('user', fn ($q) => $q->where('is_verified', true))
            ->exists();
    }

    private function roleError(string $role): JsonResponse
    {
        return response()->json(['success' => false, 'message' => "Only {$role}s can use this feature."], 403);
    }
}