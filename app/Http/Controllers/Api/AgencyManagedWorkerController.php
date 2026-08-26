<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Agency Managed Workers', description: 'An agency directly creating and listing the workers it manages')]
class AgencyManagedWorkerController extends Controller
{
    /**
     * List workers currently managed by the authenticated agency.
     */
    #[OA\Get(
        path: '/api/agency/workers',
        tags: ['Agency Managed Workers'],
        summary: 'List workers managed by this agency',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Managed workers',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'workers', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only agency accounts can access this'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can access this.',
            ], 403);
        }

        $workers = WorkerProfile::query()
            ->with('user:id,full_name,phone,profile_photo,location,is_verified')
            ->where('agency_id', $user->id)
            ->get();

        return response()->json([
            'success' => true,
            'workers' => $workers,
        ]);
    }

    /**
     * Create a new worker account, already linked to this agency.
     *
     * Used when the agency is onboarding a worker who has never
     * used the app before — the agency enters basic details on
     * the worker's behalf. A temporary password is generated and
     * returned once, for the agency to pass on to the worker.
     */
    #[OA\Post(
        path: '/api/agency/workers',
        tags: ['Agency Managed Workers'],
        summary: 'Create a worker account managed by this agency',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['full_name', 'phone'],
                properties: [
                    new OA\Property(property: 'full_name', type: 'string', example: 'Jane Doe'),
                    new OA\Property(property: 'phone', type: 'string', example: '+256700000000'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Worker account created and linked to this agency',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'temporary_password', type: 'string', description: 'Shown once only — pass this to the worker so they can log in.'),
                        new OA\Property(property: 'worker', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Only agency accounts can access this'),
            new OA\Response(response: 422, description: 'Validation error, e.g. phone already registered'),
            new OA\Response(response: 500, description: 'Unable to create the worker account'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $agency = $request->user();

        if ($agency === null || $agency->role !== 'agency') {
            return response()->json([
                'success' => false,
                'message' => 'Only agency accounts can access this.',
            ], 403);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
        ]);

        $temporaryPassword = Str::password(10, symbols: false);

        try {
            $worker = DB::transaction(function () use ($validated, $temporaryPassword, $agency) {
                $user = User::query()->create([
                    'full_name' => $validated['full_name'],
                    'phone' => $validated['phone'],
                    'password' => Hash::make($temporaryPassword),
                    'role' => 'worker',
                    'is_verified' => false,
                ]);

                WorkerProfile::query()->create([
                    'user_id' => $user->id,
                    'agency_id' => $agency->id,
                ]);

                return $user;
            });

            return response()->json([
                'success' => true,
                'message' => 'Worker account created and linked to your agency.',
                'temporary_password' => $temporaryPassword,
                'worker' => $worker,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create the worker account.',
            ], 500);
        }
    }
}
