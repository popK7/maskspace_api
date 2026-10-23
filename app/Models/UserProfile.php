<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['shop_name', 'vehicle_model', 'vehicle_plate', 'specialties', 'transfer_quotes'])]
class UserProfile extends Model
{
    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'transfer_quotes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
