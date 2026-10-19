<?php

namespace App\Models;

use App\Enums\CouponType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code', 'type', 'value', 'min_order_cents', 'max_uses', 'starts_at', 'expires_at', 'is_active'])]
class Coupon extends Model
{
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Remise en centimes pour un sous-total donne (jamais superieure au sous-total). */
    public function discountFor(int $subtotalCents): int
    {
        $discount = $this->type === CouponType::Percent
            ? intdiv($subtotalCents * min($this->value, 100), 100)
            : $this->value;

        return min($discount, $subtotalCents);
    }
}
