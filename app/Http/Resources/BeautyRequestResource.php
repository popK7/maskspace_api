<?php

namespace App\Http\Resources;

use App\Models\BeautyOffer;
use App\Models\BeautyRequest;
use App\Services\Reviews\ProviderReviews;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\BeautyRequest */
class BeautyRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $offers = $this->relationLoaded('offers') ? $this->offers : collect();
        if ($viewer && $viewer->id !== $this->client_id) {
            $offers = $offers->where('pro_id', $viewer->id)->values();
        }
        $ratings = $viewer && $viewer->id === $this->client_id && $offers->isNotEmpty()
            ? ProviderReviews::averages(BeautyRequest::class, 'pro_id', $offers->pluck('pro_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            : [];

        return [
            'id' => $this->id,
            'specialty' => $this->specialty->value,
            'mode' => $this->mode->value,
            'note' => $this->note,
            'status' => $this->status->value,
            'offers' => $offers->map(fn (BeautyOffer $offer) => [
                'id' => $offer->id,
                'message' => $offer->message,
                'status' => $offer->status->value,
                'pro' => $offer->relationLoaded('pro') && $offer->pro ? [
                    'id' => $offer->pro->id,
                    'name' => $offer->pro->name,
                    'rating' => $ratings[$offer->pro->id]['avg'] ?? null,
                    'rating_count' => $ratings[$offer->pro->id]['count'] ?? 0,
                ] : null,
            ])->values(),
            'client' => $this->when($this->relationLoaded('client') && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'pro' => $this->when($this->relationLoaded('pro') && $this->pro, fn () => [
                'id' => $this->pro->id,
                'name' => $this->pro->name,
                'phone' => in_array($this->status->value, ['assigned', 'completed'], true) ? $this->pro->phone : null,
            ]),
            'rating' => $this->rating,
            'review' => $this->review,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
