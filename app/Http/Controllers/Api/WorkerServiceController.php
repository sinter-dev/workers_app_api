<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Models\WorkerProfile;
use App\Models\WorkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Worker Services', description: 'Managing which service categories an authenticated worker offers')]
class WorkerServiceController extends Controller
{
    /**
     * Return all active service categories.
     *
     * This endpoint may be used by:
     * - Worker profile completion
     * - Worker profile editing
     * - Homeowner marketplace filters
     */
    #[OA\Get(
        path: '/api/service-categories',
        tags: ['Worker Services'],
        summary: 'List all active service categories',
        security: [['sanctum' => []]],
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
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function categories(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $categories = ServiceCategory::query()
            ->where('active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
                'icon',
                'description',
            ]);

        return response()->json([
            'success' => true,
            'service_categories' => $categories,
        ]);
    }

    /**
     * Return services currently selected by the authenticated worker.
     */
    #[OA\Get(
        path: '/api/worker/services',
        tags: ['Worker Services'],
        summary: 'Get the authenticated worker\'s selected services',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Selected services',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'services', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'selected_service_ids', type: 'array', items: new OA\Items(type: 'integer')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can manage services'),
            new OA\Response(response: 404, description: 'Worker profile not found'),
        ]
    )]
    public function index(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        $roleError = $this->validateWorkerAccount(
            $user
        );

        if ($roleError !== null) {
            return $roleError;
        }

        $profile = WorkerProfile::query()
            ->with([
                'services' => function ($query): void {
                    $query
                        ->where('active', true)
                        ->orderBy('name');
                },
            ])
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Worker profile could not be found.',
            ], 404);
        }

        return response()->json([
            'success' => true,

            'services' => $profile->services
                ->map(function (
                    ServiceCategory $service
                ): array {
                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'slug' => $service->slug,
                        'icon' => $service->icon,
                        'description' =>
                            $service->description,
                    ];
                })
                ->values(),

            'selected_service_ids' => $profile
                ->services
                ->pluck('id')
                ->map(
                    fn ($id): int => (int) $id
                )
                ->values(),
        ]);
    }

    /**
     * Update services offered by the authenticated worker.
     */
    #[OA\Put(
        path: '/api/worker/services',
        tags: ['Worker Services'],
        summary: 'Replace the authenticated worker\'s service selection',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['service_ids'],
                properties: [
                    new OA\Property(property: 'service_ids', type: 'array', items: new OA\Items(type: 'integer'), minItems: 1, maxItems: 20, example: [1, 3, 5]),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Services updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'services', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'selected_service_ids', type: 'array', items: new OA\Items(type: 'integer')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can manage services'),
            new OA\Response(response: 404, description: 'Worker profile not found'),
            new OA\Response(response: 422, description: 'Validation error or inactive service selected'),
            new OA\Response(response: 500, description: 'Update failed'),
        ]
    )]
    public function update(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        $roleError = $this->validateWorkerAccount(
            $user
        );

        if ($roleError !== null) {
            return $roleError;
        }

        $profile = WorkerProfile::query()
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Worker profile could not be found.',
            ], 404);
        }

        $validated = $request->validate([
            'service_ids' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],

            'service_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:service_categories,id',
            ],
        ]);

        $serviceIds = collect(
            $validated['service_ids']
        )
            ->map(
                fn ($id): int => (int) $id
            )
            ->unique()
            ->values();

        /*
        |--------------------------------------------------------------------------
        | Ensure every selected category is active
        |--------------------------------------------------------------------------
        */

        $activeServiceIds = ServiceCategory::query()
            ->whereIn('id', $serviceIds)
            ->where('active', true)
            ->pluck('id')
            ->map(
                fn ($id): int => (int) $id
            )
            ->values();

        $inactiveServiceIds = $serviceIds
            ->diff($activeServiceIds)
            ->values();

        if ($inactiveServiceIds->isNotEmpty()) {
            return response()->json([
                'success' => false,

                'message' =>
                    'One or more selected services are unavailable.',

                'errors' => [
                    'service_ids' => [
                        'Please select only active service categories.',
                    ],
                ],
            ], 422);
        }

        try {
            DB::transaction(function () use (
                $profile,
                $activeServiceIds
            ): void {
                /*
                |--------------------------------------------------------------------------
                | Replace old selections with the new list
                |--------------------------------------------------------------------------
                */

                WorkerService::query()
                    ->where(
                        'worker_profile_id',
                        $profile->id
                    )
                    ->delete();

                $now = now();

                $rows = $activeServiceIds
                    ->map(function (
                        int $serviceId
                    ) use (
                        $profile,
                        $now
                    ): array {
                        return [
                            'worker_profile_id' =>
                                $profile->id,

                            'service_category_id' =>
                                $serviceId,

                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })
                    ->all();

                WorkerService::query()->insert(
                    $rows
                );
            });

            $profile->load([
                'services' => function ($query): void {
                    $query
                        ->where('active', true)
                        ->orderBy('name');
                },
            ]);

            return response()->json([
                'success' => true,

                'message' =>
                    'Worker services updated successfully.',

                'services' => $profile->services
                    ->map(function (
                        ServiceCategory $service
                    ): array {
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'slug' => $service->slug,
                            'icon' => $service->icon,
                            'description' =>
                                $service->description,
                        ];
                    })
                    ->values(),

                'selected_service_ids' => $profile
                    ->services
                    ->pluck('id')
                    ->map(
                        fn ($id): int => (int) $id
                    )
                    ->values(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to update worker services.',
            ], 500);
        }
    }

    /**
     * Ensure the authenticated user is a worker.
     */
    private function validateWorkerAccount(
        mixed $user
    ): ?JsonResponse {
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,

                'message' =>
                    'Only worker accounts can manage services.',
            ], 403);
        }

        return null;
    }
}