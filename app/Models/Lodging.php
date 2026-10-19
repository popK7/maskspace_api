<?php

namespace App\Models;

use App\Enums\LodgingKind;
use App\Enums\RentalBilling;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'host_id', 'kind', 'title', 'description', 'address', 'neighborhood', 'city',
    'latitude', 'longitude', 'guests', 'bedrooms', 'beds', 'bathrooms',
    'price_cents', 'billing', 'amenities', 'is_active',
])]
class Lodging extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => LodgingKind::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'guests' => 'integer',
            'bedrooms' => 'integer',
            'beds' => 'integer',
            'bathrooms' => 'integer',
            'price_cents' => 'integer',
            'billing' => RentalBilling::class,
            'amenities' => 'array',
            'is_active' => 'boolean',
            'rating_avg' => 'float',
            'rating_count' => 'integer',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LodgingPhoto::class)->orderBy('position')->orderBy('id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(LodgingBooking::class);
    }

    public function cover(): ?LodgingPhoto
    {
        return $this->relationLoaded('photos') ? $this->photos->first() : $this->photos()->first();
    }
}
