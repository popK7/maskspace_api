<?php

namespace App\Http\Resources;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Shop */
class ShopResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'kind' => $this->kind->value,
            'description' => $this->description,
            'categories' => $this->categories ?? [],
            'logo_url' => $this->logoUrl(),
            'cover_url' => $this->coverUrl(),
            'logo_thumb_url' => $this->logoThumbUrl(),
            'cover_thumb_url' => $this->coverThumbUrl(),
            'phone' => $this->phone,
            'address' => $this->address,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => $this->is_active,
            'is_open' => $this->is_open,
            'is_open_now' => $this->isOpenNow(),
            'next_opening_label' => $this->nextOpeningLabel(),
            'force_open_until' => $this->force_open_until?->toIso8601String(),
            'opening_hours' => $this->opening_hours,
            'supports_delivery' => $this->supports_delivery,
            'supports_pickup' => $this->supports_pickup,
            'delivery_fee_cents' => $this->delivery_fee_cents,
            'free_delivery_threshold_cents' => $this->free_delivery_threshold_cents,
            'min_order_cents' => $this->min_order_cents,
            'delivery_radius_km' => $this->delivery_radius_km,
            'prep_minutes' => $this->prep_minutes,
            // Délai annoncé au client : délai habituel + mode rush éventuel.
            'prep_minutes_effective' => $this->effectivePrepMinutes(),
            'rush_extra_minutes' => $this->activeRushMinutes(),
            'rush_until' => $this->activeRushMinutes() > 0 ? $this->rush_until?->toIso8601String() : null,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'orders_count' => $this->orders_count,
            // Restaurants : le plat du jour, affiché sur la carte de la liste.
            'featured_dish' => $this->whenLoaded('featuredDish', fn () => $this->featuredDish ? [
                'id' => $this->featuredDish->id,
                'name' => $this->featuredDish->name,
                'price_cents' => $this->featuredDish->price_cents,
                'thumb_url' => $this->featuredDish->relationLoaded('images')
                    ? $this->featuredDish->images->first()?->thumbUrl() : null,
            ] : null),
            'owner_name' => $this->whenLoaded('owner', fn () => $this->owner->name),
            // Present uniquement pour une recherche geolocalisee.
            'distance_km' => $this->when($this->distance_km !== null, fn () => round($this->distance_km, 2)),
        ];
    }
}
