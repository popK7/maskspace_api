<?php

namespace App\Http\Resources;

use App\Models\LodgingBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LodgingBooking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $revealed = $viewer && in_array($viewer->id, [$this->client_id, $this->host_id], true)
            && in_array($this->status->value, ['accepted', 'completed'], true);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'check_in' => $this->check_in?->toDateString(),
            'check_out' => $this->check_out?->toDateString(),
            'guests' => $this->guests,
            'nights' => $this->nights,
            'billing' => $this->billing?->value ?? 'night',
            'total_cents' => $this->total_cents,
            'note' => $this->note,
            'rating' => $this->rating,
            'review' => $this->review,
            'cancelled_by' => $this->cancelled_by,
            'lodging' => $this->when($this->relationLoaded('lodging') && $this->lodging, fn () => new LodgingResource($this->lodging)),
            'client' => $this->when($this->relationLoaded('client') && $this->client, fn () => [
                'id' => $this->client->id,
                'name' => $this->client->name,
                'phone' => $revealed ? $this->client->phone : null,
            ]),
            'host' => $this->when($this->relationLoaded('host') && $this->host, fn () => [
                'id' => $this->host->id,
                'name' => $this->host->name,
                'phone' => $revealed ? $this->host->phone : null,
            ]),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
