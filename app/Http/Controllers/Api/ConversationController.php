<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Conversations', description: 'Direct-message conversation threads between workers and homeowners')]
class ConversationController extends Controller
{
    /**
     * Return conversations belonging to the logged-in user.
     */
    #[OA\Get(
        path: '/api/conversations',
        tags: ['Conversations'],
        summary: 'List the authenticated user\'s conversations',
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Conversations',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'conversations', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $conversations = Conversation::query()
            ->with([
                'homeowner:id,full_name,phone,email,role,profile_photo,location,is_verified',
                'worker:id,full_name,phone,email,role,profile_photo,location,is_verified',
                'job:id,title,category,status,homeowner_id,accepted_worker_id',
            ])
            ->where(function ($query) use ($user) {
                $query
                    ->where('homeowner_id', $user->id)
                    ->orWhere('worker_id', $user->id);
            })
            ->where(function ($query) use ($user) {
                if ($user->role === 'homeowner') {
                    $query->whereNull('homeowner_archived_at');
                } else {
                    $query->whereNull('worker_archived_at');
                }
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get()
            ->map(function (Conversation $conversation) use ($user): array {
                return $this->formatConversation(
                    conversation: $conversation,
                    userId: $user->id,
                );
            })
            ->values();

        $totalUnread = $conversations->sum(
            fn (array $conversation): int =>
                (int) $conversation['unread_count']
        );

        return response()->json([
            'success' => true,
            'total_conversations' => $conversations->count(),
            'total_unread_messages' => $totalUnread,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Create or return an existing conversation.
     *
     * Supported conversation types:
     *
     * Direct conversation:
     * {
     *     "other_user_id": 2
     * }
     *
     * Job conversation:
     * {
     *     "job_id": 2
     * }
     */
    #[OA\Post(
        path: '/api/conversations',
        tags: ['Conversations'],
        summary: 'Start (or reuse) a conversation with a user or about a job',
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'other_user_id', type: 'integer', nullable: true, description: 'Required if job_id is omitted'),
                    new OA\Property(property: 'job_id', type: 'integer', nullable: true, description: 'Required if other_user_id is omitted'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Existing or newly created conversation'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error'),
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

        if (!in_array($user->role, [
            'homeowner',
            'worker',
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only homeowners and workers can create conversations.',
            ], 403);
        }

        $validated = $request->validate([
            'other_user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                'required_without:job_id',
            ],

            'job_id' => [
                'nullable',
                'integer',
                'exists:jobs,id',
                'required_without:other_user_id',
            ],
        ]);

        if (
            isset($validated['other_user_id'])
            && isset($validated['job_id'])
        ) {
            throw ValidationException::withMessages([
                'conversation' => [
                    'Send either other_user_id or job_id, not both.',
                ],
            ]);
        }

        try {
            $result = DB::transaction(function () use (
                $validated,
                $user
            ): array {
                if (isset($validated['job_id'])) {
                    return $this->createJobConversation(
                        user: $user,
                        jobId: (int) $validated['job_id'],
                    );
                }

                return $this->createDirectConversation(
                    user: $user,
                    otherUserId: (int) $validated['other_user_id'],
                );
            });

            $conversation = $result['conversation'];

            $conversation->load([
                'homeowner:id,full_name,phone,email,role,profile_photo,location,is_verified',
                'worker:id,full_name,phone,email,role,profile_photo,location,is_verified',
                'job:id,title,category,status,homeowner_id,accepted_worker_id',
            ]);

            return response()->json([
                'success' => true,
                'message' => $result['created']
                    ? 'Conversation created successfully.'
                    : 'Conversation already exists.',
                'created' => $result['created'],
                'conversation' => $this->formatConversation(
                    conversation: $conversation,
                    userId: $user->id,
                ),
            ], $result['created'] ? 201 : 200);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create the conversation.',
            ], 500);
        }
    }

    /**
     * Show one conversation.
     */
    #[OA\Get(
        path: '/api/conversations/{conversation}',
        tags: ['Conversations'],
        summary: 'View a single conversation',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Conversation details'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a participant in this conversation'),
        ]
    )]
    public function show(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$conversation->isParticipant($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot access this conversation.',
            ], 403);
        }

        if ($conversation->status === 'blocked') {
            return response()->json([
                'success' => false,
                'message' => 'This conversation is blocked.',
            ], 403);
        }

        $conversation->load([
            'homeowner:id,full_name,phone,email,role,profile_photo,location,is_verified',
            'worker:id,full_name,phone,email,role,profile_photo,location,is_verified',
            'job:id,title,category,status,homeowner_id,accepted_worker_id',
        ]);

        return response()->json([
            'success' => true,
            'conversation' => $this->formatConversation(
                conversation: $conversation,
                userId: $user->id,
            ),
        ]);
    }

    /**
     * Archive a conversation for the logged-in participant.
     *
     * Archiving only hides the conversation for that user.
     * It does not delete messages for the other participant.
     */
    #[OA\Patch(
        path: '/api/conversations/{conversation}/archive',
        tags: ['Conversations'],
        summary: 'Archive a conversation (hides it for this user only)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Archived'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a participant in this conversation'),
        ]
    )]
    public function archive(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$conversation->isParticipant($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot archive this conversation.',
            ], 403);
        }

        if ($user->id === $conversation->homeowner_id) {
            $conversation->update([
                'homeowner_archived_at' => now(),
            ]);
        } else {
            $conversation->update([
                'worker_archived_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation archived successfully.',
        ]);
    }

    /**
     * Restore a conversation archived by the logged-in participant.
     */
    #[OA\Patch(
        path: '/api/conversations/{conversation}/restore',
        tags: ['Conversations'],
        summary: 'Restore (un-archive) a conversation',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Restored'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a participant in this conversation'),
        ]
    )]
    public function restore(
        Request $request,
        Conversation $conversation
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$conversation->isParticipant($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot restore this conversation.',
            ], 403);
        }

        if ($user->id === $conversation->homeowner_id) {
            $conversation->update([
                'homeowner_archived_at' => null,
            ]);
        } else {
            $conversation->update([
                'worker_archived_at' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Conversation restored successfully.',
            'conversation' => $conversation->fresh(),
        ]);
    }

    /**
     * Create a direct conversation between a homeowner and worker.
     */
    private function createDirectConversation(
        User $user,
        int $otherUserId
    ): array {
        if ($user->id === $otherUserId) {
            throw ValidationException::withMessages([
                'other_user_id' => [
                    'You cannot create a conversation with yourself.',
                ],
            ]);
        }

        $otherUser = User::query()->findOrFail($otherUserId);

        $roles = [
            $user->role,
            $otherUser->role,
        ];

        sort($roles);

        if ($roles !== [
            'homeowner',
            'worker',
        ]) {
            throw ValidationException::withMessages([
                'other_user_id' => [
                    'A conversation must be between one homeowner and one worker.',
                ],
            ]);
        }

        $homeownerId = $user->role === 'homeowner'
            ? $user->id
            : $otherUser->id;

        $workerId = $user->role === 'worker'
            ? $user->id
            : $otherUser->id;

        $conversationKey = sprintf(
            'direct:%d:%d',
            $homeownerId,
            $workerId
        );

        $conversation = Conversation::query()
            ->where('conversation_key', $conversationKey)
            ->first();

        if ($conversation !== null) {
            $this->restoreForCurrentUser(
                conversation: $conversation,
                user: $user,
            );

            return [
                'created' => false,
                'conversation' => $conversation->fresh(),
            ];
        }

        $conversation = Conversation::query()->create([
            'conversation_key' => $conversationKey,
            'homeowner_id' => $homeownerId,
            'worker_id' => $workerId,
            'job_id' => null,
            'status' => 'active',
        ]);

        return [
            'created' => true,
            'conversation' => $conversation,
        ];
    }

    /**
     * Create a conversation connected to an accepted job.
     */
    private function createJobConversation(
        User $user,
        int $jobId
    ): array {
        $job = Job::query()
            ->lockForUpdate()
            ->findOrFail($jobId);

        if ($job->accepted_worker_id === null) {
            throw ValidationException::withMessages([
                'job_id' => [
                    'This job does not have an accepted worker.',
                ],
            ]);
        }

        $isHomeowner = $user->id === $job->homeowner_id;
        $isWorker = $user->id === $job->accepted_worker_id;

        if (!$isHomeowner && !$isWorker) {
            throw ValidationException::withMessages([
                'job_id' => [
                    'You are not a participant in this job.',
                ],
            ]);
        }

        if (in_array($job->status, [
            'open',
            'cancelled',
        ], true)) {
            throw ValidationException::withMessages([
                'job_id' => [
                    'Chat is not available for this job status.',
                ],
            ]);
        }

        $conversationKey = sprintf(
            'job:%d',
            $job->id
        );

        $conversation = Conversation::query()
            ->where('conversation_key', $conversationKey)
            ->first();

        if ($conversation !== null) {
            $this->restoreForCurrentUser(
                conversation: $conversation,
                user: $user,
            );

            return [
                'created' => false,
                'conversation' => $conversation->fresh(),
            ];
        }

        $conversation = Conversation::query()->create([
            'conversation_key' => $conversationKey,
            'homeowner_id' => $job->homeowner_id,
            'worker_id' => $job->accepted_worker_id,
            'job_id' => $job->id,
            'status' => 'active',
        ]);

        return [
            'created' => true,
            'conversation' => $conversation,
        ];
    }

    /**
     * Restore an archived conversation when a participant opens it again.
     */
    private function restoreForCurrentUser(
        Conversation $conversation,
        User $user
    ): void {
        if ($user->id === $conversation->homeowner_id) {
            $conversation->update([
                'homeowner_archived_at' => null,
            ]);

            return;
        }

        if ($user->id === $conversation->worker_id) {
            $conversation->update([
                'worker_archived_at' => null,
            ]);
        }
    }

    /**
     * Format conversation data for Flutter.
     */
    private function formatConversation(
        Conversation $conversation,
        int $userId
    ): array {
        $otherParticipant = $conversation->otherParticipant($userId);

        $unreadCount = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();

        return [
            'id' => $conversation->id,
            'conversation_key' => $conversation->conversation_key,
            'status' => $conversation->status,

            'job_id' => $conversation->job_id,

            'job' => $conversation->job === null
                ? null
                : [
                    'id' => $conversation->job->id,
                    'title' => $conversation->job->title,
                    'category' => $conversation->job->category,
                    'status' => $conversation->job->status,
                ],

            'other_participant' => $otherParticipant === null
                ? null
                : [
                    'id' => $otherParticipant->id,
                    'full_name' => $otherParticipant->full_name,
                    'phone' => $otherParticipant->phone,
                    'email' => $otherParticipant->email,
                    'role' => $otherParticipant->role,
                    'profile_photo' => $otherParticipant->profile_photo,
                    'location' => $otherParticipant->location,
                    'is_verified' => $otherParticipant->is_verified,
                ],

            'last_message' => $conversation->last_message,
            'last_message_sender_id' =>
                $conversation->last_message_sender_id,
            'last_message_at' => $conversation->last_message_at,

            'unread_count' => $unreadCount,

            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ];
    }
}