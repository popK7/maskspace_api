<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subject_type' => ConversationSubject::class,
            'channel' => ConversationChannel::class,
            'last_message_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class)->latestOfMany();
    }
}
