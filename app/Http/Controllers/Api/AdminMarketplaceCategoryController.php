<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Admin Marketplace Categories', description: 'Administrator management of top-level category groups (e.g. "Domestic & Household", "Home Repairs & Maintenance")')]
class AdminMarketplaceCategoryController extends Controller
{
    #[OA\Get(
        path: '/api/admin/marketplace-categories',
        tags: ['Admin Marketplace Categories'],
        summary: 'List all marketplace category groups',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category groups',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'categories', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function index(): JsonResponse
    {
        $categories = ServiceCategory::query()
            ->whereNull('parent_id')
            ->withCount('children')
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    #[OA\Post(
        path: '/api/admin/marketplace-categories',
        tags: ['Admin Marketplace Categories'],
        summary: 'Create a marketplace category group (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Home Repairs & Maintenance'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'image', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'display_order', type: 'integer', nullable: true, example: 0),
                        new OA\Property(property: 'active', type: 'boolean', nullable: true, example: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Category group created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ]);

        $imagePath = null;

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('categories', 'public');
        }

        try {
            $category = ServiceCategory::query()->create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'image' => $imagePath,
                'description' => $validated['description'] ?? null,
                'display_order' => $validated['display_order'] ?? 0,
                'active' => $validated['active'] ?? true,
                'parent_id' => null,
                'transaction_type' => 'employment',
            ]);
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create the category group.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category group created.',
            'category' => $category,
        ], 201);
    }

    #[OA\Get(
        path: '/api/admin/marketplace-categories/{category}',
        tags: ['Admin Marketplace Categories'],
        summary: 'View a marketplace category group and its services',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category group with its leaf services'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Not a marketplace category group'),
        ]
    )]
    public function show(ServiceCategory $category): JsonResponse
    {
        if ($category->parent_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This is a service category, not a marketplace category group.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'category' => $category->load('children'),
        ]);
    }

    #[OA\Post(
        path: '/api/admin/marketplace-categories/{category}',
        tags: ['Admin Marketplace Categories'],
        summary: 'Update a marketplace category group (multipart/form-data)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'image', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'display_order', type: 'integer', nullable: true),
                        new OA\Property(property: 'active', type: 'boolean', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Not a marketplace category group'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(Request $request, ServiceCategory $category): JsonResponse
    {
        if ($category->parent_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This is a service category, not a marketplace category group.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ]);

        $imagePath = $category->image;
        $oldImage = null;

        if ($request->hasFile('image')) {
            $newImagePath = $request->file('image')->store('categories', 'public');
            $oldImage = $category->image;
            $imagePath = $newImagePath;
        }

        $category->update([
            'name' => $validated['name'],
            'slug' => $validated['name'] === $category->name
                ? $category->slug
                : $this->uniqueSlug($validated['name'], $category->id),
            'image' => $imagePath,
            'description' => $validated['description'] ?? null,
            'display_order' => $validated['display_order'] ?? $category->display_order,
            'active' => $request->has('active') ? $validated['active'] : $category->active,
        ]);

        if ($oldImage !== null) {
            Storage::disk('public')->delete($oldImage);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category group updated.',
            'category' => $category->fresh(),
        ]);
    }

    #[OA\Delete(
        path: '/api/admin/marketplace-categories/{category}',
        tags: ['Admin Marketplace Categories'],
        summary: 'Delete a marketplace category group (only if empty)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Not a marketplace category group'),
            new OA\Response(response: 422, description: 'Still has service categories under it'),
        ]
    )]
    public function destroy(ServiceCategory $category): JsonResponse
    {
        if ($category->parent_id !== null) {
            return response()->json([
                'success' => false,
                'message' => 'This is a service category, not a marketplace category group.',
            ], 404);
        }

        if ($category->isInUse()) {
            return response()->json([
                'success' => false,
                'message' => 'This group still has service categories under it. Move or delete those first.',
            ], 422);
        }

        $image = $category->image;
        $category->delete();

        if ($image !== null) {
            Storage::disk('public')->delete($image);
        }

        return response()->json([
            'success' => true,
            'message' => 'Category group deleted.',
        ]);
    }

    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (
            ServiceCategory::query()
                ->where('slug', $slug)
                ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . (++$suffix);
        }

        return $slug;
    }
}
