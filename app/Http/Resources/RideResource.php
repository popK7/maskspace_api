<?php

namespace App\Http\Resources;

use App\Models\Ride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ride */
class RideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $revealed = $this->driver_id !== null
            && $viewer
            && in_array($viewer->id, [$this->passenger_id, $this->driver_id], true);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'job_id' => $this->job_id,
            'transfer_id' => $this->transfer_id,
            'price_cents' => $this->price_cents,
            'distance_km' => $this->distance_km,
            'eta_minutes' => $this->eta_minutes,
            'pickup' => [
                'address' => $revealed ? $this->pickup_address : null,
                'neighborhood' => $this->pickupNeighborhood(),
                'latitude' => $revealed ? $this->pickup_latitude : null,
                'longitude' => $revealed ? $this->pickup_longitude : null,
            ],
            'dropoff' => [
                'address' => $revealed ? $this->dropoff_address : null,
                'neighborhood' => $this->dropoffNeighborhood(),
                'latitude' => $revealed ? $this->dropoff_latitude : null,
                'longitude' => $revealed ? $this->dropoff_longitude : null,
            ],
            'passenger' => $this->when($this->relationLoaded('passenger') && $this->passenger, fn () => [
                'id' => $this->passenger->id,
                'name' => $this->passenger->name,
                'phone' => $revealed ? $this->passenger->phone : null,
                'role' => $this->passenger->role->value,
            ]),
            'driver' => $this->when($this->driver && $this->relationLoaded('driver'), fn () => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'phone' => $revealed ? $this->driver->phone : null,
                'vehicle' => $this->driver->profileForRole(
                    $this->driver->hasRole(\App\Enums\UserRole::Driver)
                        ? \App\Enums\UserRole::Driver
                        : \App\Enums\UserRole::Livreur,
                )->first()?->vehicle_model,
                'plate' => $revealed ? $this->driver->profileForRole(
                    $this->driver->hasRole(\App\Enums\UserRole::Driver)
                        ? \App\Enums\UserRole::Driver
                        : \App\Enums\UserRole::Livreur,
                )->first()?->vehicle_plate : null,
            ]),
            'offer' => $this->when(
                $this->status->value === 'searching' && $viewer && $viewer->id === $this->passenger_id,
                fn () => [
                    'exclusive' => $this->hasActiveOffer(),
                    'driver_id' => $this->offered_driver_id,
                    'driver_name' => $this->offeredDriver?->name,
                    'expires_at' => $this->offer_expires_at?->toIso8601String(),
                ],
            ),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'picked_up_at' => $this->picked_up_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function pickupNeighborhood(): ?string
    {
        return $this->shortPlace($this->pickup_address);
    }

    private function dropoffNeighborhood(): ?string
    {
        return $this->shortPlace($this->dropoff_address);
    }

    private function shortPlace(string $address): string
    {
        $parts = array_map('trim', explode(',', $address));

        return $parts[count($parts) > 1 ? 1 : 0] ?: $address;
    }
}
