<?php

namespace App\Models;

use App\Enums\FulfillmentType;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded = [];

    protected $hidden = ['delivery_pin'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'fulfillment_type' => FulfillmentType::class,
            'delivery_latitude' => 'float',
            'delivery_longitude' => 'float',
            'commission_rate' => 'float',
            'accepted_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'offer_expires_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class)->withTrashed();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function livreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    /**
     * Courses qu'un livreur peut prendre : libres, et soit ouvertes a tous, soit reservees a lui,
     * soit dont la reservation a un autre livreur a expire.
     */
    public function scopeVisibleToLivreur(Builder $query, int $livreurId): Builder
    {
        return $query->whereNull('livreur_id')->where(function (Builder $q) use ($livreurId) {
            $q->whereNull('offered_livreur_id')
                ->orWhere('offered_livreur_id', $livreurId)
                ->orWhereNull('offer_expires_at')
                ->orWhere('offer_expires_at', '<=', now());
        });
    }

    public function offeredLivreur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_livreur_id');
    }

    /** Course reservee a un livreur habituel, et delai de reponse pas encore ecoule. */
    public function hasActiveOffer(): bool
    {
        return $this->livreur_id === null
            && $this->offered_livreur_id !== null
            && $this->offer_expires_at !== null
            && $this->offer_expires_at->isFuture();
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function isDelivery(): bool
    {
        return $this->fulfillment_type === FulfillmentType::Delivery;
    }
}
