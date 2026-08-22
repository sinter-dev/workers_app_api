<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HomeownerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Homeowner Profile', description: 'Creating and viewing the authenticated homeowner\'s own profile')]
class HomeownerProfileController extends Controller
{
    /**
     * Return the authenticated homeowner's profile.
     */
    #[OA\Get(
        path: '/api/homeowner/profile',
        tags: ['Homeowner Profile'],
        summary: 'Get the authenticated homeowner\'s profile',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Homeowner profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'profile_completed', type: 'boolean'),
                        new OA\Property(property: 'profile', type: 'object', nullable: true),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only homeowner accounts can access this profile'),
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

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can access this profile.',
            ], 403);
        }

        $profile = HomeownerProfile::query()
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'success' => true,
            'profile_completed' => (bool) ($profile?->profile_completed ?? false),
            'profile' => $profile,
            'user' => $user,
        ]);
    }

    /**
     * Create or update the authenticated homeowner's profile.
     */
    #[OA\Post(
        path: '/api/homeowner/profile',
        tags: ['Homeowner Profile'],
        summary: 'Create or update the homeowner profile (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['full_name', 'email', 'address', 'district', 'preferred_contact'],
                    properties: [
                        new OA\Property(property: 'full_name', type: 'string', example: 'John Homeowner'),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'address', type: 'string'),
                        new OA\Property(property: 'city', type: 'string', nullable: true),
                        new OA\Property(property: 'district', type: 'string', example: 'Kampala'),
                        new OA\Property(property: 'country', type: 'string', nullable: true, example: 'Uganda'),
                        new OA\Property(property: 'preferred_contact', type: 'string', enum: ['phone', 'whatsapp', 'email']),
                        new OA\Property(property: 'profile_photo', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'latitude', type: 'number', nullable: true),
                        new OA\Property(property: 'longitude', type: 'number', nullable: true),
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
            new OA\Response(response: 403, description: 'Only homeowner accounts can complete this profile'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Unable to save the homeowner profile'),
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

        if ($user->role !== 'homeowner') {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowner accounts can complete this profile.',
            ], 403);
        }

        $validated = $request->validate([
            'full_name' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],

            'address' => [
                'required',
                'string',
                'max:255',
            ],

            'city' => [
                'nullable',
                'string',
                'max:150',
            ],

            'district' => [
                'required',
                'string',
                'max:150',
            ],

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'preferred_contact' => [
                'required',
                Rule::in([
                    'phone',
                    'whatsapp',
                    'email',
                ]),
            ],

            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
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
        ]);

        $newFiles = [];
        $oldFilesToDelete = [];

        try {
            $profile = DB::transaction(function () use (
                $request,
                $validated,
                $user,
                &$newFiles,
                &$oldFilesToDelete
            ) {
                $profile = HomeownerProfile::query()
                    ->firstOrNew([
                        'user_id' => $user->id,
                    ]);

                $profilePhotoPath = $user->profile_photo;

                if ($request->hasFile('profile_photo')) {
                    $newProfilePhotoPath = $request
                        ->file('profile_photo')
                        ->store(
                            'homeowner/profile-photos',
                            'public'
                        );

                    $newFiles[] = $newProfilePhotoPath;

                    if (
                        $profilePhotoPath !== null
                        && $profilePhotoPath !== $newProfilePhotoPath
                    ) {
                        $oldFilesToDelete[] = $profilePhotoPath;
                    }

                    $profilePhotoPath = $newProfilePhotoPath;
                }

                $profile->fill([
                    'address' => $validated['address'],
                    'city' => $validated['city'] ?? null,
                    'district' => $validated['district'],
                    'country' => $validated['country'] ?? 'Uganda',
                    'latitude' => $validated['latitude'] ?? null,
                    'longitude' => $validated['longitude'] ?? null,
                    'preferred_contact' => $validated['preferred_contact'],
                    'profile_completed' => true,
                ]);

                $profile->save();

                $user->update([
                    'full_name' => $validated['full_name'],
                    'email' => $validated['email'],
                    'location' => $validated['district'],
                    'profile_photo' => $profilePhotoPath,
                ]);

                return $profile->fresh();
            });

            foreach (array_unique($oldFilesToDelete) as $oldFile) {
                Storage::disk('public')->delete($oldFile);
            }

            return response()->json([
                'success' => true,
                'message' => 'Homeowner profile saved successfully.',
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
                'message' => 'Unable to save the homeowner profile.',
            ], 500);
        }
    }
}