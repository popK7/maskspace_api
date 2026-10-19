<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isCustomer = $viewer && $viewer->id === $this->customer_id;
        $isShop = $this->relationLoaded('shop') && $viewer && $this->shop->owner_id === $viewer->id;
        $isLivreur = $viewer && $this->livreur_id === $viewer->id;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'fulfillment_type' => $this->fulfillment_type->value,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'subtotal_cents' => $this->subtotal_cents,
            'discount_cents' => $this->discount_cents,
            'delivery_fee_cents' => $this->delivery_fee_cents,
            'total_cents' => $this->total_cents,
            'coupon_code' => $this->coupon_code,
            'delivery_address' => $this->delivery_address,
            'delivery_latitude' => $this->delivery_latitude,
            'delivery_longitude' => $this->delivery_longitude,
            'customer_note' => $this->customer_note,
            'rejection_reason' => $this->rejection_reason,
            'cancellation_reason' => $this->cancellation_reason,
            'prep_minutes' => $this->prep_minutes,
            // Le code de remise n'est connu que du client : il le donne au livreur / a la boutique.
            'delivery_pin' => $this->when($isCustomer, fn () => $this->makeVisible('delivery_pin')->delivery_pin),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name,
                'unit' => $i->unit,
                'unit_price_cents' => $i->unit_price_cents,
                'quantity' => $i->quantity,
                'total_cents' => $i->total_cents,
            ])->values()),
            'shop' => $this->whenLoaded('shop', fn () => [
                'id' => $this->shop->id,
                'kind' => $this->shop->kind->value,
                'name' => $this->shop->name,
                'logo_url' => $this->shop->logoUrl(),
                'address' => $this->shop->address,
                'phone' => $this->shop->phone,
                'latitude' => $this->shop->latitude,
                'longitude' => $this->shop->longitude,
            ]),
            // Coordonnees du client : boutique et livreur assigne uniquement.
            'customer' => $this->when($isShop || $isLivreur, fn () => $this->customer ? [
                'name' => $this->customer->name,
                'phone' => $this->customer_phone,
            ] : null),
            'livreur' => $this->when($isCustomer || $isShop, fn () => $this->livreur ? [
                'name' => $this->livreur->name,
                'phone' => $this->livreur->phone,
            ] : null),
            // Recherche de livreur (vue boutique) : open | offered | expired | assigned.
            'dispatch' => $this->when($isShop && $this->isDelivery(), fn () => [
                'state' => match (true) {
                    $this->livreur_id !== null => 'assigned',
                    $this->hasActiveOffer() => 'offered',
                    $this->offered_livreur_id !== null => 'expired',
                    default => 'open',
                },
                'offered_livreur' => $this->offered_livreur_id && $this->livreur_id === null
                    ? ['id' => $this->offered_livreur_id, 'name' => $this->offeredLivreur?->name, 'phone' => $this->offeredLivreur?->phone]
                    : null,
                'offer_expires_at' => $this->offer_expires_at?->toIso8601String(),
            ]),
            'livreur_id' => $this->when($isShop, $this->livreur_id),
            'history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($h) => [
                'from' => $h->from_status?->value,
                'to' => $h->to_status->value,
                'note' => $h->note,
                'at' => $h->created_at?->toIso8601String(),
            ])->values()),
            'review' => $this->whenLoaded('review', fn () => $this->review ? [
                'rating' => $this->review->rating,
                'courier_rating' => $this->review->courier_rating,
                'comment' => $this->review->comment,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
        ];
    }
}
