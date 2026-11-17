<?php

namespace App\Http\Resources;

use App\Models\Consultation;
use App\Models\ConsultationOffer;
use App\Services\Reviews\ProviderReviews;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Consultation */
class ConsultationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $offers = $this->relationLoaded('offers') ? $this->offers : collect();
        if ($viewer && $viewer->id !== $this->client_id) {
            $offers = $offers->where('medecin_id', $viewer->id)->values();
        }
        $ratings = $viewer && $viewer->id === $this->client_id && $offers->isNotEmpty()
            ? ProviderReviews::averages(Consultation::class, 'medecin_id', $offers->pluck('medecin_id')->map(fn ($id) => (int) $id)->unique()->values()->all())
            : [];

        return [
            'id' => $this->id,
            'specialty' => $this->specialty->value,
            'mode' => $this->mode->value,
            'region' => $this->region,
            'note' => $this->note,
            'price_dh' => $this->price_dh,
            'price_status' => $this->price_status ?? 'not_submitted',
            'status' => $this->status->value,
            'offers' => $offers->map(fn (ConsultationOffer $offer) => [
                'id' => $offer->id,
                'message' => $offer->message,
                'status' => $offer->status->value,
                'medecin' => $offer->relationLoaded('medecin') && $offer->medecin ? [
                    'id' => $offer->medecin->id,
                    'name' => $offer->medecin->name,
                    'rating' => $ratings[$offer->medecin->id]['avg'] ?? null,
                    'rating_count' => $ratings[$offer->medecin->id]['count'] ?? 0,
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
            'rating' => $this->rating,
            'review' => $this->review,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
