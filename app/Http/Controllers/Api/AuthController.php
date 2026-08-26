<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\CompanyProfile;
use App\Models\HomeownerProfile;
use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Auth', description: 'Registration, login, and session management')]
class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    #[OA\Post(
        path: '/api/register',
        tags: ['Auth'],
        summary: 'Register a new worker or homeowner account',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['full_name', 'phone', 'password', 'role'],
                properties: [
                    new OA\Property(property: 'full_name', type: 'string', example: 'Jane Doe'),
                    new OA\Property(property: 'phone', type: 'string', example: '+256700000000'),
                    new OA\Property(property: 'email', type: 'string', example: 'jane@example.com', nullable: true),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'Str0ngPass!'),
                    new OA\Property(property: 'role', type: 'string', enum: ['worker', 'homeowner']),
                    new OA\Property(property: 'location', type: 'string', example: 'Kampala', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Registration successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 500, description: 'Registration failed'),
        ]
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $result = DB::transaction(function () use ($request): array {
                $user = User::query()->create([
                    'full_name' => $request->full_name,
                    'phone' => $request->phone,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'role' => $request->role,
                    'location' => $request->location,
                    'is_verified' => false,
                ]);

                if ($user->role === 'worker') {
                    WorkerProfile::query()->create([
                        'user_id' => $user->id,
                    ]);
                } elseif ($user->role === 'company') {
                    CompanyProfile::query()->create([
                        'user_id' => $user->id,
                    ]);
                } elseif ($user->role === 'homeowner') {
                    HomeownerProfile::query()->create([
                        'user_id' => $user->id,
                    ]);
                }
                // Note: 'agency' is not yet a registerable role (see
                // RegisterRequest) — agency_profiles doesn't exist
                // until this platform's Phase 3. 'admin' accounts
                // are never created through public registration.

                $token = $user
                    ->createToken('worker_app')
                    ->plainTextToken;

                return [
                    'user' => $user,
                    'token' => $token,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Registration successful.',
                'token' => $result['token'],
                'user' => $result['user'],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to register the account.',
            ], 500);
        }
    }

    /**
     * Log in a user.
     */
    #[OA\Post(
        path: '/api/login',
        tags: ['Auth'],
        summary: 'Log in with phone and password',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['phone', 'password'],
                properties: [
                    new OA\Property(property: 'phone', type: 'string', example: '+256700000000'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                        new OA\Property(property: 'reactivated', type: 'boolean'),
                        new OA\Property(property: 'restored_deletion', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Invalid phone number or password'),
            new OA\Response(response: 403, description: 'Account deactivated or deleted'),
        ]
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('phone', $request->phone)
            ->first();

        if (
            $user === null
            || !Hash::check($request->password, $user->password)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid phone number or password.',
            ], 401);
        }

        $reactivated = false;
        $restoredDeletion = false;

        $accountStatus =
            $user->account_status ?? 'active';

        /*
        |--------------------------------------------------------------------------
        | Self-service reactivation / deletion recovery
        |--------------------------------------------------------------------------
        |
        | Correct credentials reactivate accounts paused by the user.
        | Logging in during the 30-day deletion grace period also cancels
        | deletion and restores the account.
        |
        */

        if (
            in_array(
                $accountStatus,
                ['deactivated', 'pending_deletion'],
                true
            )
            && $user->account_status_source === 'user'
        ) {
            $wasPendingDeletion =
                $accountStatus === 'pending_deletion';

            $reactivated = true;
            $restoredDeletion = $wasPendingDeletion;

            $user->forceFill([
                'account_status' => 'active',
                'account_status_source' => null,
                'account_status_reason' => null,
                'account_status_changed_at' => now(),
                'account_status_changed_by' => null,
                'deletion_requested_at' => null,
                'deletion_scheduled_for' => null,
            ])->save();

            if (
                $user->role === 'worker'
                && $user->workerProfile !== null
            ) {
                $user->workerProfile()->update([
                    'active' => true,
                ]);
            }

            $accountStatus = 'active';
        } elseif ($accountStatus === 'deactivated') {
            return response()->json([
                'success' => false,
                'message' => 'This account was deactivated by an administrator. Please contact support.',
                'account_status' => 'deactivated',
                'account_status_reason' =>
                    $user->account_status_reason,
            ], 403);
        }

        if ($accountStatus === 'deleted') {
            return response()->json([
                'success' => false,
                'message' => 'This account has been permanently deleted.',
                'account_status' => 'deleted',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Do not delete every existing token here
        |--------------------------------------------------------------------------
        |
        | This allows the same user to remain logged in on more than one device.
        | The user can later choose "Log out other devices" from Security.
        |
        */

        $token = $user
            ->createToken('worker_app')
            ->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'user' => $user,
            'reactivated' => $reactivated,
            'restored_deletion' => $restoredDeletion,
        ]);
    }

    /**
     * Return the authenticated user.
     */
    #[OA\Get(
        path: '/api/me',
        tags: ['Auth'],
        summary: 'Get the currently authenticated user',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Authenticated user',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'user', type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => $user,
        ]);
    }

    /**
     * Log out the current device only.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $currentToken = $user->currentAccessToken();

        if ($currentToken !== null) {
            $currentToken->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Change the authenticated user's password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'current_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],
        ]);

        if (
            !Hash::check(
                $validated['current_password'],
                $user->password
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The current password is incorrect.',
                'errors' => [
                    'current_password' => [
                        'The current password is incorrect.',
                    ],
                ],
            ], 422);
        }

        if (
            Hash::check(
                $validated['password'],
                $user->password
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The new password must be different from the current password.',
                'errors' => [
                    'password' => [
                        'Choose a password different from your current password.',
                    ],
                ],
            ], 422);
        }

        try {
            DB::transaction(function () use (
                $user,
                $validated
            ): void {
                $user->update([
                    'password' => Hash::make(
                        $validated['password']
                    ),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Keep the current device logged in
                |--------------------------------------------------------------------------
                |
                | Delete all other tokens after changing the password.
                |
                */

                $currentToken = $user->currentAccessToken();

                $user->tokens()
                    ->when(
                        $currentToken !== null,
                        fn ($query) => $query->where(
                            'id',
                            '!=',
                            $currentToken->id
                        )
                    )
                    ->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully. Other devices have been logged out.',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to change the password.',
            ], 500);
        }
    }

    /**
     * Log out all other devices while keeping this device logged in.
     */
    public function logoutOtherDevices(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
            ],
        ]);

        if (
            !Hash::check(
                $validated['password'],
                $user->password
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The password is incorrect.',
                'errors' => [
                    'password' => [
                        'The password is incorrect.',
                    ],
                ],
            ], 422);
        }

        $currentToken = $user->currentAccessToken();

        $query = $user->tokens();

        if ($currentToken !== null) {
            $query->where(
                'id',
                '!=',
                $currentToken->id
            );
        }

        $deletedTokens = $query->count();

        $query->delete();

        return response()->json([
            'success' => true,
            'message' => 'All other devices have been logged out.',
            'logged_out_devices' => $deletedTokens,
        ]);
    }

    /**
     * Log out every device, including the current one.
     */
    public function logoutAllDevices(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
            ],
        ]);

        if (
            !Hash::check(
                $validated['password'],
                $user->password
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'The password is incorrect.',
                'errors' => [
                    'password' => [
                        'The password is incorrect.',
                    ],
                ],
            ], 422);
        }

        $deletedTokens = $user->tokens()->count();

        $user->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'You have been logged out from every device.',
            'logged_out_devices' => $deletedTokens,
        ]);
    }
}