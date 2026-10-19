<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationSubject;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChatMessageResource;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $chat) {}

    /** GET /chats?subject_type=order&subject_id=12 — fils visibles pour cette demande. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $d = $request->validate([
            'subject_type' => ['required', Rule::enum(ConversationSubject::class)],
            'subject_id' => ['required', 'integer', 'min:1'],
        ]);

        $threads = $this->chat->list(
            $request->user(),
            ConversationSubject::from($d['subject_type']),
            (int) $d['subject_id'],
        );

        return ConversationResource::collection($threads);
    }

    /** GET /chats/{conversation} — historique (200 derniers messages). */
    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        return new ConversationResource($this->chat->show($request->user(), $conversation));
    }

    /** POST /chats/{conversation}/messages */
    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $d = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $message = $this->chat->post($request->user(), $conversation, $d['body']);

        return (new ChatMessageResource($message))->response()->setStatusCode(201);
    }
}
