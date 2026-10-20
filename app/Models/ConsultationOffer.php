<?php

namespace App\Models;

use App\Enums\JobOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['consultation_id', 'medecin_id', 'message', 'status'])]
class ConsultationOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JobOfferStatus::class,
        ];
    }

    public function consultation(): BelongsTo
    {
        return $this->belongsTo(Consultation::class);
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }
}
