<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Marketplace Categories', description: 'Public browsing of the category tree (groups and their services) for home-screen navigation')]
class MarketplaceCategoryController extends Controller
{
    #[OA\Get(
        path: '/api/marketplace/categories',
        tags: ['Marketplace Categories'],
        summary: 'Get the full category tree (public, no login required)',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category groups, each with their active services',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'categories',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'slug', type: 'string'),
                                    new OA\Property(property: 'icon', type: 'string', nullable: true),
                                    new OA\Property(property: 'image', type: 'string', nullable: true),
                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                    new OA\Property(
                                        property: 'services',
                                        type: 'array',
                                        items: new OA\Items(
                                            properties: [
                                                new OA\Property(property: 'id', type: 'integer'),
                                                new OA\Property(property: 'name', type: 'string'),
                                                new OA\Property(property: 'slug', type: 'string'),
                                                new OA\Property(property: 'icon', type: 'string', nullable: true),
                                                new OA\Property(property: 'image', type: 'string', nullable: true),
                                                new OA\Property(property: 'transaction_type', type: 'string', enum: ['employment', 'on_demand_service']),
                                            ]
                                        )
                                    ),
                                ]
                            )
                        ),
                    ]
                )
            ),
        ]
    )]
    public function index(): JsonResponse
    {
        $groups = ServiceCategory::query()
            ->whereNull('parent_id')
            ->where('active', true)
            ->with(['children' => function ($query) {
                $query->where('active', true)
                    ->orderBy('display_order')
                    ->orderBy('name');
            }])
            ->orderBy('display_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceCategory $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'slug' => $group->slug,
                'icon' => $group->icon,
                'image' => $group->image,
                'description' => $group->description,
                'services' => $group->children->map(fn (ServiceCategory $service) => [
                    'id' => $service->id,
                    'name' => $service->name,
                    'slug' => $service->slug,
                    'icon' => $service->icon,
                    'image' => $service->image,
                    'transaction_type' => $service->transaction_type,
                ])->values(),
            ]);

        return response()->json([
            'success' => true,
            'categories' => $groups,
        ]);
    }
}
