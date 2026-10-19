<?php

namespace App\Services\Shop;

use App\Enums\FulfillmentType;
use App\Exceptions\ApiException;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Calcule un panier cote serveur : le client n'envoie QUE des identifiants et des quantites,
 * jamais un prix ni un total.
 */
class OrderPricing
{
    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @param  bool  $lock  verrouille les lignes produit/coupon (a utiliser dans une transaction de creation)
     * @return array{
     *   lines: Collection<int, array{product: Product, quantity: int, total: int}>,
     *   subtotal: int, discount: int, delivery_fee: int, total: int, coupon: ?Coupon, shop: Shop
     * }
     */
    public function compute(
        Shop $shop,
        array $items,
        FulfillmentType $type,
        ?string $couponCode,
        ?User $customer,
        ?float $lat,
        ?float $lng,
        bool $lock = false,
    ): array {
        $this->assertShopCanSell($shop, $type);

        // Quantites cumulees par produit (le meme produit peut etre envoye 2 fois).
        $quantities = [];
        foreach ($items as $item) {
            $quantities[(int) $item['product_id']] = ($quantities[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }

        $query = Product::where('shop_id', $shop->id)->whereIn('id', array_keys($quantities))->orderBy('id');
        $products = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');

        $lines = collect();
        $subtotal = 0;
        foreach ($quantities as $productId => $qty) {
            $product = $products->get($productId);

            if ($product === null || ! $product->is_active) {
                throw ValidationException::withMessages(['items' => ['Un produit du panier n\'est plus disponible dans cette boutique.']]);
            }

            if ($product->isSoldOut()) {
                throw new ApiException(
                    "« {$product->name} » est épuisé pour aujourd'hui. Retirez-le du panier.",
                    409,
                    'product_sold_out',
                );
            }

            if ($product->track_stock && $product->stock_quantity < $qty) {
                throw new ApiException(
                    "Stock insuffisant pour « {$product->name} » (restant : {$product->stock_quantity}).",
                    409,
                    'insufficient_stock',
                );
            }

            $total = $product->price_cents * $qty;
            $subtotal += $total;
            $lines->push(['product' => $product, 'quantity' => $qty, 'total' => $total]);
        }

        if ($subtotal < $shop->min_order_cents) {
            throw new ApiException(
                'Commande minimum : '.number_format($shop->min_order_cents / 100, 2, ',', ' ').' DH.',
                422,
                'min_order_not_reached',
            );
        }

        $coupon = $couponCode ? $this->resolveCoupon($shop, $couponCode, $subtotal, $customer, $lock) : null;
        $discount = $coupon?->discountFor($subtotal) ?? 0;

        $deliveryFee = 0;
        if ($type === FulfillmentType::Delivery) {
            $this->assertWithinRadius($shop, $lat, $lng);
            $deliveryFee = $shop->delivery_fee_cents;
            if ($shop->free_delivery_threshold_cents !== null && ($subtotal - $discount) >= $shop->free_delivery_threshold_cents) {
                $deliveryFee = 0;
            }
        }

        return [
            'shop' => $shop,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal - $discount + $deliveryFee,
            'coupon' => $coupon,
        ];
    }

    private function assertShopCanSell(Shop $shop, FulfillmentType $type): void
    {
        if (! $shop->isOpenNow()) {
            throw new ApiException(
                $shop->isRestaurant() ? 'Ce restaurant est fermé pour le moment.' : 'Cette boutique est fermée pour le moment.',
                409,
                'shop_closed',
            );
        }

        $supported = $type === FulfillmentType::Delivery ? $shop->supports_delivery : $shop->supports_pickup;
        if (! $supported) {
            throw new ApiException(
                $type === FulfillmentType::Delivery ? 'Cette boutique ne livre pas.' : 'Le retrait en boutique n\'est pas proposé.',
                422,
                'fulfillment_not_supported',
            );
        }
    }

    private function assertWithinRadius(Shop $shop, ?float $lat, ?float $lng): void
    {
        if ($shop->delivery_radius_km === null || $lat === null || $lng === null || ! $shop->hasLocation()) {
            return;
        }

        if ($shop->distanceKmTo($lat, $lng) > $shop->delivery_radius_km) {
            throw new ApiException(
                "Adresse hors de la zone de livraison ({$shop->delivery_radius_km} km).",
                422,
                'out_of_delivery_zone',
            );
        }
    }

    private function resolveCoupon(Shop $shop, string $code, int $subtotal, ?User $customer, bool $lock): Coupon
    {
        $query = Coupon::where('shop_id', $shop->id)->where('code', strtoupper(trim($code)));
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        $invalid = fn (string $why) => ValidationException::withMessages(['coupon_code' => [$why]]);

        if ($coupon === null || ! $coupon->is_active) {
            throw $invalid('Code promo invalide.');
        }
        if (($coupon->starts_at && $coupon->starts_at->isFuture()) || ($coupon->expires_at && $coupon->expires_at->isPast())) {
            throw $invalid('Ce code promo a expiré ou n\'est pas encore valide.');
        }
        if ($coupon->max_uses !== null && $coupon->uses_count >= $coupon->max_uses) {
            throw $invalid('Ce code promo a atteint sa limite d\'utilisation.');
        }
        if ($subtotal < $coupon->min_order_cents) {
            throw $invalid('Montant minimum non atteint pour ce code : '.number_format($coupon->min_order_cents / 100, 2, ',', ' ').' DH.');
        }
        if ($customer && Order::where('customer_id', $customer->id)->where('coupon_id', $coupon->id)
            ->whereNotIn('status', ['rejected', 'cancelled'])->exists()) {
            throw $invalid('Vous avez déjà utilisé ce code promo.');
        }

        return $coupon;
    }
}
