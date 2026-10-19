<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name', 'description', 'origin', 'unit', 'category', 'price_cents', 'compare_at_price_cents',
    'track_stock', 'stock_quantity', 'low_stock_threshold', 'is_active',
    'is_featured', 'spice_level', 'is_vegetarian', 'prep_minutes',
])]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'price_cents' => 'integer',
            'compare_at_price_cents' => 'integer',
            'stock_quantity' => 'integer',
            'is_featured' => 'boolean',
            'is_vegetarian' => 'boolean',
            'spice_level' => 'integer',
            'prep_minutes' => 'integer',
            'sold_out_until' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position')->orderBy('id');
    }

    public function isAvailable(): bool
    {
        return $this->is_active && ! $this->isSoldOut() && (! $this->track_stock || $this->stock_quantity > 0);
    }

    /** Epuise aujourd'hui : se leve automatiquement a l'heure indiquee (minuit). */
    public function isSoldOut(): bool
    {
        return $this->sold_out_until !== null && $this->sold_out_until->isFuture();
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock_quantity <= $this->low_stock_threshold;
    }
}
