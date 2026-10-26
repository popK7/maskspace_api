<?php

namespace App\Models;

use App\Enums\HousingKind;
use App\Enums\HousingStatus;
use App\Enums\RentalBilling;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_id', 'demarcheur_id', 'kind', 'zone', 'zone_name', 'zone_city', 'zone_latitude', 'zone_longitude', 'billing', 'budget_cents', 'guests',
    'note', 'status', 'assigned_at', 'completed_at', 'cancelled_at', 'cancel_reason',
])]
class HousingRequest extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => HousingKind::class,
            'billing' => RentalBilling::class,
            'status' => HousingStatus::class,
            'budget_cents' => 'integer',
            'zone_latitude' => 'float',
            'zone_longitude' => 'float',
            'guests' => 'integer',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function demarcheur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demarcheur_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(HousingOffer::class);
    }
}
