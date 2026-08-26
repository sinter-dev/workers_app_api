<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Company Marketplace', description: 'Homeowner-facing search and browsing of verified companies')]
class CompanyPublicProfileController extends Controller
{
    #[OA\Get(
        path: '/api/companies/{company}/profile',
        tags: ['Company Marketplace'],
        summary: 'Get a company\'s full public profile',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'company', in: 'path', required: true, description: 'Company user ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Company profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'company', type: 'object'),
                        new OA\Property(property: 'profile', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Company profile not available'),
        ]
    )]
    public function show(Request $request, User $company): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $profile = CompanyProfile::query()
            ->with('services:id,name,slug,icon,description')
            ->where('user_id', $company->id)
            ->where('profile_completed', true)
            ->where('verification_status', 'approved')
            ->first();

        if ($company->role !== 'company' || $profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Company profile not available.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'company' => $company->only(['id', 'full_name', 'phone', 'is_verified']),
            'profile' => $profile,
        ]);
    }
}
