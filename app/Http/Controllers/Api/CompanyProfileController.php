<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\ServiceCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Company Profile', description: 'Creating and viewing the authenticated company\'s own profile')]
class CompanyProfileController extends Controller
{
    /**
     * Return the authenticated company's profile.
     */
    #[OA\Get(
        path: '/api/company/profile',
        tags: ['Company Profile'],
        summary: 'Get the authenticated company\'s profile',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Company profile',
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
            new OA\Response(response: 403, description: 'Only company accounts can access this profile'),
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

        if ($user->role !== 'company') {
            return response()->json([
                'success' => false,
                'message' => 'Only company accounts can access this profile.',
            ], 403);
        }

        $profile = CompanyProfile::query()
            ->with('services:id,name,slug,icon')
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
     * Create or update the authenticated company's profile.
     */
    #[OA\Post(
        path: '/api/company/profile',
        tags: ['Company Profile'],
        summary: 'Create or update the company profile (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['company_name', 'district', 'service_ids'],
                    properties: [
                        new OA\Property(property: 'company_name', type: 'string', example: 'Bright Home Cleaning Co.'),
                        new OA\Property(property: 'description', type: 'string', nullable: true, maxLength: 2000),
                        new OA\Property(property: 'business_registration_number', type: 'string', nullable: true),
                        new OA\Property(property: 'address', type: 'string', nullable: true),
                        new OA\Property(property: 'district', type: 'string', example: 'Kampala'),
                        new OA\Property(property: 'latitude', type: 'number', nullable: true),
                        new OA\Property(property: 'longitude', type: 'number', nullable: true),
                        new OA\Property(property: 'logo', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(
                            property: 'service_ids',
                            type: 'array',
                            items: new OA\Items(type: 'integer'),
                            description: 'Which service categories this company offers (must be on-demand-service categories)'
                        ),
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
            new OA\Response(response: 403, description: 'Only company accounts can complete this profile'),
            new OA\Response(response: 422, description: 'Validation error, or a selected service is not a bookable on-demand category'),
            new OA\Response(response: 500, description: 'Unable to save the company profile'),
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

        if ($user->role !== 'company') {
            return response()->json([
                'success' => false,
                'message' => 'Only company accounts can complete this profile.',
            ], 403);
        }

        $validated = $request->validate([
            'company_name' => [
                'required',
                'string',
                'min:2',
                'max:255',
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

            'service_ids' => [
                'required',
                'array',
                'min:1',
                'max:20',
            ],

            'service_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:service_categories,id',
            ],
        ]);

        // Companies only offer on-demand, bookable services — not
        // the employment-style categories used for hiring individual
        // staff like housemaids or drivers.
        $validServiceIds = ServiceCategory::query()
            ->whereIn('id', $validated['service_ids'])
            ->where('active', true)
            ->where('transaction_type', 'on_demand_service')
            ->pluck('id');

        if ($validServiceIds->count() !== count($validated['service_ids'])) {
            return response()->json([
                'success' => false,
                'message' => 'One or more selected services are not available for companies.',
            ], 422);
        }

        $newFiles = [];
        $oldFilesToDelete = [];

        try {
            $profile = DB::transaction(function () use (
                $validated,
                $validServiceIds,
                $request,
                $user,
                &$newFiles,
                &$oldFilesToDelete
            ) {
                $profile = CompanyProfile::query()
                    ->firstOrNew([
                        'user_id' => $user->id,
                    ]);

                $isNewProfile = !$profile->exists;

                $logoPath = $profile->logo;

                if ($request->hasFile('logo')) {
                    $newLogoPath = $request
                        ->file('logo')
                        ->store(
                            'company/logos',
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
                    'company_name' => $validated['company_name'],
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

                $profile->services()->sync($validServiceIds);

                $user->update([
                    'full_name' => $validated['company_name'],
                    'location' => $validated['district'],
                ]);

                return $profile->fresh('services');
            });

            foreach (array_unique($oldFilesToDelete) as $oldFile) {
                Storage::disk('public')->delete($oldFile);
            }

            return response()->json([
                'success' => true,
                'message' => 'Company profile saved successfully.',
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
                'message' => 'Unable to save the company profile.',
            ], 500);
        }
    }

    /**
     * Resubmit a rejected company profile for administrator verification.
     */
    #[OA\Post(
        path: '/api/company/profile/resubmit-verification',
        tags: ['Company Profile'],
        summary: 'Resubmit a rejected company profile for verification',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Resubmitted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'profile', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only company accounts can resubmit verification'),
            new OA\Response(response: 422, description: 'Profile incomplete, or not currently rejected'),
        ]
    )]
    public function resubmitVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== 'company') {
            return response()->json([
                'success' => false,
                'message' => 'Only company accounts can resubmit verification.',
            ], 403);
        }

        $profile = CompanyProfile::query()
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null || !$profile->profile_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Complete your company profile before submitting for verification.',
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
            'message' => 'Your company profile has been resubmitted for verification.',
            'profile' => $profile->fresh(),
        ]);
    }
}
