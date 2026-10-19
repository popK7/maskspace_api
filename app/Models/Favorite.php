<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Favori d'un client : une boutique (`kind = shop`) ou un produit (`kind = product`). */
class Favorite extends Model
{
    public const KINDS = ['shop', 'product'];

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
