<?php

namespace App\Http\Resources;

use App\Enums\JobOfferStatus;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Job */
class JobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $asClient = $viewer && $viewer->id === $this->client_id;
        $asArtisan = $viewer && $viewer->id === $this->artisan_id;
        $assigned = $this->artisan_id !== null;

        return [
            'id' => $this->id,
            'trade' => $this->trade,
            'status' => $this->status->value,
            'note' => $this->note,
            'address' => $this->address,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'eta_minutes' => $this->eta_minutes,
            'final_price_dh' => $this->final_price_cents === null
                ? null
                : intdiv($this->final_price_cents, 100),
            'final_price_status' => $this->final_price_status?->value ?? 'not_submitted',
            'rating' => $this->rating,
            'review' => $this->review,
            'cancelled_by' => $this->cancelled_by,
            'ride_id' => $this->ride_id,
            'client' => $this->when($this->relationLoaded('client') && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $assigned || $asArtisan ? $this->client->phone : null,
            ]),
            'artisan' => $this->when($this->artisan && $this->relationLoaded('artisan'), fn () => [
                'id' => $this->artisan->id,
                'name' => $this->artisan->name,
                'phone' => $asClient ? $this->artisan->phone : null,
                'specialties' => $this->artisan->profile?->specialties ?? [],
            ]),
            'offers' => $this->when($asClient && $this->relationLoaded('offers'), fn () => $this->offers
                ->filter(fn ($o) => $o->status === JobOfferStatus::Pending || $o->status === JobOfferStatus::Chosen)
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'status' => $o->status->value,
                    'eta_minutes' => $o->eta_minutes,
                    'estimated_price_dh' => $o->estimated_price_cents === null
                        ? null
                        : intdiv($o->estimated_price_cents, 100),
                    'distance_km' => $o->distance_km,
                    'artisan' => [
                        'id' => $o->artisan?->id,
                        'name' => $o->artisan?->name,
                        'specialties' => $o->artisan?->profile?->specialties ?? [],
                    ],
                ])->values()),
            'ride' => $this->when($this->relationLoaded('ride') && $this->ride, fn () => new RideResource($this->ride)),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'en_route_at' => $this->en_route_at?->toIso8601String(),
            'arrived_at' => $this->arrived_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
