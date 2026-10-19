<?php

namespace App\Models;

use App\Enums\RideStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'passenger_id', 'driver_id', 'offered_driver_id', 'job_id', 'transfer_id', 'status',
    'pickup_address', 'pickup_latitude', 'pickup_longitude',
    'dropoff_address', 'dropoff_latitude', 'dropoff_longitude',
    'distance_km', 'price_cents', 'eta_minutes', 'cancel_reason',
    'accepted_at', 'picked_up_at', 'completed_at', 'cancelled_at', 'offer_expires_at',
])]
class Ride extends Model
{
    protected function casts(): array
    {
        return [
            'status' => RideStatus::class,
            'pickup_latitude' => 'float',
            'pickup_longitude' => 'float',
            'dropoff_latitude' => 'float',
            'dropoff_longitude' => 'float',
            'distance_km' => 'float',
            'price_cents' => 'integer',
            'eta_minutes' => 'integer',
            'accepted_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'offer_expires_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function offeredDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_driver_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(MoneyTransfer::class, 'transfer_id');
    }

    /** Offre exclusive encore valable : seuls le chauffeur vise la voit. */
    public function hasActiveOffer(): bool
    {
        return $this->status === RideStatus::Searching
            && $this->offered_driver_id !== null
            && $this->offer_expires_at !== null
            && $this->offer_expires_at->isFuture();
    }
}
