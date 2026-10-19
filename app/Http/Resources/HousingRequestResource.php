<?php

namespace App\Http\Resources;

use App\Models\HousingOffer;
use App\Models\HousingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin HousingRequest */
class HousingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $offers = $this->relationLoaded('offers') ? $this->offers : collect();
        if ($viewer && $viewer->id !== $this->client_id) {
            $offers = $offers->where('demarcheur_id', $viewer->id)->values();
        }

        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'zone' => $this->zone->value,
            'billing' => $this->billing?->value ?? 'night',
            'budget_cents' => $this->budget_cents,
            'guests' => $this->guests,
            'note' => $this->note,
            'status' => $this->status->value,
            'offers' => $offers->map(fn (HousingOffer $offer) => [
                'id' => $offer->id,
                'message' => $offer->message,
                'status' => $offer->status->value,
                'demarcheur' => $offer->relationLoaded('demarcheur') && $offer->demarcheur ? [
                    'id' => $offer->demarcheur->id,
                    'name' => $offer->demarcheur->name,
                ] : null,
            ])->values(),
            'client' => $this->when($this->relationLoaded('client') && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'demarcheur' => $this->when($this->relationLoaded('demarcheur') && $this->demarcheur, fn () => [
                'id' => $this->demarcheur->id,
                'name' => $this->demarcheur->name,
                'phone' => $this->status->value === 'assigned' || $this->status->value === 'completed'
                    ? $this->demarcheur->phone
                    : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
