<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\WorkerGalleryImage;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Worker Profile Sections', description: 'Granular, section-by-section updates to an existing worker profile')]
class WorkerProfileSectionController extends Controller
{
    /**
     * Update the worker's personal information.
     */
    #[OA\Post(
        path: '/api/worker/profile/personal',
        tags: ['Worker Profile Sections'],
        summary: 'Update National ID documents (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'national_id_front_document', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'national_id_back_document', type: 'string', format: 'binary', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this'),
            new OA\Response(response: 404, description: 'Worker profile not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Update failed'),
        ]
    )]
    public function personal(Request $request): JsonResponse
    {
        $user = $request->user();

        $roleError = $this->validateWorkerAccount($user);

        if ($roleError !== null) {
            return $roleError;
        }

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

            'profile_photo' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],
        ]);

        $newPhoto = null;
        $oldPhoto = null;

        try {
            $result = DB::transaction(function () use (
                $request,
                $validated,
                $user,
                &$newPhoto,
                &$oldPhoto
            ): array {
                $profile = WorkerProfile::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($profile === null) {
                    throw new \RuntimeException(
                        'Worker profile could not be found.'
                    );
                }

                $profilePhotoPath = $profile->profile_photo
                    ?? $user->profile_photo;

                if ($request->hasFile('profile_photo')) {
                    $newPhoto = $request
                        ->file('profile_photo')
                        ->store(
                            'worker/profile-photos',
                            'public'
                        );

                    if (
                        $profilePhotoPath !== null
                        && $profilePhotoPath !== $newPhoto
                    ) {
                        $oldPhoto = $profilePhotoPath;
                    }

                    $profilePhotoPath = $newPhoto;
                }

                $profile->update([
                    'age' => $validated['age'],
                    'religion' => $validated['religion'],
                    'gender' => $validated['gender'],
                    'district' => $validated['district'],
                    'profile_photo' => $profilePhotoPath,
                ]);

                $user->update([
                    'full_name' => $validated['full_name'],
                    'location' => $validated['district'],
                    'profile_photo' => $profilePhotoPath,
                ]);

                return [
                    'profile' => $profile->fresh([
                        'galleryImages',
                    ]),
                    'user' => $user->fresh(),
                ];
            });

            if ($oldPhoto !== null) {
                Storage::disk('local')->delete($oldPhoto);
            }

            return response()->json([
                'success' => true,
                'message' => 'Personal information updated successfully.',
                'profile' => $result['profile'],
                'user' => $result['user'],
            ]);
        } catch (Throwable $exception) {
            if ($newPhoto !== null) {
                Storage::disk('local')->delete($newPhoto);
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update personal information.',
            ], 500);
        }
    }

    /**
     * Update the worker's professional information.
     */
    #[OA\Put(
        path: '/api/worker/profile/professional',
        tags: ['Worker Profile Sections'],
        summary: 'Update work type, availability, bio, experience, and rates',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['work_type', 'availability', 'experience_years'],
                properties: [
                    new OA\Property(property: 'work_type', type: 'string', enum: ['full_time', 'part_time']),
                    new OA\Property(property: 'availability', type: 'string', enum: ['available', 'busy', 'unavailable']),
                    new OA\Property(property: 'bio', type: 'string', nullable: true, maxLength: 2000),
                    new OA\Property(property: 'experience_years', type: 'integer', minimum: 0, maximum: 60),
                    new OA\Property(property: 'hourly_rate', type: 'number', nullable: true),
                    new OA\Property(property: 'monthly_rate', type: 'number', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'profile', type: 'object'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Only worker accounts can access this'),
            new OA\Response(response: 404, description: 'Worker profile not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Update failed'),
        ]
    )]
    public function professional(Request $request): JsonResponse
    {
        $user = $request->user();

        $roleError = $this->validateWorkerAccount($user);

        if ($roleError !== null) {
            return $roleError;
        }

        $profile = WorkerProfile::query()
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Worker profile could not be found.',
            ], 404);
        }

        $validated = $request->validate([
            'work_type' => [
                'required',
                Rule::in([
                    'full_time',
                    'part_time',
                ]),
            ],

            'availability' => [
                'required',
                Rule::in([
                    'available',
                    'busy',
                    'unavailable',
                ]),
            ],

            'bio' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'experience_years' => [
                'required',
                'integer',
                'min:0',
                'max:60',
            ],

            'hourly_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100000000',
            ],

            'monthly_rate' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100000000',
            ],
        ]);

        /* Availability is now controlled by the worker, not by active-job count. */

        try {
            $profile->update([
                'work_type' => $validated['work_type'],
                'availability' => $validated['availability'],
                'bio' => $validated['bio'] ?? null,
                'experience_years' =>
                    $validated['experience_years'],
                'hourly_rate' =>
                    $validated['hourly_rate'] ?? null,
                'monthly_rate' =>
                    $validated['monthly_rate'] ?? null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Professional information updated successfully.',
                'profile' => $profile->fresh([
                    'galleryImages',
                ]),
                'user' => $user->fresh(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update professional information.',
            ], 500);
        }
    }

    /**
     * Update National ID front and back documents.
     */
    /**
     * Update National ID front and back documents.
     */
    #[OA\Post(
        path: '/api/worker/profile/verification',
        tags: ['Worker Profile Sections'],
        summary: 'Submit or update verification documents (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object'))
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this'),
            new OA\Response(response: 404, description: 'Worker profile not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Update failed'),
        ]
    )]
    public function verification(Request $request): JsonResponse
    {
        $user = $request->user();

        $roleError = $this->validateWorkerAccount($user);

        if ($roleError !== null) {
            return $roleError;
        }

        $profile = WorkerProfile::query()
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Worker profile could not be found.',
            ], 404);
        }

        $validated = $request->validate([
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
        ]);

        $newFrontDocument = null;
        $newBackDocument = null;
        $oldFrontDocument = null;
        $oldBackDocument = null;

        try {
            DB::transaction(function () use (
                $request,
                $profile,
                &$newFrontDocument,
                &$newBackDocument,
                &$oldFrontDocument,
                &$oldBackDocument
            ): void {
                $frontPath =
                    $profile->national_id_front_document;

                $backPath =
                    $profile->national_id_back_document;

                if (
                    $request->hasFile(
                        'national_id_front_document'
                    )
                ) {
                    $newFrontDocument = $request
                        ->file('national_id_front_document')
                        ->store(
                            'worker/national-id-documents',
                            'local'
                        );

                    if (
                        $frontPath !== null
                        && $frontPath !== $newFrontDocument
                    ) {
                        $oldFrontDocument = $frontPath;
                    }

                    $frontPath = $newFrontDocument;
                }

                if (
                    $request->hasFile(
                        'national_id_back_document'
                    )
                ) {
                    $newBackDocument = $request
                        ->file('national_id_back_document')
                        ->store(
                            'worker/national-id-documents',
                            'local'
                        );

                    if (
                        $backPath !== null
                        && $backPath !== $newBackDocument
                    ) {
                        $oldBackDocument = $backPath;
                    }

                    $backPath = $newBackDocument;
                }

                if ($frontPath === null || $backPath === null) {
                    throw new \RuntimeException(
                        'Front and back National ID images are required.'
                    );
                }

                $profile->update([
                    'national_id_front_document' => $frontPath,
                    'national_id_back_document' => $backPath,
                    'identity_verified' => false,
                ]);
            });

            if ($oldFrontDocument !== null) {
                Storage::disk('local')->delete(
                    $oldFrontDocument
                );
            }

            if ($oldBackDocument !== null) {
                Storage::disk('local')->delete(
                    $oldBackDocument
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'National ID images submitted successfully. Verification is pending.',
                'profile' => $profile->fresh([
                    'galleryImages',
                ]),
            ]);
        } catch (Throwable $exception) {
            if ($newFrontDocument !== null) {
                Storage::disk('local')->delete(
                    $newFrontDocument
                );
            }

            if ($newBackDocument !== null) {
                Storage::disk('local')->delete(
                    $newBackDocument
                );
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update National ID images.',
            ], 500);
        }
    }

    /**
     * Replace one or more gallery images.
     */
    #[OA\Post(
        path: '/api/worker/profile/gallery',
        tags: ['Worker Profile Sections'],
        summary: 'Update gallery photos (multipart/form-data)',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'gallery_image_1', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'gallery_image_2', type: 'string', format: 'binary', nullable: true),
                        new OA\Property(property: 'gallery_image_3', type: 'string', format: 'binary', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 403, description: 'Only worker accounts can access this'),
            new OA\Response(response: 404, description: 'Worker profile not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Update failed'),
        ]
    )]
    public function gallery(Request $request): JsonResponse
    {
        $user = $request->user();

        $roleError = $this->validateWorkerAccount($user);

        if ($roleError !== null) {
            return $roleError;
        }

        $profile = WorkerProfile::query()
            ->with('galleryImages')
            ->where('user_id', $user->id)
            ->first();

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Worker profile could not be found.',
            ], 404);
        }

        $validated = $request->validate([
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

        $hasAnyNewImage = collect([
            1,
            2,
            3,
        ])->contains(
            fn (int $position): bool =>
                $request->hasFile(
                    "gallery_image_{$position}"
                )
        );

        if (!$hasAnyNewImage) {
            return response()->json([
                'success' => false,
                'message' => 'Select at least one gallery image to replace.',
            ], 422);
        }

        $newFiles = [];
        $oldFiles = [];

        try {
            DB::transaction(function () use (
                $request,
                $profile,
                &$newFiles,
                &$oldFiles
            ): void {
                for (
                    $position = 1;
                    $position <= 3;
                    $position++
                ) {
                    $fieldName =
                        "gallery_image_{$position}";

                    if (!$request->hasFile($fieldName)) {
                        continue;
                    }

                    $existingImage =
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

                    $newPath = $request
                        ->file($fieldName)
                        ->store(
                            'worker/gallery',
                            'public'
                        );

                    $newFiles[] = $newPath;

                    if (
                        $existingImage !== null
                        && $existingImage->image_path
                        !== $newPath
                    ) {
                        $oldFiles[] =
                            $existingImage->image_path;
                    }

                    WorkerGalleryImage::query()
                        ->updateOrCreate(
                            [
                                'worker_profile_id' =>
                                    $profile->id,
                                'position' => $position,
                            ],
                            [
                                'image_path' => $newPath,
                            ]
                        );
                }
            });

            foreach (
                array_unique($oldFiles)
                as $oldFile
            ) {
                Storage::disk('local')->delete(
                    $oldFile
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Gallery updated successfully.',
                'profile' => $profile->fresh([
                    'galleryImages',
                ]),
            ]);
        } catch (Throwable $exception) {
            foreach (
                array_unique($newFiles)
                as $newFile
            ) {
                Storage::disk('local')->delete(
                    $newFile
                );
            }

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update the gallery.',
            ], 500);
        }
    }

    /**
     * Validate that the authenticated account is a worker.
     */
    private function validateWorkerAccount(
        mixed $user
    ): ?JsonResponse {
        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->role !== 'worker') {
            return response()->json([
                'success' => false,
                'message' => 'Only worker accounts can manage this profile.',
            ], 403);
        }

        return null;
    }
}