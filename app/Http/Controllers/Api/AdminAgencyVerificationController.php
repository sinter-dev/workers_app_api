<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AgencyProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Admin Agency Verification', description: 'Administrator review of agency verification submissions')]
class AdminAgencyVerificationController extends Controller
{
    #[OA\Get(
        path: '/api/admin/agency-verifications',
        tags: ['Admin Agency Verification'],
        summary: 'List agency verification submissions',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'all'], default: 'pending')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Agency verification queue',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'agencies', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])],
        ]);

        $status = $validated['status'] ?? 'pending';

        $query = AgencyProfile::query()
            ->with([
                'user:id,full_name,phone,email,role,profile_photo,location,is_verified,created_at',
            ])
            ->where('profile_completed', true);

        if ($status !== 'all') {
            $query->where('verification_status', $status);
        }

        $profiles = $query
            ->orderByRaw('CASE WHEN verification_submitted_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('verification_submitted_at')
            ->orderBy('id')
            ->get()
            ->map(fn (AgencyProfile $profile) => $this->summary($profile));

        return response()->json([
            'success' => true,
            'status' => $status,
            'agencies' => $profiles,
        ]);
    }

    #[OA\Get(
        path: '/api/admin/agency-verifications/{agencyProfile}',
        tags: ['Admin Agency Verification'],
        summary: 'View full verification details for one agency',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agencyProfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Full agency verification details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function show(AgencyProfile $agencyProfile): JsonResponse
    {
        $agencyProfile->load([
            'user:id,full_name,phone,email,role,profile_photo,location,is_verified,created_at',
        ]);

        return response()->json([
            'success' => true,
            'agency' => $this->details($agencyProfile),
        ]);
    }

    #[OA\Post(
        path: '/api/admin/agency-verifications/{agencyProfile}/approve',
        tags: ['Admin Agency Verification'],
        summary: 'Approve a agency\'s verification',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agencyProfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Agency approved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function approve(Request $request, AgencyProfile $agencyProfile): JsonResponse
    {
        $admin = $request->user();

        DB::transaction(function () use ($admin, $agencyProfile) {
            $agencyProfile->forceFill([
                'verification_status' => 'approved',
                'verification_rejection_reason' => null,
                'verification_reviewed_at' => now(),
                'verification_reviewed_by' => $admin->id,
            ])->save();

            $agencyProfile->user()->update([
                'is_verified' => true,
            ]);
        });

        AppNotificationService::send(
            $agencyProfile->user_id,
            'agency_verification_approved',
            'system',
            'Agency verified',
            'Your agency profile has been approved. Your verified badge is now active.',
            'agency_profile',
            $agencyProfile->user_id,
            ['verification_status' => 'approved']
        );

        return response()->json([
            'success' => true,
            'message' => 'Agency approved successfully.',
        ]);
    }

    #[OA\Post(
        path: '/api/admin/agency-verifications/{agencyProfile}/reject',
        tags: ['Admin Agency Verification'],
        summary: 'Reject a agency\'s verification',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'agencyProfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', minLength: 5, maxLength: 2000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Agency verification rejected'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function reject(Request $request, AgencyProfile $agencyProfile): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($admin, $agencyProfile, $validated) {
            $agencyProfile->forceFill([
                'verification_status' => 'rejected',
                'verification_rejection_reason' => trim($validated['reason']),
                'verification_reviewed_at' => now(),
                'verification_reviewed_by' => $admin->id,
            ])->save();

            $agencyProfile->user()->update([
                'is_verified' => false,
            ]);
        });

        AppNotificationService::send(
            $agencyProfile->user_id,
            'agency_verification_rejected',
            'system',
            'Verification needs attention',
            'Your agency verification was not approved. Open your profile to review the reason and correct your details.',
            'agency_profile',
            $agencyProfile->user_id,
            [
                'verification_status' => 'rejected',
                'reason' => trim($validated['reason']),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Agency verification rejected.',
        ]);
    }

    private function summary(AgencyProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'agency_name' => $profile->agency_name,
            'specialty' => $profile->specialty,
            'phone' => $profile->user?->phone,
            'district' => $profile->district,
            'logo' => $profile->logo,
            'verification_status' => $profile->verification_status,
            'submitted_at' => optional($profile->verification_submitted_at)->toIso8601String(),
            'worker_count' => $profile->workers()->count(),
        ];
    }

    private function details(AgencyProfile $profile): array
    {
        return [
            ...$this->summary($profile),
            'email' => $profile->user?->email,
            'description' => $profile->description,
            'business_registration_number' => $profile->business_registration_number,
            'address' => $profile->address,
            'user_is_verified' => (bool) $profile->user?->is_verified,
            'rejection_reason' => $profile->verification_rejection_reason,
            'reviewed_at' => optional($profile->verification_reviewed_at)->toIso8601String(),
        ];
    }
}
