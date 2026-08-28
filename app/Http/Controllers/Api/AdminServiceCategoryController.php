<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Admin Service Categories', description: 'Administrator management of the actual selectable services (e.g. "Plumbing", "Housemaid") that users choose from')]
class AdminServiceCategoryController extends Controller
{
    #[OA\Get(
        path: '/api/admin/service-categories',
        tags: ['Admin Service Categories'],
        summary: 'List all service categories',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'parent_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'transaction_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['employment', 'on_demand_service'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service categories',
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
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'transaction_type' => ['nullable', Rule::in(['employment', 'on_demand_service'])],
        ]);

        $query = ServiceCategory::query()
            ->with('parent:id,name')
            ->whereNotNull('parent_id');

        if (!empty($validated['parent_id'])) {
            $query->where('parent_id', $validated['parent_id']);
        }

        if (!empty($validated['transaction_type'])) {
            $query->where('transaction_type', $validated['transaction_type']);
        }

        $categories = $query
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    #[OA\Post(
        path: '/api/admin/service-categories',
        tags: ['Admin Service Categories'],
        summary: 'Create a service category (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'parent_id', 'transaction_type'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'Plumbing'),
                        new OA\Property(property: 'parent_id', type: 'integer', description: 'Must be an existing marketplace category group'),
                        new OA\Property(property: 'transaction_type', type: 'string', enum: ['employment', 'on_demand_service']),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'image', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'display_order', type: 'integer', nullable: true, example: 0),
                        new OA\Property(property: 'active', type: 'boolean', nullable: true, example: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Service category created'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 422, description: 'Validation error, or parent_id is not a marketplace category group'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['required', 'integer', 'exists:service_categories,id'],
            'transaction_type' => ['required', Rule::in(['employment', 'on_demand_service'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ]);

        $parent = ServiceCategory::query()
            ->where('id', $validated['parent_id'])
            ->whereNull('parent_id')
            ->first();

        if ($parent === null) {
            return response()->json([
                'success' => false,
                'message' => 'parent_id must reference an existing marketplace category group.',
            ], 422);
        }

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
                'parent_id' => $parent->id,
                'transaction_type' => $validated['transaction_type'],
            ]);
        } catch (Throwable $exception) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create the service category.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service category created.',
            'category' => $category,
        ], 201);
    }

    #[OA\Get(
        path: '/api/admin/service-categories/{category}',
        tags: ['Admin Service Categories'],
        summary: 'View a single service category',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Service category'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Not a service category'),
        ]
    )]
    public function show(ServiceCategory $category): JsonResponse
    {
        if ($category->parent_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'This is a marketplace category group, not a service category.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'category' => $category->load('parent:id,name'),
        ]);
    }

    #[OA\Post(
        path: '/api/admin/service-categories/{category}',
        tags: ['Admin Service Categories'],
        summary: 'Update a service category (multipart/form-data)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['name', 'parent_id', 'transaction_type'],
                    properties: [
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'parent_id', type: 'integer'),
                        new OA\Property(property: 'transaction_type', type: 'string', enum: ['employment', 'on_demand_service']),
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
            new OA\Response(response: 404, description: 'Not a service category'),
            new OA\Response(response: 422, description: 'Validation error, or parent_id is not a marketplace category group'),
        ]
    )]
    public function update(Request $request, ServiceCategory $category): JsonResponse
    {
        if ($category->parent_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'This is a marketplace category group, not a service category.',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['required', 'integer', 'exists:service_categories,id'],
            'transaction_type' => ['required', Rule::in(['employment', 'on_demand_service'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'active' => ['nullable', 'boolean'],
        ]);

        $parent = ServiceCategory::query()
            ->where('id', $validated['parent_id'])
            ->whereNull('parent_id')
            ->first();

        if ($parent === null) {
            return response()->json([
                'success' => false,
                'message' => 'parent_id must reference an existing marketplace category group.',
            ], 422);
        }

        $imagePath = $category->image;
        $oldImage = null;

        if ($request->hasFile('image')) {
            $oldImage = $category->image;
            $imagePath = $request->file('image')->store('categories', 'public');
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
            'parent_id' => $parent->id,
            'transaction_type' => $validated['transaction_type'],
        ]);

        if ($oldImage !== null) {
            Storage::disk('public')->delete($oldImage);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service category updated.',
            'category' => $category->fresh(),
        ]);
    }

    #[OA\Delete(
        path: '/api/admin/service-categories/{category}',
        tags: ['Admin Service Categories'],
        summary: 'Delete a service category (only if not currently used anywhere)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 404, description: 'Not a service category'),
            new OA\Response(response: 422, description: 'This category is currently in use and cannot be deleted'),
        ]
    )]
    public function destroy(ServiceCategory $category): JsonResponse
    {
        if ($category->parent_id === null) {
            return response()->json([
                'success' => false,
                'message' => 'This is a marketplace category group, not a service category.',
            ], 404);
        }

        if ($category->isInUse()) {
            return response()->json([
                'success' => false,
                'message' => 'This category is currently in use (by workers, companies, jobs, or requests) and cannot be deleted. Deactivate it instead.',
            ], 422);
        }

        $image = $category->image;
        $category->delete();

        if ($image !== null) {
            Storage::disk('public')->delete($image);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service category deleted.',
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
