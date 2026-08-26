<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgencyProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Agency Marketplace', description: 'Homeowner-facing search and browsing of verified agencies')]
class AgencyMarketplaceController extends Controller
{
    #[OA\Get(
        path: '/api/agencies',
        tags: ['Agency Marketplace'],
        summary: 'Search and browse verified agencies (homeowner only)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'district', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'specialty', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', schema: new OA\Schema(type: 'string', enum: ['newest', 'name'])),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated, filtered agency list',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'agencies', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'pagination', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can browse agencies'),
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
                'message' => 'Only homeowner accounts can browse agencies.',
            ], 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:150'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', Rule::in(['newest', 'name'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = AgencyProfile::query()
            ->with('user:id,full_name,phone,is_verified')
            ->withCount('workers')
            ->where('profile_completed', true)
            ->where('verification_status', 'approved');

        if (!empty($validated['search'])) {
            $query->where('agency_name', 'like', '%' . $validated['search'] . '%');
        }

        if (!empty($validated['district'])) {
            $query->where('district', $validated['district']);
        }

        if (!empty($validated['specialty'])) {
            $query->where('specialty', 'like', '%' . $validated['specialty'] . '%');
        }

        match ($validated['sort'] ?? 'newest') {
            'name' => $query->orderBy('agency_name'),
            default => $query->latest(),
        };

        $agencies = $query->paginate(
            $validated['per_page'] ?? 20,
            ['*'],
            'page',
            $validated['page'] ?? 1
        );

        return response()->json([
            'success' => true,
            'agencies' => $agencies->items(),
            'pagination' => [
                'current_page' => $agencies->currentPage(),
                'last_page' => $agencies->lastPage(),
                'per_page' => $agencies->perPage(),
                'total' => $agencies->total(),
                'from' => $agencies->firstItem(),
                'to' => $agencies->lastItem(),
            ],
        ]);
    }
}
