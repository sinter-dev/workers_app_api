<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Company Marketplace', description: 'Homeowner-facing search and browsing of verified companies')]
class CompanyMarketplaceController extends Controller
{
    #[OA\Get(
        path: '/api/companies',
        tags: ['Company Marketplace'],
        summary: 'Search and browse verified companies (homeowner only)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'district', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'service', in: 'query', description: 'Service category slug or name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['newest', 'name'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated, filtered company list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'companies', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pagination', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can browse companies'),
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
                'message' => 'Only homeowner accounts can browse companies.',
            ], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', Rule::in(['newest', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = CompanyProfile::query()
            ->with([
                'user:id,full_name,phone,is_verified',
                'services:id,name,slug,icon',
            ])
            ->where('profile_completed', true)
            ->where('verification_status', 'approved');

        if (!empty($validated['search'])) {
            $query->where('company_name', 'like', '%' . $validated['search'] . '%');
        }

        if (!empty($validated['district'])) {
            $query->where('district', $validated['district']);
        }

        if (!empty($validated['service'])) {
            $query->whereHas('services', function ($q) use ($validated) {
                $q->where('slug', $validated['service'])
                    ->orWhere('name', 'like', '%' . $validated['service'] . '%');
            });
        }

        match ($validated['sort'] ?? 'newest') {
            'name' => $query->orderBy('company_name'),
            default => $query->latest(),
        };

        $companies = $query->paginate(
            $validated['per_page'] ?? 20,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return response()->json([
            'success' => true,
            'companies' => $companies->items(),
            'pagination' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
                'from' => $companies->firstItem(),
                'to' => $companies->lastItem(),
            ],
        ]);
    }
}
