<?php

namespace App\Http\Resources;

use App\Models\ConsultationOffer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Consultation */
class ConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $offers = $this->relationLoaded('offers') ? $this->offers : collect();
        if ($viewer && $viewer->id !== $this->client_id) {
            $offers = $offers->where('medecin_id', $viewer->id)->values();
        }

        return [
            'id' => $this->id,
            'specialty' => $this->specialty->value,
            'mode' => $this->mode->value,
            'note' => $this->note,
            'status' => $this->status->value,
            'offers' => $offers->map(fn (ConsultationOffer $offer) => [
                'id' => $offer->id,
                'message' => $offer->message,
                'status' => $offer->status->value,
                'medecin' => $offer->relationLoaded('medecin') && $offer->medecin ? [
                    'id' => $offer->medecin->id,
                    'name' => $offer->medecin->name,
                ] : null,
            ])->values(),
            'client' => $this->when($this->relationLoaded('client') && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
            ]),
            'medecin' => $this->when($this->relationLoaded('medecin') && $this->medecin, fn () => [
                'id' => $this->medecin->id,
                'name' => $this->medecin->name,
                'phone' => in_array($this->status->value, ['assigned', 'completed'], true) ? $this->medecin->phone : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
