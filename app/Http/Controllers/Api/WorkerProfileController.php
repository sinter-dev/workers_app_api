<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WorkerGalleryImage;
use App\Models\WorkerProfile;
use App\Models\ServiceCategory;
use App\Models\WorkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Worker Profile', description: 'Creating, viewing, and resubmitting the authenticated worker\'s own profile')]
class WorkerProfileController extends Controller
{
    /**
     * Return the authenticated worker's profile.
     */
    #[OA\Get(
        path: '/api/worker/profile',
        tags: ['Worker Profile'],
        summary: 'Get the authenticated worker\'s profile',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Worker profile and user data',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'profile', type: 'object', nullable: true),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this profile'),
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

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can access this profile.',
            ], 403);
        }

        $profile = WorkerProfile::query()
            ->with('galleryImages')
            ->where('user_id', $user->id)
            ->first();

        return response()->json([
            'success' => true,
            'profile' => $profile,
            'user' => $user,
        ]);
    }

    /**
     * Resubmit a rejected worker profile for administrator verification.
     */
    #[OA\Post(
        path: '/api/worker/profile/resubmit-verification',
        tags: ['Worker Profile'],
        summary: 'Resubmit a rejected profile for verification',
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
            new OA\Response(response: 403, description: 'Only worker accounts can resubmit verification'),
            new OA\Response(response: 422, description: 'Profile incomplete, missing ID documents, or not currently rejected'),
        ]
    )]
    public function resubmitVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can resubmit verification.',
            ], 403);
        }

        $profile = WorkerProfile::query()
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null || !$profile->profile_completed) {
            return response()->json([
                'success' => false,
                'message' => 'Complete your worker profile before submitting for verification.',
            ], 422);
        }

        if (
            empty($profile->national_id_front_document) ||
            empty($profile->national_id_back_document)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Front and back National ID images are required before resubmission.',
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
            'identity_verified' => false,
        ])->save();

        $user->forceFill([
            'is_verified' => false,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Your profile has been resubmitted for verification.',
            'profile' => $profile->fresh(),
        ]);
    }

    /**
     * Create or update the authenticated worker's profile.
     */
    #[OA\Post(
        path: '/api/worker/profile',
        tags: ['Worker Profile'],
        summary: 'Create or update the worker profile (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['full_name', 'age', 'religion', 'gender', 'district', 'work_type'],
                    properties: [
                        new OA\Property(property: 'full_name', type: 'string', example: 'Jane Doe'),
                        new OA\Property(property: 'age', type: 'integer', example: 28),
                        new OA\Property(property: 'religion', type: 'string'),
                        new OA\Property(property: 'gender', type: 'string', enum: ['male', 'female', 'other']),
                        new OA\Property(property: 'district', type: 'string', example: 'Kampala'),
                        new OA\Property(property: 'work_type', type: 'string', enum: ['full_time', 'part_time']),
                        new OA\Property(property: 'languages', type: 'array', items: new OA\Items(type: 'string')),
                        new OA\Property(property: 'availability', type: 'string', enum: ['available', 'busy', 'offline']),
                        new OA\Property(property: 'bio', type: 'string', nullable: true),
                        new OA\Property(property: 'experience_years', type: 'integer', nullable: true),
                        new OA\Property(property: 'hourly_rate', type: 'number', nullable: true),
                        new OA\Property(property: 'monthly_rate', type: 'number', nullable: true),
                        new OA\Property(property: 'service_ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Required on first profile creation'),
                        new OA\Property(property: 'profile_photo', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'national_id_front_document', type: 'string', format: 'binary', description: 'Required on first submission'),
                        new OA\Property(property: 'national_id_back_document', type: 'string', format: 'binary', description: 'Required on first submission'),
                        new OA\Property(property: 'gallery_image_1', type: 'string', format: 'binary', description: 'Required unless already saved previously'),
                        new OA\Property(property: 'gallery_image_2', type: 'string', format: 'binary', description: 'Required unless already saved previously'),
                        new OA\Property(property: 'gallery_image_3', type: 'string', format: 'binary', description: 'Required unless already saved previously'),
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
                        new OA\Property(property: 'profile', type: 'object'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only worker accounts can complete a worker profile'),
            new OA\Response(response: 422, description: 'Validation error (missing gallery images, inactive services, etc.)'),
            new OA\Response(response: 500, description: 'Unable to save the worker profile'),
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

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can complete a worker profile.',
            ], 403);
        }

        $existingProfile = WorkerProfile::query()
            ->with('galleryImages')
            ->where('user_id', $user->id)
            ->first();

        $validated = $request->validate([
            'full_name' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],

            'age' => [
                'required',
                'integer',
                'min:18',
                'max:70',
            ],

            'religion' => [
                'required',
                'string',
                'max:100',
            ],

            'gender' => [
                'required',
                Rule::in([
                    'male',
                    'female',
                    'other',
                ]),
            ],

            'district' => [
                'required',
                'string',
                'max:150',
            ],

            'work_type' => [
                'required',
                Rule::in([
                    'full_time',
                    'part_time',
                ]),
            ],

            'languages' => [
                'nullable',
                'array',
                'max:12',
            ],

            'languages.*' => [
                'string',
                'distinct',
                'max:80',
            ],

            'availability' => [
                'nullable',
                Rule::in([
                    'available',
                    'busy',
                    'offline',
                ]),
            ],


            'bio' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'experience_years' => [
                'nullable',
                'integer',
                'min:0',
                'max:60',
            ],

            'hourly_rate' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'monthly_rate' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'service_ids' => [
                $existingProfile === null ? 'required' : 'sometimes',
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

            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'national_id_front_document' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],

            'national_id_back_document' => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],

            'gallery_image_1' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'gallery_image_2' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'gallery_image_3' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Ensure all three gallery positions exist
        |--------------------------------------------------------------------------
        |
        | New workers must upload three images.
        | Existing workers may keep an old image without uploading it again.
        |
        */

        $existingPositions = $existingProfile === null
            ? []
            : $existingProfile
                ->galleryImages
                ->pluck('position')
                ->map(fn ($position) => (int) $position)
                ->all();

        $missingGalleryImages = [];

        for ($position = 1; $position <= 3; $position++) {
            $fieldName = "gallery_image_{$position}";

            $hasNewImage = $request->hasFile($fieldName);

            $hasExistingImage = in_array(
                $position,
                $existingPositions,
                true
            );

            if (!$hasNewImage && !$hasExistingImage) {
                $missingGalleryImages[$fieldName] = [
                    "Gallery photo {$position} is required.",
                ];
            }
        }

        if (!empty($missingGalleryImages)) {
            return response()->json([
                'message' => 'Please upload all 3 gallery photos.',
                'errors' => $missingGalleryImages,
            ], 422);
        }

        $serviceIds = collect($validated['service_ids'])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $activeServiceIds = ServiceCategory::query()
            ->whereIn('id', $serviceIds)
            ->where('active', true)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($activeServiceIds->count() !== $serviceIds->count()) {
            return response()->json([
                'success' => false,
                'message' => 'One or more selected services are unavailable.',
                'errors' => [
                    'service_ids' => [
                        'Please select only active service categories.',
                    ],
                ],
            ], 422);
        }

        $newFiles = [];
        $oldFilesToDelete = [];

        try {
            $profile = DB::transaction(function () use (
                $request,
                $validated,
                $user,
                $activeServiceIds,
                &$newFiles,
                &$oldFilesToDelete
            ) {
                $profile = WorkerProfile::query()
                    ->firstOrNew([
                        'user_id' => $user->id,
                    ]);

                $profilePhotoPath = $profile->profile_photo;

                $nationalIdFrontDocumentPath =
                    $profile->national_id_front_document;

                $nationalIdBackDocumentPath =
                    $profile->national_id_back_document;

                /*
                |--------------------------------------------------------------------------
                | Profile photo
                |--------------------------------------------------------------------------
                */

                if ($request->hasFile('profile_photo')) {
                    $newProfilePhotoPath = $request
                        ->file('profile_photo')
                        ->store(
                            'worker/profile-photos',
                            'public'
                        );

                    $newFiles[] = [
                        'disk' => 'public',
                        'path' => $newProfilePhotoPath,
                    ];

                    if (
                        $profilePhotoPath !== null
                        && $profilePhotoPath !== $newProfilePhotoPath
                    ) {
                        $oldFilesToDelete[] = [
                            'disk' => 'public',
                            'path' => $profilePhotoPath,
                        ];
                    }

                    $profilePhotoPath = $newProfilePhotoPath;
                }

                /*
                |--------------------------------------------------------------------------
                | National ID document
                |--------------------------------------------------------------------------
                */

                if ($request->hasFile('national_id_front_document')) {
                    $newNationalIdFrontPath = $request
                        ->file('national_id_front_document')
                        ->store(
                            'worker/national-id-documents',
                            'local'
                        );

                    $newFiles[] = [
                        'disk' => 'local',
                        'path' => $newNationalIdFrontPath,
                    ];

                    if (
                        $nationalIdFrontDocumentPath !== null
                        && $nationalIdFrontDocumentPath !== $newNationalIdFrontPath
                    ) {
                        $oldFilesToDelete[] = [
                            'disk' => 'local',
                            'path' =>
                                $nationalIdFrontDocumentPath,
                        ];
                    }

                    $nationalIdFrontDocumentPath =
                        $newNationalIdFrontPath;
                }

                if ($request->hasFile('national_id_back_document')) {
                    $newNationalIdBackPath = $request
                        ->file('national_id_back_document')
                        ->store(
                            'worker/national-id-documents',
                            'local'
                        );

                    $newFiles[] = [
                        'disk' => 'local',
                        'path' => $newNationalIdBackPath,
                    ];

                    if (
                        $nationalIdBackDocumentPath !== null
                        && $nationalIdBackDocumentPath !== $newNationalIdBackPath
                    ) {
                        $oldFilesToDelete[] = [
                            'disk' => 'local',
                            'path' =>
                                $nationalIdBackDocumentPath,
                        ];
                    }

                    $nationalIdBackDocumentPath =
                        $newNationalIdBackPath;
                }

                if (
                    empty($nationalIdFrontDocumentPath)
                    || empty($nationalIdBackDocumentPath)
                ) {
                    throw new \RuntimeException(
                        'Both front and back National ID images are required.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Save worker profile
                |--------------------------------------------------------------------------
                */

                $profile->fill([
                    'age' => $validated['age'],
                    'religion' => $validated['religion'],
                    'gender' => $validated['gender'],
                    'district' => $validated['district'],
                    'work_type' => $validated['work_type'],
                    'languages' => $validated['languages'] ?? [],

                    'availability' =>
                        $validated['availability']
                        ?? 'available',


                    'national_id_front_document' =>
                        $nationalIdFrontDocumentPath,

                    'national_id_back_document' =>
                        $nationalIdBackDocumentPath,

                    'profile_photo' => $profilePhotoPath,

                    'profile_completed' => true,

                    'bio' => $validated['bio'] ?? null,

                    'experience_years' =>
                        $validated['experience_years']
                        ?? 0,

                    'hourly_rate' =>
                        $validated['hourly_rate']
                        ?? null,

                    'monthly_rate' =>
                        $validated['monthly_rate']
                        ?? null,
                ]);

                $profile->save();

                /*
                |--------------------------------------------------------------------------
                | Save worker services
                |--------------------------------------------------------------------------
                */

                WorkerService::query()
                    ->where('worker_profile_id', $profile->id)
                    ->delete();

                $now = now();
                $serviceRows = $activeServiceIds
                    ->map(fn (int $serviceId): array => [
                        'worker_profile_id' => $profile->id,
                        'service_category_id' => $serviceId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all();

                WorkerService::query()->insert($serviceRows);

                /*
                |--------------------------------------------------------------------------
                | Save three gallery images
                |--------------------------------------------------------------------------
                */

                for ($position = 1; $position <= 3; $position++) {
                    $fieldName = "gallery_image_{$position}";

                    if (!$request->hasFile($fieldName)) {
                        continue;
                    }

                    $existingGalleryImage =
                        WorkerGalleryImage::query()
                            ->where(
                                'worker_profile_id',
                                $profile->id
                            )
                            ->where(
                                'position',
                                $position
                            )
                            ->first();

                    $newGalleryPath = $request
                        ->file($fieldName)
                        ->store(
                            'worker/gallery',
                            'public'
                        );

                    $newFiles[] = [
                        'disk' => 'public',
                        'path' => $newGalleryPath,
                    ];

                    if (
                        $existingGalleryImage !== null
                        && $existingGalleryImage->image_path
                        !== $newGalleryPath
                    ) {
                        $oldFilesToDelete[] = [
                            'disk' => 'public',
                            'path' =>
                                $existingGalleryImage->image_path,
                        ];
                    }

                    WorkerGalleryImage::query()
                        ->updateOrCreate(
                            [
                                'worker_profile_id' =>
                                    $profile->id,

                                'position' => $position,
                            ],
                            [
                                'image_path' =>
                                    $newGalleryPath,
                            ]
                        );
                }

                /*
                |--------------------------------------------------------------------------
                | Update user table
                |--------------------------------------------------------------------------
                */

                $user->update([
                    'full_name' =>
                        $validated['full_name'],

                    'location' =>
                        $validated['district'],

                    'profile_photo' =>
                        $profilePhotoPath,
                ]);

                return $profile
                    ->fresh([
                        'galleryImages',
                        'services',
                    ]);
            });

            /*
            |--------------------------------------------------------------------------
            | Delete replaced files after successful database transaction
            |--------------------------------------------------------------------------
            */

            foreach ($oldFilesToDelete as $oldFile) {
                Storage::disk($oldFile['disk'])->delete(
                    $oldFile['path']
                );
            }

            return response()->json([
                'success' => true,

                'message' =>
                    'Worker profile, services, and gallery saved successfully.',

                'profile' => $profile,

                'user' => $user->fresh(),
            ]);
        } catch (Throwable $exception) {
            /*
            |--------------------------------------------------------------------------
            | Remove newly uploaded files if database saving fails
            |--------------------------------------------------------------------------
            */

            foreach ($newFiles as $newFile) {
                Storage::disk($newFile['disk'])->delete(
                    $newFile['path']
                );
            }

            report($exception);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to save the worker profile.',
            ], 500);
        }
    }
}