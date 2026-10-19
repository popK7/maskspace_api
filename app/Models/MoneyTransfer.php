<?php

namespace App\Models;

use App\Enums\TransferCurrency;
use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'changeur_id', 'ride_id', 'amount', 'currency', 'destination_country',
    'status', 'note', 'address', 'neighborhood', 'city', 'latitude', 'longitude',
    'eta_minutes', 'rating', 'review', 'cancelled_by', 'cancel_reason',
    'assigned_at', 'meeting_at', 'completed_at', 'cancelled_at',
])]
class MoneyTransfer extends Model
{
    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'currency' => TransferCurrency::class,
            'amount' => 'integer',
            'latitude' => 'float',
            'longitude' => 'float',
            'eta_minutes' => 'integer',
            'rating' => 'integer',
            'assigned_at' => 'datetime',
            'meeting_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function changeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changeur_id');
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(TransferOffer::class, 'transfer_id');
    }

    public function rides(): HasMany
    {
        return $this->hasMany(Ride::class, 'transfer_id');
    }
}
