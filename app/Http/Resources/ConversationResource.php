<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Conversation */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type->value,
            'subject_id' => $this->subject_id,
            'channel' => $this->channel->value,
            'title' => $this->presented_title ?? 'Discussion',
            'peer' => $this->presented_peer,
            'can_write' => (bool) ($this->presented_can_write ?? true),
            'last_message' => $this->when(
                $this->relationLoaded('lastMessage') && $this->lastMessage,
                fn () => new ChatMessageResource($this->lastMessage),
            ),
            'messages' => $this->when(
                $this->relationLoaded('messages'),
                fn () => ChatMessageResource::collection($this->messages),
            ),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
        ];
    }
}
