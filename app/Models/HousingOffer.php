<?php

namespace App\Models;

use App\Enums\JobOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['housing_request_id', 'demarcheur_id', 'message', 'status'])]
class HousingOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JobOfferStatus::class,
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(HousingRequest::class, 'housing_request_id');
    }

    public function demarcheur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demarcheur_id');
    }
}
