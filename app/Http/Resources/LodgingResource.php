<?php

namespace App\Http\Resources;

use App\Models\Lodging;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lodging */
class LodgingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $photos = $this->relationLoaded('photos') ? $this->photos : collect();
        $cover = $photos->first();

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'description' => $this->description,
            'address' => $this->address,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'guests' => $this->guests,
            'bedrooms' => $this->bedrooms,
            'beds' => $this->beds,
            'bathrooms' => $this->bathrooms,
            'price_cents' => $this->price_cents,
            'billing' => $this->billing?->value ?? 'night',
            'amenities' => $this->amenities ?? [],
            'is_active' => $this->is_active,
            'rating_avg' => $this->rating_avg,
            'rating_count' => $this->rating_count,
            'cover_url' => $cover?->url(),
            'cover_thumb_url' => $cover?->thumbUrl(),
            'photos' => $photos->map(fn ($p) => [
                'id' => $p->id,
                'url' => $p->url(),
                'thumb_url' => $p->thumbUrl(),
                'caption' => $p->caption,
            ])->values(),
            'host' => $this->when($this->relationLoaded('host') && $this->host, fn () => [
                'id' => $this->host->id,
                'name' => $this->host->name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
