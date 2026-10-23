<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ride_id', 'driver_id', 'status', 'price_cents', 'eta_minutes', 'distance_to_pickup_km'])]
class RideOffer extends Model
{
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'eta_minutes' => 'integer',
            'distance_to_pickup_km' => 'float',
        ];
    }

    public function ride(): BelongsTo
    {
        return $this->belongsTo(Ride::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }
}
