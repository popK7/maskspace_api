<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = $this->relationLoaded('images') ? $this->images : collect();
        $merchant = $request->routeIs('merchant.*');

        return [
            'id' => $this->id,
            'shop_id' => $this->shop_id,
            'name' => $this->name,
            'description' => $this->description,
            'origin' => $this->origin,
            'unit' => $this->unit,
            'category' => $this->category,
            'price_cents' => $this->price_cents,
            'compare_at_price_cents' => $this->compare_at_price_cents,
            'on_sale' => $this->compare_at_price_cents !== null && $this->compare_at_price_cents > $this->price_cents,
            'is_active' => $this->is_active,
            'is_featured' => $this->is_featured,
            'spice_level' => $this->spice_level,
            'is_vegetarian' => $this->is_vegetarian,
            'prep_minutes' => $this->prep_minutes,
            'sold_out' => $this->isSoldOut(),
            'available' => $this->isAvailable(),
            'images' => $images->map(fn ($i) => ['id' => $i->id, 'url' => $i->url(), 'thumb_url' => $i->thumbUrl()])->values(),
            'cover_url' => $images->first()?->url(),
            'cover_thumb_url' => $images->first()?->thumbUrl(),
            // Donnees de gestion du stock : visibles par le marchand uniquement.
            $this->mergeWhen($merchant, fn () => [
                'track_stock' => $this->track_stock,
                'stock_quantity' => $this->stock_quantity,
                'low_stock_threshold' => $this->low_stock_threshold,
                'is_low_stock' => $this->isLowStock(),
            ]),
        ];
    }
}
