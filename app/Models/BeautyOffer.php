<?php

namespace App\Models;

use App\Enums\JobOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['beauty_request_id', 'pro_id', 'message', 'status'])]
class BeautyOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JobOfferStatus::class,
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(BeautyRequest::class, 'beauty_request_id');
    }

    public function pro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pro_id');
    }
}
