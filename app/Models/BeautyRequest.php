<?php

namespace App\Models;

use App\Enums\BeautyMode;
use App\Enums\BeautySpecialty;
use App\Enums\BeautyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'pro_id', 'specialty', 'mode', 'latitude', 'longitude', 'note', 'status',
    'assigned_at', 'completed_at', 'cancelled_at', 'cancel_reason',
])]
class BeautyRequest extends Model
{
    protected function casts(): array
    {
        return [
            'specialty' => BeautySpecialty::class,
            'mode' => BeautyMode::class,
            'latitude' => 'float',
            'longitude' => 'float',
            'status' => BeautyStatus::class,
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'rating' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pro(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pro_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(BeautyOffer::class);
    }
}
