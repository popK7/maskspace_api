<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'shop_name', 'vehicle_model', 'vehicle_plate', 'specialties', 'housing_zones', 'transfer_quotes'])]
class UserProfile extends Model
{
    protected static function booted(): void
    {
        static::creating(function (UserProfile $profile): void {
            if ($profile->role === null) {
                $profile->role = User::query()->find($profile->user_id)?->role->value;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'housing_zones' => 'array',
            'transfer_quotes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
