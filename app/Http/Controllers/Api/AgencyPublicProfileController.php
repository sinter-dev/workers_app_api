<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgencyProfile;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Agency Marketplace', description: 'Homeowner-facing search and browsing of verified agencies')]
class AgencyPublicProfileController extends Controller
{
    #[OA\Get(
        path: '/api/agencies/{agency}/profile',
        tags: ['Agency Marketplace'],
        summary: 'Get an agency\'s full public profile, with a worker breakdown by category',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agency', in: 'path', required: true, description: 'Agency user ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Agency profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'agency', type: 'object'),
                        new OA\Property(property: 'profile', type: 'object'),
                        new OA\Property(property: 'worker_count', type: 'integer'),
                        new OA\Property(
                            property: 'workers_by_category',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'category', type: 'string'),
                                    new OA\Property(property: 'count', type: 'integer'),
                                ]
                            )
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Agency profile not available'),
        ]
    )]
    public function show(Request $request, User $agency): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $profile = AgencyProfile::query()
            ->where('user_id', $agency->id)
            ->where('profile_completed', true)
            ->where('verification_status', 'approved')
            ->first();

        if ($agency->role !== 'agency' || $profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Agency profile not available.',
            ], 404);
        }

        // "Available Workers: 24 — Maids 10, Nannies 5, Drivers 4..."
        // computed live from the agency's actual linked workers'
        // existing categories, rather than duplicating that data
        // anywhere new.
        $workersByCategory = WorkerProfile::query()
            ->join('worker_services', 'worker_profiles.id', '=', 'worker_services.worker_profile_id')
            ->join('service_categories', 'worker_services.service_category_id', '=', 'service_categories.id')
            ->where('worker_profiles.agency_id', $agency->id)
            ->select('service_categories.name as category', DB::raw('count(distinct worker_profiles.id) as count'))
            ->groupBy('service_categories.name')
            ->orderByDesc('count')
            ->get();

        return response()->json([
            'success' => true,
            'agency' => $agency->only(['id', 'full_name', 'phone', 'is_verified']),
            'profile' => $profile,
            'worker_count' => WorkerProfile::where('agency_id', $agency->id)->count(),
            'workers_by_category' => $workersByCategory,
        ]);
    }
}
