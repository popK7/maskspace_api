<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ChatMessage */
class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'sender_id' => $this->sender_id,
            'sender' => $this->when($this->relationLoaded('sender') && $this->sender, fn () => [
                'id' => $this->sender->id,
                'name' => $this->sender->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
