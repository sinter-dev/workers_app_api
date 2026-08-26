<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Admin Company Verification', description: 'Administrator review of company verification submissions')]
class AdminCompanyVerificationController extends Controller
{
    #[OA\Get(
        path: '/api/admin/company-verifications',
        tags: ['Admin Company Verification'],
        summary: 'List company verification submissions',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'all'], default: 'pending')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Company verification queue',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'companies', type: 'array', items: new OA\Items(type: 'object')),
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

        $query = CompanyProfile::query()
            ->with([
                'user:id,full_name,phone,email,role,profile_photo,location,is_verified,created_at',
                'services:id,name,slug,icon',
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
            ->map(fn (CompanyProfile $profile) => $this->summary($profile));

        return response()->json([
            'success' => true,
            'status' => $status,
            'companies' => $profiles,
        ]);
    }

    #[OA\Get(
        path: '/api/admin/company-verifications/{companyProfile}',
        tags: ['Admin Company Verification'],
        summary: 'View full verification details for one company',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'companyProfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Full company verification details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function show(CompanyProfile $companyProfile): JsonResponse
    {
        $companyProfile->load([
            'user:id,full_name,phone,email,role,profile_photo,location,is_verified,created_at',
            'services:id,name,slug,icon,description',
        ]);

        return response()->json([
            'success' => true,
            'company' => $this->details($companyProfile),
        ]);
    }

    #[OA\Post(
        path: '/api/admin/company-verifications/{companyProfile}/approve',
        tags: ['Admin Company Verification'],
        summary: 'Approve a company\'s verification',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'companyProfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Company approved'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
        ]
    )]
    public function approve(Request $request, CompanyProfile $companyProfile): JsonResponse
    {
        $admin = $request->user();

        DB::transaction(function () use ($admin, $companyProfile) {
            $companyProfile->forceFill([
                'verification_status' => 'approved',
                'verification_rejection_reason' => null,
                'verification_reviewed_at' => now(),
                'verification_reviewed_by' => $admin->id,
            ])->save();

            $companyProfile->user()->update([
                'is_verified' => true,
            ]);
        });

        AppNotificationService::send(
            $companyProfile->user_id,
            'company_verification_approved',
            'system',
            'Company verified',
            'Your company profile has been approved. Your verified badge is now active.',
            'company_profile',
            $companyProfile->user_id,
            ['verification_status' => 'approved']
        );

        return response()->json([
            'success' => true,
            'message' => 'Company approved successfully.',
        ]);
    }

    #[OA\Post(
        path: '/api/admin/company-verifications/{companyProfile}/reject',
        tags: ['Admin Company Verification'],
        summary: 'Reject a company\'s verification',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'companyProfile', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 200, description: 'Company verification rejected'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not an administrator'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function reject(Request $request, CompanyProfile $companyProfile): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $admin = $request->user();

        DB::transaction(function () use ($admin, $companyProfile, $validated) {
            $companyProfile->forceFill([
                'verification_status' => 'rejected',
                'verification_rejection_reason' => trim($validated['reason']),
                'verification_reviewed_at' => now(),
                'verification_reviewed_by' => $admin->id,
            ])->save();

            $companyProfile->user()->update([
                'is_verified' => false,
            ]);
        });

        AppNotificationService::send(
            $companyProfile->user_id,
            'company_verification_rejected',
            'system',
            'Verification needs attention',
            'Your company verification was not approved. Open your profile to review the reason and correct your details.',
            'company_profile',
            $companyProfile->user_id,
            [
                'verification_status' => 'rejected',
                'reason' => trim($validated['reason']),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Company verification rejected.',
        ]);
    }

    private function summary(CompanyProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'company_name' => $profile->company_name,
            'phone' => $profile->user?->phone,
            'district' => $profile->district,
            'logo' => $profile->logo,
            'verification_status' => $profile->verification_status,
            'submitted_at' => optional($profile->verification_submitted_at)->toIso8601String(),
            'services' => $profile->services->pluck('name')->values(),
        ];
    }

    private function details(CompanyProfile $profile): array
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
            'services' => $profile->services->map(fn ($service) => [
                'id' => $service->id,
                'name' => $service->name,
            ])->values(),
        ];
    }
}
