<?php

namespace App\Http\Resources;

use App\Models\BeautyOffer;
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
