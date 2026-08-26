<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgencyProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Agency Profile', description: 'Creating and viewing the authenticated agency\'s own profile')]
class AgencyProfileController extends Controller
{
    /**
     * Return the authenticated agency's profile.
     */
    #[OA\Get(
        path: '/api/agency/profile',
        tags: ['Agency Profile'],
        summary: 'Get the authenticated agency\'s profile',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Agency profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'profile_completed', type: 'boolean'),
                        new OA\Property(property: 'profile', type: 'object', nullable: true),
                        new OA\Property(property: 'worker_count', type: 'integer'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only agency accounts can access this profile'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can access this profile.',
            ], 403);
        }

        $profile = AgencyProfile::query()
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'success' => true,
            'profile_completed' => (bool) ($profile?->profile_completed ?? false),
            'profile' => $profile,
            'worker_count' => $user->managedWorkers()->count(),
            'user' => $user,
        ]);
    }

    /**
     * Create or update the authenticated agency's profile.
     */
    #[OA\Post(
        path: '/api/agency/profile',
        tags: ['Agency Profile'],
        summary: 'Create or update the agency profile (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['agency_name', 'district'],
                    properties: [
                        new OA\Property(property: 'agency_name', type: 'string', example: 'Bright Home Recruitment Agency'),
                        new OA\Property(property: 'specialty', type: 'string', nullable: true, example: 'Domestic Worker Agency'),
                        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 2000),
                        new OA\Property(property: 'business_registration_number', type: 'string', nullable: true),
                        new OA\Property(property: 'address', type: 'string', nullable: true),
                        new OA\Property(property: 'district', type: 'string', example: 'Kampala'),
                        new OA\Property(property: 'latitude', type: 'number', nullable: true),
                        new OA\Property(property: 'longitude', type: 'number', nullable: true),
                        new OA\Property(property: 'logo', type: 'string', format: 'binary', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profile saved',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'profile_completed', type: 'boolean'),
                        new OA\Property(property: 'profile', type: 'object'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only agency accounts can complete this profile'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Unable to save the agency profile'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can complete this profile.',
            ], 403);
        }

        $validated = $request->validate([
            'agency_name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],

            'specialty' => [
                'nullable',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'business_registration_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'address' => [
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                'required',
                'string',
                'max:150',
            ],

            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'logo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $newFiles = [];
        $oldFilesToDelete = [];

        try {
            $profile = DB::transaction(function () use (
                $validated,
                $request,
                $user,
                &$newFiles,
                &$oldFilesToDelete
            ) {
                $profile = AgencyProfile::query()
                    ->firstOrNew([
                        'user_id' => $user->id,
                    ]);

                $isNewProfile = !$profile->exists;

                $logoPath = $profile->logo;

                if ($request->hasFile('logo')) {
                    $newLogoPath = $request
                        ->file('logo')
                        ->store(
                            'agency/logos',
                            'public'
                        );

                    $newFiles[] = $newLogoPath;

                    if (
                        $logoPath !== null
                        && $logoPath !== $newLogoPath
                    ) {
                        $oldFilesToDelete[] = $logoPath;
                    }

                    $logoPath = $newLogoPath;
                }

                $profile->fill([
                    'agency_name' => $validated['agency_name'],
                    'specialty' => $validated['specialty'] ?? null,
                    'logo' => $logoPath,
                    'description' => $validated['description'] ?? null,
                    'business_registration_number' => $validated['business_registration_number'] ?? null,
                    'address' => $validated['address'] ?? null,
                    'district' => $validated['district'],
                    'latitude' => $validated['latitude'] ?? null,
                    'longitude' => $validated['longitude'] ?? null,
                    'profile_completed' => true,
                ]);

                if ($isNewProfile) {
                    $profile->fill([
                        'verification_status' => 'pending',
                        'verification_submitted_at' => now(),
                    ]);
                }

                $profile->save();

                $user->update([
                    'full_name' => $validated['agency_name'],
                    'location' => $validated['district'],
                ]);

                return $profile->fresh();
            });

            foreach (array_unique($oldFilesToDelete) as $oldFile) {
                Storage::disk('public')->delete($oldFile);
            }

            return response()->json([
                'success' => true,
                'message' => 'Agency profile saved successfully.',
                'profile_completed' => true,
                'profile' => $profile,
                'user' => $user->fresh(),
            ]);
        } catch (Throwable $exception) {
            foreach (array_unique($newFiles) as $newFile) {
                Storage::disk('public')->delete($newFile);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to save the agency profile.',
            ], 500);
        }
    }

    /**
     * Resubmit a rejected agency profile for administrator verification.
     */
    #[OA\Post(
        path: '/api/agency/profile/resubmit-verification',
        tags: ['Agency Profile'],
        summary: 'Resubmit a rejected agency profile for verification',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Resubmitted'),
            new OA\Response(response: 403, description: 'Only agency accounts can resubmit verification'),
            new OA\Response(response: 422, description: 'Profile incomplete, or not currently rejected'),
        ]
    )]
    public function resubmitVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can resubmit verification.',
            ], 403);
        }

        $profile = AgencyProfile::query()
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null || !$profile->profile_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Complete your agency profile before submitting for verification.',
            ], 422);
        }

        if ($profile->verification_status !== 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'Only rejected profiles can be resubmitted.',
            ], 422);
        }

        $profile->forceFill([
            'verification_status' => 'pending',
            'verification_rejection_reason' => null,
            'verification_submitted_at' => now(),
            'verification_reviewed_at' => null,
            'verification_reviewed_by' => null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Your agency profile has been resubmitted for verification.',
            'profile' => $profile->fresh(),
        ]);
    }
}
