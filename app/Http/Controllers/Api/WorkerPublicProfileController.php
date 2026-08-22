<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedWorker;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Worker Public Profile', description: 'Full worker profile view for authenticated viewers')]
class WorkerPublicProfileController extends Controller
{
    /**
     * Return one worker's public profile.
     */
    #[OA\Get(
        path: '/api/workers/{worker}/profile',
        tags: ['Worker Public Profile'],
        summary: 'Get a worker\'s full public profile',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'worker', in: 'path', required: true, description: 'Worker user ID', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Worker profile, reviews, and viewer context',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'worker', type: 'object'),
                        new OA\Property(property: 'profile', type: 'object'),
                        new OA\Property(property: 'reviews', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'viewer', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Worker profile not available'),
        ]
    )]
    public function show(
        Request $request,
        User $worker
    ): JsonResponse {
        $viewer = $request->user();

        if ($viewer === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($worker->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'The selected account is not a worker.',
            ], 404);
        }

        $profile = WorkerProfile::query()
            ->with([
                'galleryImages',
                'services' => function ($query) {
                    $query
                        ->where('active', true)
                        ->select([
                            'service_categories.id',
                            'service_categories.name',
                            'service_categories.slug',
                            'service_categories.icon',
                            'service_categories.description',
                        ]);
                },
            ])
            ->where('user_id', $worker->id)
            ->first();

        if (
            $profile === null
            || !$profile->active
            || !$profile->profile_completed
            || ($viewer->role === 'homeowner' && (
                $profile->verification_status !== 'approved'
                || !$profile->identity_verified
                || !$worker->is_verified
            ))
        ) {
            return response()->json([
                'success' => false,
                'message' => 'This worker profile is not available.',
            ], 404);
        }

        $isSaved = false;

        if ($viewer->role === 'homeowner') {
            $isSaved = SavedWorker::query()
                ->where('homeowner_id', $viewer->id)
                ->where('worker_id', $worker->id)
                ->exists();
        }

        $reviews = $worker
            ->reviews()
            ->with([
                'homeowner:id,full_name,profile_photo',
                'job:id,title',
            ])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,

            'worker' => [
                'id' => $worker->id,
                'full_name' => $worker->full_name,
                'profile_photo' => $worker->profile_photo,
                'location' => $worker->location,
                'is_verified' => $worker->is_verified,
                'created_at' => $worker->created_at,
            ],

            'profile' => [
                'id' => $profile->id,
                'age' => $profile->age,
                'religion' => $profile->religion,
                'gender' => $profile->gender,
                'district' => $profile->district,
                'work_type' => $profile->work_type,
                'languages' => $profile->languages ?? [],
                'bio' => $profile->bio,
                'experience_years' =>
                    $profile->experience_years,
                'hourly_rate' => $profile->hourly_rate,
                'monthly_rate' => $profile->monthly_rate,
                'availability' =>
                    $profile->availability,
                'rating' => $profile->rating,
                'total_reviews' =>
                    $profile->total_reviews,
                'jobs_completed' =>
                    $profile->jobs_completed,
                'background_checked' =>
                    $profile->background_checked,
                'police_clearance' =>
                    $profile->police_clearance,
                'medical_clearance' =>
                    $profile->medical_clearance,
                'identity_verified' =>
                    $profile->identity_verified,
                'featured' => $profile->featured,
                'gallery_images' =>
                    $profile->galleryImages,
                'services' =>
                    $profile->services,
            ],

            'reviews' => $reviews,

            'viewer' => [
                'role' => $viewer->role,
                'is_saved' => $isSaved,
                'is_own_profile' =>
                    $viewer->id === $worker->id,
            ],
        ]);
    }
}