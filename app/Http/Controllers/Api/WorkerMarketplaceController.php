<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkerCardResource;
use App\Models\SavedWorker;
use App\Models\WorkerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Worker Marketplace', description: 'Homeowner-facing worker search, filtering, and sorting')]
class WorkerMarketplaceController extends Controller
{
    /**
     * Return searchable and filterable workers.
     */
    #[OA\Get(
        path: '/api/workers',
        tags: ['Worker Marketplace'],
        summary: 'Search and filter workers (homeowner only)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'district', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'min_age', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'max_age', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'gender', in: 'query', schema: new OA\Schema(type: 'string', enum: ['male', 'female', 'other'])),
            new OA\Parameter(name: 'religion', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'work_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['full_time', 'part_time'])),
            new OA\Parameter(name: 'availability', in: 'query', schema: new OA\Schema(type: 'string', enum: ['available', 'busy', 'unavailable'])),
            new OA\Parameter(name: 'featured', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'rating', in: 'query', description: 'Minimum rating', schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'service', in: 'query', description: 'Service category slug or name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['newest', 'rating', 'experience', 'jobs_completed', 'name'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated, filtered worker list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'workers', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pagination', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can browse workers'),
            new OA\Response(response: 500, description: 'Unable to load workers'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        if ($viewer === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($viewer->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can browse workers.',
            ], 403);
        }

        $validated = $request->validate([
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                'nullable',
                'string',
                'max:150',
            ],

            'min_age' => [
                'nullable',
                'integer',
                'min:18',
                'max:70',
            ],

            'max_age' => [
                'nullable',
                'integer',
                'min:18',
                'max:70',
                'gte:min_age',
            ],

            'gender' => [
                'nullable',
                Rule::in([
                    'male',
                    'female',
                    'other',
                ]),
            ],

            'religion' => [
                'nullable',
                'string',
                'max:100',
            ],

            'work_type' => [
                'nullable',
                Rule::in([
                    'full_time',
                    'part_time',
                ]),
            ],

            'availability' => [
                'nullable',
                Rule::in([
                    'available',
                    'busy',
                    'unavailable',
                ]),
            ],

            'featured' => [
                'nullable',
                'boolean',
            ],

            'rating' => [
                'nullable',
                'numeric',
                'min:0',
                'max:5',
            ],

            'service' => [
                'nullable',
                'string',
                'max:150',
            ],

            'sort' => [
                'nullable',
                Rule::in([
                    'newest',
                    'rating',
                    'experience',
                    'jobs_completed',
                    'name',
                ]),
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        try {
            $query = WorkerProfile::query()
                ->with([
                    'user:id,full_name,profile_photo,location,is_verified,created_at',

                    'services' => function ($query): void {
                        $query
                            ->where('active', true)
                            ->select([
                                'service_categories.id',
                                'service_categories.name',
                                'service_categories.slug',
                                'service_categories.icon',
                            ]);
                    },
                ])
                ->where('profile_completed', true)
                ->where('active', true)
                ->where('verification_status', 'approved')
                ->where('identity_verified', true)
                ->whereHas(
                    'user',
                    fn ($q) => $q
                        ->where('is_verified', true)
                        ->where('account_status', 'active')
                );

            $this->applySearch(
                $query,
                $validated['search'] ?? null
            );

            $this->applyFilters(
                $query,
                $validated
            );

            $this->applySorting(
                $query,
                $validated['sort'] ?? 'newest'
            );

            $perPage = (int) (
                $validated['per_page'] ?? 20
            );

            $workers = $query
                ->paginate($perPage)
                ->withQueryString();

            $savedWorkerIds = SavedWorker::query()
                ->where('homeowner_id', $viewer->id)
                ->pluck('worker_id')
                ->map(
                    fn ($id): int => (int) $id
                )
                ->all();

            $workers
                ->getCollection()
                ->transform(
                    function (
                        WorkerProfile $profile
                    ) use (
                        $savedWorkerIds
                    ): WorkerProfile {
                        $profile->setAttribute(
                            'is_saved',
                            in_array(
                                (int) $profile->user_id,
                                $savedWorkerIds,
                                true
                            )
                        );

                        return $profile;
                    }
                );

            $workerCards = WorkerCardResource::collection(
                $workers->getCollection()
            )->resolve($request);

            return response()->json([
                'success' => true,

                'workers' => $workerCards,

                'pagination' => [
                    'current_page' =>
                        $workers->currentPage(),

                    'last_page' =>
                        $workers->lastPage(),

                    'per_page' =>
                        $workers->perPage(),

                    'total' =>
                        $workers->total(),

                    'from' =>
                        $workers->firstItem(),

                    'to' =>
                        $workers->lastItem(),

                    'has_more_pages' =>
                        $workers->hasMorePages(),
                ],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load workers.',
            ], 500);
        }
    }

    /**
     * Apply worker name, district and biography search.
     */
    private function applySearch(
        Builder $query,
        ?string $search
    ): void {
        if ($search === null) {
            return;
        }

        $search = trim($search);

        if ($search === '') {
            return;
        }

        $query->where(function (
            Builder $builder
        ) use (
            $search
        ): void {
            $builder
                ->whereHas(
                    'user',
                    function (
                        Builder $userQuery
                    ) use (
                        $search
                    ): void {
                        $userQuery->where(
                            'full_name',
                            'like',
                            "%{$search}%"
                        );
                    }
                )
                ->orWhere(
                    'district',
                    'like',
                    "%{$search}%"
                )
                ->orWhere(
                    'bio',
                    'like',
                    "%{$search}%"
                );
        });
    }

    /**
     * Apply marketplace filters.
     */
    private function applyFilters(
        Builder $query,
        array $validated
    ): void {
        if (!empty($validated['district'])) {
            $query->where(
                'district',
                'like',
                '%' .
                trim($validated['district']) .
                '%'
            );
        }

        if (array_key_exists('min_age', $validated)) {
            $query->where(
                'age',
                '>=',
                (int) $validated['min_age']
            );
        }

        if (array_key_exists('max_age', $validated)) {
            $query->where(
                'age',
                '<=',
                (int) $validated['max_age']
            );
        }

        if (!empty($validated['gender'])) {
            $query->where(
                'gender',
                $validated['gender']
            );
        }

        if (!empty($validated['religion'])) {
            $query->where(
                'religion',
                'like',
                '%' .
                trim($validated['religion']) .
                '%'
            );
        }

        if (!empty($validated['work_type'])) {
            $query->where(
                'work_type',
                $validated['work_type']
            );
        }

        if (!empty($validated['availability'])) {
            $query->where(
                'availability',
                $validated['availability']
            );
        }

        if (
            array_key_exists(
                'featured',
                $validated
            )
        ) {
            $query->where(
                'featured',
                (bool) $validated['featured']
            );
        }

        if (
            array_key_exists(
                'rating',
                $validated
            )
        ) {
            $query->where(
                'rating',
                '>=',
                $validated['rating']
            );
        }

        if (!empty($validated['service'])) {
            $service = trim(
                $validated['service']
            );

            $query->whereHas(
                'services',
                function (
                    Builder $serviceQuery
                ) use (
                    $service
                ): void {
                    $serviceQuery
                        ->where(
                            'service_categories.slug',
                            $service
                        )
                        ->orWhere(
                            'service_categories.name',
                            'like',
                            "%{$service}%"
                        );
                }
            );
        }
    }

    /**
     * Apply marketplace sorting.
     */
    private function applySorting(
        Builder $query,
        string $sort
    ): void {
        match ($sort) {
            'rating' => $query
                ->orderByDesc('rating')
                ->orderByDesc('total_reviews')
                ->orderByDesc('jobs_completed'),

            'experience' => $query
                ->orderByDesc('experience_years')
                ->orderByDesc('rating'),

            'jobs_completed' => $query
                ->orderByDesc('jobs_completed')
                ->orderByDesc('rating'),

            'name' => $query
                ->join(
                    'users',
                    'users.id',
                    '=',
                    'worker_profiles.user_id'
                )
                ->orderBy('users.full_name')
                ->select('worker_profiles.*'),

            default => $query
                ->orderByDesc('featured')
                ->latest(
                    'worker_profiles.created_at'
                ),
        };
    }
}