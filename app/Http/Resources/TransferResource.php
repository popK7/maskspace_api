<?php

namespace App\Http\Resources;

use App\Enums\JobOfferStatus;
use App\Enums\TransferCurrency;
use App\Models\MoneyTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MoneyTransfer */
class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $asClient = $viewer && $viewer->id === $this->client_id;
        $asChangeur = $viewer && $viewer->id === $this->changeur_id;
        $assigned = $this->changeur_id !== null;

        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'currency' => $this->currency->value,
            'destination_currency' => TransferCurrency::destinationFor($this->destination_country),
            'destination_country' => $this->destination_country,
            'status' => $this->status->value,
            'note' => $this->note,
            'address' => $this->address,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'eta_minutes' => $this->eta_minutes,
            'rating' => $this->rating,
            'review' => $this->review,
            'cancelled_by' => $this->cancelled_by,
            'ride_id' => $this->ride_id,
            'client' => $this->when($this->relationLoaded('client') && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $assigned || $asChangeur ? $this->client->phone : null,
            ]),
            'changeur' => $this->when($this->changeur && $this->relationLoaded('changeur'), fn () => [
                'id' => $this->changeur->id,
                'name' => $this->changeur->name,
                'phone' => $asClient ? $this->changeur->phone : null,
                'specialties' => $this->changeur->profileForRole(\App\Enums\UserRole::Changeur)->first()?->specialties ?? [],
            ]),
            'offers' => $this->when($asClient && $this->relationLoaded('offers'), fn () => $this->offers
                ->filter(fn ($o) => $o->status === JobOfferStatus::Chosen
                    || ($o->status === JobOfferStatus::Pending
                        && $o->exchange_rate !== null
                        && $o->fee_amount !== null))
                ->map(fn ($o) => [
                    'id' => $o->id,
                    'status' => $o->status->value,
                    'eta_minutes' => $o->eta_minutes,
                    'exchange_rate' => $o->exchange_rate,
                    'fee_amount' => $o->fee_amount,
                    'recipient_currency' => TransferCurrency::destinationFor($this->destination_country),
                    'recipient_amount' => $o->exchange_rate !== null && $o->fee_amount !== null
                        ? (int) round(($this->amount - $o->fee_amount) * $o->exchange_rate)
                        : null,
                    'distance_km' => $o->distance_km,
                    'changeur' => [
                        'id' => $o->changeur?->id,
                        'name' => $o->changeur?->name,
                        'specialties' => $o->changeur?->profileForRole(\App\Enums\UserRole::Changeur)->first()?->specialties ?? [],
                    ],
                ])->values()),
            'ride' => $this->when($this->relationLoaded('ride') && $this->ride, fn () => new RideResource($this->ride)),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'meeting_at' => $this->meeting_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
