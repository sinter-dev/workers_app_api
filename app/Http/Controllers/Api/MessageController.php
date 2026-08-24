<?php

namespace App\Http\Controllers\Api;

use App\Models\AppNotification;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Messages', description: 'Sending, reading, editing, and deleting messages within a conversation')]
class MessageController extends Controller
{
    /**
     * List messages in one conversation.
     */
    #[OA\Get(
        path: '/api/conversations/{conversation}/messages',
        tags: ['Messages'],
        summary: 'List messages in a conversation (paginated)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 30)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Messages',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'object')),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a participant in this conversation'),
        ]
    )]
    public function index(
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
                'message' => 'You cannot access these messages.',
            ], 403);
        }

        if ($conversation->status === 'blocked') {
            return response()->json([
                'success' => false,
                'message' => 'This conversation is blocked.',
            ], 403);
        }

        $validated = $request->validate([
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $perPage = $validated['per_page'] ?? 30;

        $messages = Message::query()
            ->with([
                'sender:id,full_name,role,profile_photo',
            ])
            ->where('conversation_id', $conversation->id)
            ->where(function ($query) use ($user) {
                $query
                    ->where(function ($senderQuery) use ($user) {
                        $senderQuery
                            ->where('sender_id', $user->id)
                            ->whereNull('deleted_for_sender_at');
                    })
                    ->orWhere(function ($receiverQuery) use ($user) {
                        $receiverQuery
                            ->where('sender_id', '!=', $user->id)
                            ->whereNull('deleted_for_receiver_at');
                    });
            })
            ->latest('id')
            ->paginate($perPage);

        $formattedMessages = collect($messages->items())
            ->reverse()
            ->map(
                fn (Message $message): array =>
                    $this->formatMessage($message, $user->id)
            )
            ->values();

        return response()->json([
            'success' => true,
            'conversation_id' => $conversation->id,
            'messages' => $formattedMessages,
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
                'has_more_pages' => $messages->hasMorePages(),
            ],
        ]);
    }

    /**
     * Send a new message.
     */
    #[OA\Post(
        path: '/api/conversations/{conversation}/messages',
        tags: ['Messages'],
        summary: 'Send a message (text and/or attachment, multipart/form-data)',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'message_type', type: 'string', enum: ['text', 'image', 'file'], nullable: true),
                        new OA\Property(property: 'message', type: 'string', nullable: true, maxLength: 5000, description: 'Required if no attachment'),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Message sent'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a participant in this conversation'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(
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
                'message' => 'You cannot send messages in this conversation.',
            ], 403);
        }

        if ($conversation->status === 'blocked') {
            return response()->json([
                'success' => false,
                'message' => 'This conversation is blocked.',
            ], 403);
        }

        $validated = $request->validate([
            'message_type' => [
                'nullable',
                'in:text,image,file',
            ],

            'message' => [
                'nullable',
                'string',
                'max:5000',
                'required_without:attachment',
            ],

            'attachment' => [
                'nullable',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,webp,pdf,doc,docx',
                'required_without:message',
            ],
        ]);

        $messageType = $validated['message_type'] ?? 'text';

        if ($request->hasFile('attachment')) {
            $mimeType = $request->file('attachment')->getMimeType();

            $messageType = str_starts_with(
                (string) $mimeType,
                'image/'
            )
                ? 'image'
                : 'file';
        }

        try {
            $result = DB::transaction(function () use (
                $request,
                $validated,
                $conversation,
                $user,
                $messageType
            ): array {
                $attachmentPath = null;
                $attachmentName = null;
                $attachmentMimeType = null;
                $attachmentSize = null;

                if ($request->hasFile('attachment')) {
                    $attachment = $request->file('attachment');

                    $attachmentPath = $attachment->store(
                        "chat/{$conversation->id}",
                        'public'
                    );

                    $attachmentName = $attachment->getClientOriginalName();
                    $attachmentMimeType = $attachment->getMimeType();
                    $attachmentSize = $attachment->getSize();
                }

                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'message_type' => $messageType,
                    'message' => $validated['message'] ?? null,
                    'attachment_path' => $attachmentPath,
                    'attachment_name' => $attachmentName,
                    'attachment_mime_type' => $attachmentMimeType,
                    'attachment_size' => $attachmentSize,
                    'delivered_at' => now(),
                    'read_at' => null,
                    'is_edited' => false,
                ]);

                $preview = $this->buildLastMessagePreview($message);

                $conversation->update([
                    'status' => 'active',
                    'last_message' => $preview,
                    'last_message_sender_id' => $user->id,
                    'last_message_at' => now(),
                    'homeowner_archived_at' => null,
                    'worker_archived_at' => null,
                ]);

                return [
                    'message' => $message->fresh([
                        'sender:id,full_name,role,profile_photo',
                    ]),
                    'conversation' => $conversation->fresh(),
                ];
            });

            $recipientId = $conversation->homeowner_id === $user->id
                ? $conversation->worker_id
                : $conversation->homeowner_id;
            $pushPreview = $this->buildLastMessagePreview(
                $result['message']
            );

            AppNotificationService::send(
                $recipientId,
                'new_message',
                'messages',
                'New message from ' . $user->full_name,
                $pushPreview,
                'conversation',
                $conversation->id,
                [
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'sender_name' => $user->full_name,
                    'message_id' => $result['message']->id,
                    'message_type' => $result['message']->message_type,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully.',
                'chat_message' => $this->formatMessage(
                    $result['message'],
                    $user->id
                ),
                'conversation' => $result['conversation'],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to send the message.',
            ], 500);
        }
    }

    /**
     * Mark all received messages in a conversation as read.
     */
    #[OA\Patch(
        path: '/api/conversations/{conversation}/read',
        tags: ['Messages'],
        summary: 'Mark all messages in a conversation as read',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'conversation', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Marked as read'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not a participant in this conversation'),
        ]
    )]
    public function markRead(
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
                'message' => 'You cannot update this conversation.',
            ], 403);
        }

        $updatedCount = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);

        AppNotification::query()
            ->where('user_id', $user->id)
            ->where('action_type', 'conversation')
            ->where('action_id', $conversation->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Messages marked as read.',
            'updated_messages' => $updatedCount,
        ]);
    }

    /**
     * Edit a text message.
     */
    #[OA\Put(
        path: '/api/messages/{message}',
        tags: ['Messages'],
        summary: 'Edit a text message the user sent',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['message'],
                properties: [
                    new OA\Property(property: 'message', type: 'string', maxLength: 5000),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Message updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the sender of this message'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function update(
        Request $request,
        Message $message
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($message->sender_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only edit your own messages.',
            ], 403);
        }

        $message->load('conversation');

        if (
            $message->conversation === null
            || !$message->conversation->isParticipant($user->id)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot edit this message.',
            ], 403);
        }

        if ($message->message_type !== 'text') {
            return response()->json([
                'success' => false,
                'message' => 'Only text messages can be edited.',
            ], 422);
        }

        if ($message->deleted_for_sender_at !== null) {
            return response()->json([
                'success' => false,
                'message' => 'Deleted messages cannot be edited.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        try {
            DB::transaction(function () use (
                $message,
                $validated
            ): void {
                $message->update([
                    'message' => $validated['message'],
                    'is_edited' => true,
                    'edited_at' => now(),
                ]);

                $conversation = $message->conversation;

                if (
                    $conversation !== null
                    && $conversation->last_message_sender_id ===
                        $message->sender_id
                    && $conversation->last_message_at?->equalTo(
                        $message->created_at
                    )
                ) {
                    $conversation->update([
                        'last_message' => $validated['message'],
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Message updated successfully.',
                'chat_message' => $this->formatMessage(
                    $message->fresh([
                        'sender:id,full_name,role,profile_photo',
                    ]),
                    $user->id
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update the message.',
            ], 500);
        }
    }

    /**
     * Delete a message for the logged-in user.
     */
    #[OA\Delete(
        path: '/api/messages/{message}',
        tags: ['Messages'],
        summary: 'Delete a message',
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'message', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Message deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Not the sender of this message'),
        ]
    )]
    public function destroy(
        Request $request,
        Message $message
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $message->load('conversation');

        $conversation = $message->conversation;

        if (
            $conversation === null
            || !$conversation->isParticipant($user->id)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete this message.',
            ], 403);
        }

        if ($message->sender_id === $user->id) {
            $message->update([
                'deleted_for_sender_at' => now(),
            ]);
        } else {
            $message->update([
                'deleted_for_receiver_at' => now(),
            ]);
        }

        $freshMessage = $message->fresh();

        if (
            $freshMessage->deleted_for_sender_at !== null
            && $freshMessage->deleted_for_receiver_at !== null
        ) {
            $this->deleteAttachment($freshMessage);
            $freshMessage->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully.',
        ]);
    }

    /**
     * Build a preview for the conversations list.
     */
    private function buildLastMessagePreview(
        Message $message
    ): string {
        if (
            $message->message !== null
            && trim($message->message) !== ''
        ) {
            return mb_strimwidth(
                trim($message->message),
                0,
                120,
                '...'
            );
        }

        return match ($message->message_type) {
            'image' => 'Image',
            'file' => $message->attachment_name !== null
                ? "File: {$message->attachment_name}"
                : 'File',
            default => 'New message',
        };
    }

    /**
     * Format one message for Flutter.
     */
    private function formatMessage(
        Message $message,
        int $currentUserId
    ): array {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,

            'is_mine' => $message->sender_id === $currentUserId,

            'message_type' => $message->message_type,
            'message' => $message->message,

            'attachment_path' => $message->attachment_path,
            'attachment_name' => $message->attachment_name,
            'attachment_mime_type' =>
                $message->attachment_mime_type,
            'attachment_size' => $message->attachment_size,

            'delivered_at' => $message->delivered_at,
            'read_at' => $message->read_at,

            'is_edited' => $message->is_edited,
            'edited_at' => $message->edited_at,

            'sender' => $message->sender === null
                ? null
                : [
                    'id' => $message->sender->id,
                    'full_name' => $message->sender->full_name,
                    'role' => $message->sender->role,
                    'profile_photo' =>
                        $message->sender->profile_photo,
                ],

            'created_at' => $message->created_at,
            'updated_at' => $message->updated_at,
        ];
    }

    /**
     * Delete the stored attachment if a message is fully removed.
     */
    private function deleteAttachment(Message $message): void
    {
        if (
            $message->attachment_path !== null
            && Storage::disk('public')->exists(
                $message->attachment_path
            )
        ) {
            Storage::disk('public')->delete(
                $message->attachment_path
            );
        }
    }
}