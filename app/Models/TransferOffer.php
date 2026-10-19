<?php

namespace App\Models;

use App\Enums\JobOfferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['transfer_id', 'changeur_id', 'status', 'eta_minutes', 'distance_km'])]
class TransferOffer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => JobOfferStatus::class,
            'eta_minutes' => 'integer',
            'distance_km' => 'float',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(MoneyTransfer::class, 'transfer_id');
    }

    public function changeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changeur_id');
    }
}
