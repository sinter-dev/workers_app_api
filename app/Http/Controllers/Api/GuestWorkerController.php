<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Guest Marketplace', description: 'Public, unauthenticated worker browsing (used for pre-login discovery)')]
class GuestWorkerController extends Controller
{
    #[OA\Get(
        path: '/api/guest/service-categories',
        tags: ['Guest Marketplace'],
        summary: 'List active service categories (public)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service categories',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'service_categories', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
        ]
    )]
    public function categories(): JsonResponse
    {
        $categories = ServiceCategory::query()
            ->where('active', true)
            ->withCount([
                'workerProfiles as workers_count' => function (Builder $query): void {
                    $query
                        ->where('profile_completed', true)
                        ->where('active', true)
                        ->where('verification_status', 'approved')
                        ->where('identity_verified', true)
                        ->whereHas('user', function (Builder $user): void {
                            $user
                                ->where('is_verified', true)
                                ->where('account_status', 'active');
                        });
                },
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon']);

        return response()->json([
            'success' => true,
            'service_categories' => $categories,
        ]);
    }

    #[OA\Get(
        path: '/api/guest/workers',
        tags: ['Guest Marketplace'],
        summary: 'Browse verified workers (public, paginated)',
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', description: 'Service category slug', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated worker list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'workers', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pagination', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = WorkerProfile::query()
            ->with([
                'user:id,full_name,profile_photo,location,is_verified,account_status',
                'services:id,name,slug,icon',
            ])
            ->where('profile_completed', true)
            ->where('active', true)
            ->where('verification_status', 'approved')
            ->where('identity_verified', true)
            ->whereHas('user', function (Builder $user): void {
                $user
                    ->where('is_verified', true)
                    ->where('account_status', 'active');
            });

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('district', 'like', "%{$search}%")
                    ->orWhereHas('user', fn (Builder $u) =>
                        $u->where('full_name', 'like', "%{$search}%"))
                    ->orWhereHas('services', fn (Builder $s) =>
                        $s->where('service_categories.name', 'like', "%{$search}%"));
            });
        }

        if (!empty($validated['service'])) {
            $slug = trim($validated['service']);
            $query->whereHas(
                'services',
                fn (Builder $s) =>
                    $s->where('service_categories.slug', $slug)
            );
        }

        $workers = $query
            ->orderByDesc('rating')
            ->orderByDesc('updated_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        $items = $workers->getCollection()->map(function (WorkerProfile $profile): array {
            return [
                'id' => $profile->user_id,
                'full_name' => $profile->user?->full_name,
                'profile_photo' => $profile->user?->profile_photo,
                'district' => $profile->district,
                'availability' => $profile->availability,
                'rating' => $profile->rating,
                'total_reviews' => $profile->total_reviews,
                'experience_years' => $profile->experience_years,
                'is_verified' => true,
                'services' => $profile->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'icon' => $service->icon,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'workers' => $items,
            'pagination' => [
                'current_page' => $workers->currentPage(),
                'last_page' => $workers->lastPage(),
                'total' => $workers->total(),
                'has_more_pages' => $workers->hasMorePages(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/guest/workers/{worker}/profile',
        tags: ['Guest Marketplace'],
        summary: 'Preview a worker\'s public profile (public, partial data)',
        parameters: [
            new OA\Parameter(name: 'worker', in: 'path', required: true, description: 'Worker user ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Worker preview profile with locked sections listed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'worker', type: 'object'),
                        new OA\Property(property: 'locked', type: 'array', items: new OA\Items(type: 'string'), example: ['full_bio', 'gallery', 'reviews', 'work_history', 'contact', 'hiring']),
                    ]
                )
            ),
            new OA\Response(response: 404, description: 'Worker profile not available'),
        ]
    )]
    public function show(User $worker): JsonResponse
    {
        if (
            $worker->role !== 'worker'
            || !$worker->is_verified
            || ($worker->account_status ?? 'active') !== 'active'
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This worker profile is not available.',
            ], 404);
        }

        $profile = WorkerProfile::query()
            ->with(['services:id,name,slug,icon'])
            ->where('user_id', $worker->id)
            ->where('profile_completed', true)
            ->where('active', true)
            ->where('verification_status', 'approved')
            ->where('identity_verified', true)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'This worker profile is not available.',
            ], 404);
        }

        $bio = trim((string) ($profile->bio ?? ''));
        $bioPreview = mb_strlen($bio) > 180
            ? mb_substr($bio, 0, 180).'…'
            : $bio;

        return response()->json([
            'success' => true,
            'worker' => [
                'id' => $worker->id,
                'full_name' => $worker->full_name,
                'profile_photo' => $worker->profile_photo,
                'district' => $profile->district,
                'availability' => $profile->availability,
                'rating' => $profile->rating,
                'total_reviews' => $profile->total_reviews,
                'experience_years' => $profile->experience_years,
                'bio_preview' => $bioPreview,
                'is_verified' => true,
                'services' => $profile->services->map(fn ($service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'icon' => $service->icon,
                ])->values(),
            ],
            'locked' => [
                'full_bio',
                'gallery',
                'reviews',
                'work_history',
                'contact',
                'hiring',
            ],
        ]);
    }
}