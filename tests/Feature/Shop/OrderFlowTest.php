<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    private function placeOrder(string $clientToken, array $payload, ?string $key = null)
    {
        return $this->api($clientToken)->postJson('/api/v1/orders', $payload, $key ? ['Idempotency-Key' => $key] : []);
    }

    public function test_client_places_order_with_server_side_pricing_and_stock_decrements(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 2800, 'stock_quantity' => 10]);
        $token = $this->tokenFor($this->makeClient());

        $res = $this->placeOrder($token, $this->orderPayload($merchant->shop, $product, 3))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal_cents', 8400)
            ->assertJsonPath('data.delivery_fee_cents', 1800)
            ->assertJsonPath('data.total_cents', 10200)
            ->assertJsonPath('data.payment_method', 'cash');

        $this->assertMatchesRegularExpression('/^\d{4}$/', $res->json('data.delivery_pin'));
        $this->assertSame(7, $product->fresh()->stock_quantity);
    }

    public function test_client_cannot_inject_prices_and_unknown_items_are_rejected(): void
    {
        $merchant = $this->makeMerchant();
        $other = $this->makeMerchant();
        $foreign = $this->makeProduct($other->shop);
        $token = $this->tokenFor($this->makeClient());

        // Produit d'une autre boutique -> refuse
        $this->placeOrder($token, $this->orderPayload($merchant->shop, $foreign))->assertUnprocessable();

        // Un prix envoye par le client est ignore
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 1000]);
        $payload = $this->orderPayload($merchant->shop, $product, 1);
        $payload['items'][0]['price_cents'] = 1;
        $payload['total_cents'] = 1;
        $this->placeOrder($token, $payload)->assertCreated()->assertJsonPath('data.subtotal_cents', 1000);
    }

    public function test_insufficient_stock_is_refused_and_nothing_is_created(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 2]);
        $token = $this->tokenFor($this->makeClient());

        $this->placeOrder($token, $this->orderPayload($merchant->shop, $product, 3))
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame(0, Order::count());
        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_idempotency_key_prevents_duplicate_orders(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 10]);
        $token = $this->tokenFor($this->makeClient());
        $payload = $this->orderPayload($merchant->shop, $product, 2);

        $first = $this->placeOrder($token, $payload, 'abc-123')->assertCreated();
        $second = $this->placeOrder($token, $payload, 'abc-123')->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_closed_shop_and_unsupported_fulfillment_and_minimum_are_enforced(): void
    {
        $token = $this->tokenFor($this->makeClient());

        $closed = $this->makeMerchant(['is_open' => false]);
        $p1 = $this->makeProduct($closed->shop);
        $this->placeOrder($token, $this->orderPayload($closed->shop, $p1))
            ->assertStatus(409)->assertJsonPath('code', 'shop_closed');

        $pickupOnly = $this->makeMerchant(['supports_delivery' => false]);
        $p2 = $this->makeProduct($pickupOnly->shop);
        $this->placeOrder($token, $this->orderPayload($pickupOnly->shop, $p2))
            ->assertUnprocessable()->assertJsonPath('code', 'fulfillment_not_supported');

        $strict = $this->makeMerchant(['min_order_cents' => 10000]);
        $p3 = $this->makeProduct($strict->shop, ['price_cents' => 1000]);
        $this->placeOrder($token, $this->orderPayload($strict->shop, $p3, 2))
            ->assertUnprocessable()->assertJsonPath('code', 'min_order_not_reached');
    }

    public function test_delivery_outside_radius_is_refused_and_free_delivery_threshold_applies(): void
    {
        $merchant = $this->makeMerchant(['delivery_radius_km' => 5, 'free_delivery_threshold_cents' => 5000]);
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 3000, 'stock_quantity' => 50]);
        $token = $this->tokenFor($this->makeClient());

        // Marrakech : tres loin
        $this->placeOrder($token, $this->orderPayload($merchant->shop, $product, 1, [
            'delivery_latitude' => 31.6295, 'delivery_longitude' => -7.9811,
        ]))->assertUnprocessable()->assertJsonPath('code', 'out_of_delivery_zone');

        $this->placeOrder($token, $this->orderPayload($merchant->shop, $product, 2))
            ->assertCreated()->assertJsonPath('data.delivery_fee_cents', 0)->assertJsonPath('data.total_cents', 6000);
    }

    public function test_pickup_order_has_no_delivery_fee_and_needs_no_address(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 2000]);
        $token = $this->tokenFor($this->makeClient());

        $this->placeOrder($token, [
            'shop_id' => $merchant->shop->id, 'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.delivery_fee_cents', 0)->assertJsonPath('data.total_cents', 2000);
    }

    public function test_coupon_percent_applies_once_per_customer_and_quote_matches(): void
    {
        $merchant = $this->makeMerchant();
        $merchant->shop->coupons()->create(['code' => 'PROMO10', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 5000, 'stock_quantity' => 50]);
        $token = $this->tokenFor($this->makeClient());
        $payload = $this->orderPayload($merchant->shop, $product, 2, ['coupon_code' => 'promo10']);

        $this->api($token)->postJson('/api/v1/orders/quote', $payload)
            ->assertOk()->assertJsonPath('data.discount_cents', 1000)->assertJsonPath('data.total_cents', 10800);
        $this->assertSame(0, Order::count());

        $this->placeOrder($token, $payload)->assertCreated()->assertJsonPath('data.discount_cents', 1000);
        $this->assertSame(1, $merchant->shop->coupons()->first()->uses_count);

        $this->placeOrder($token, $payload)->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    }

    public function test_coupon_max_uses_and_unknown_codes_are_refused(): void
    {
        $merchant = $this->makeMerchant();
        $merchant->shop->coupons()->create(['code' => 'ONCE', 'type' => 'fixed', 'value' => 500, 'max_uses' => 1, 'is_active' => true]);
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 50]);

        $a = $this->tokenFor($this->makeClient());
        $b = $this->tokenFor($this->makeClient());

        $this->placeOrder($a, $this->orderPayload($merchant->shop, $product, 1, ['coupon_code' => 'ONCE']))->assertCreated();
        $this->placeOrder($b, $this->orderPayload($merchant->shop, $product, 1, ['coupon_code' => 'ONCE']))
            ->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
        $this->placeOrder($b, $this->orderPayload($merchant->shop, $product, 1, ['coupon_code' => 'NOPE']))
            ->assertUnprocessable()->assertJsonValidationErrors('coupon_code');
    }

    public function test_customer_cancels_pending_order_and_stock_and_coupon_are_restored(): void
    {
        $merchant = $this->makeMerchant();
        $merchant->shop->coupons()->create(['code' => 'C1', 'type' => 'fixed', 'value' => 100, 'is_active' => true]);
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 10]);
        $token = $this->tokenFor($this->makeClient());

        $id = $this->placeOrder($token, $this->orderPayload($merchant->shop, $product, 4, ['coupon_code' => 'C1']))
            ->assertCreated()->json('data.id');
        $this->assertSame(6, $product->fresh()->stock_quantity);

        $this->api($token)->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Erreur'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(0, $merchant->shop->coupons()->first()->uses_count);

        // Une commande annulee ne peut plus bouger
        $this->api($token)->postJson("/api/v1/orders/{$id}/cancel")->assertStatus(409);
    }

    public function test_customer_can_cancel_an_accepted_order(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $token = $this->tokenFor($this->makeClient());
        $id = $this->placeOrder($token, $this->orderPayload($merchant->shop, $product, 1))->json('data.id');

        $m = $this->tokenFor($merchant);
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept")->assertOk();

        $this->api($token)->postJson("/api/v1/orders/{$id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_orders_are_private_between_customers(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $owner = $this->tokenFor($this->makeClient());
        $stranger = $this->tokenFor($this->makeClient());
        $id = $this->placeOrder($owner, $this->orderPayload($merchant->shop, $product, 1))->json('data.id');

        $this->api($stranger)->getJson("/api/v1/orders/{$id}")->assertNotFound();
        $this->api($stranger)->postJson("/api/v1/orders/{$id}/cancel")->assertNotFound();
        $this->api($stranger)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->api($owner)->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonStructure(['data' => ['delivery_pin']]);
    }

    public function test_full_delivery_lifecycle_with_livreur_pin_and_review(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 4000, 'stock_quantity' => 10]);
        $client = $this->makeClient();
        $livreur = $this->makeLivreur();
        $c = $this->tokenFor($client);
        $m = $this->tokenFor($merchant);
        $l = $this->tokenFor($livreur);

        $created = $this->placeOrder($c, $this->orderPayload($merchant->shop, $product, 2))->assertCreated();
        $id = $created->json('data.id');
        $pin = $created->json('data.delivery_pin');

        // Le livreur ne voit rien tant que ce n'est pas pret a etre pris
        $this->api($l)->getJson('/api/v1/livreur/deliveries/available')->assertOk()->assertJsonCount(0, 'data');

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept", ['prep_minutes' => 15])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.prep_minutes', 15);
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare")->assertOk();

        // Course visible, sans donnees personnelles du client ni PIN
        $available = $this->api($l)->getJson('/api/v1/livreur/deliveries/available')->assertOk()->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('delivery_address', $available->json('data.0'));
        $this->assertArrayNotHasKey('delivery_pin', $available->json('data.0'));
        $this->assertSame(1800, $available->json('data.0.earning_cents'));

        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")
            ->assertOk()->assertJsonPath('data.customer.name', $client->name);

        // Un 2e livreur ne peut plus la prendre
        $other = $this->tokenFor($this->makeLivreur());
        $this->api($other)->postJson("/api/v1/livreur/deliveries/{$id}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'delivery_unavailable');
        $this->api($other)->getJson("/api/v1/livreur/deliveries/{$id}")->assertNotFound();

        // Recuperation impossible avant "pret"
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/pickup")->assertStatus(409);

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/ready")->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/pickup")
            ->assertOk()->assertJsonPath('data.status', 'out_for_delivery');

        // Mauvais PIN, puis bon PIN
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/deliver", ['pin' => $pin === '0000' ? '1111' : '0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/deliver", ['pin' => $pin])
            ->assertOk()->assertJsonPath('data.status', 'delivered')->assertJsonPath('data.payment_status', 'paid');

        // Le client suit la commande, voit le livreur, et la note
        $this->api($c)->getJson("/api/v1/orders/{$id}")->assertOk()
            ->assertJsonPath('data.livreur.name', $livreur->name)
            ->assertJsonCount(6, 'data.history');
        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 5, 'comment' => 'Parfait'])->assertCreated();
        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 1])->assertStatus(409);

        $shop = $merchant->shop->fresh();
        $this->assertEquals(5.0, $shop->rating_avg);
        $this->assertSame(1, $shop->rating_count);
        $this->assertSame(1, $shop->orders_count);

        $this->api($l)->getJson('/api/v1/livreur/earnings')->assertOk()
            ->assertJsonPath('data.deliveries_count', 1)->assertJsonPath('data.earnings_cents', 1800)
            ->assertJsonPath('data.cash_collected_cents', 9800);
    }

    public function test_pickup_order_is_completed_by_the_shop_with_the_pin_only(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $c = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $created = $this->placeOrder($c, [
            'shop_id' => $merchant->shop->id, 'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $id = $created->json('data.id');
        $pin = $created->json('data.delivery_pin');

        foreach (['accept', 'prepare', 'ready'] as $step) {
            $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/{$step}")->assertOk();
        }

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/complete", ['pin' => $pin === '0000' ? '1111' : '0000'])
            ->assertUnprocessable();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/complete", ['pin' => $pin])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
    }

    public function test_shop_cannot_complete_a_delivery_order_and_reject_requires_reason(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 5]);
        $c = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $id = $this->placeOrder($c, $this->orderPayload($merchant->shop, $product, 2))->json('data.id');

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare")->assertStatus(409);

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/reject", ['reason' => 'Rupture'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.rejection_reason', 'Rupture');
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_livreur_can_release_and_active_deliveries_are_capped(): void
    {
        config(['shop.livreur_max_active' => 1]);
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 20]);
        $c = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);
        $l = $this->tokenFor($this->makeLivreur());

        $ids = [];
        foreach ([1, 2] as $_) {
            $id = $this->placeOrder($c, $this->orderPayload($merchant->shop, $product, 1, ['coupon_code' => null]))->json('data.id');
            $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept");
            $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare");
            $ids[] = $id;
        }

        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$ids[0]}/accept")->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$ids[1]}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'too_many_active_deliveries');

        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$ids[0]}/release")->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$ids[1]}/accept")->assertOk();
    }

    public function test_role_boundaries(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $client = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);
        $l = $this->tokenFor($this->makeLivreur());

        $this->api($m)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))->assertForbidden();
        $this->api($l)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))->assertForbidden();
        $this->api($client)->getJson('/api/v1/merchant/shop')->assertForbidden();
        $this->api($client)->getJson('/api/v1/livreur/deliveries')->assertForbidden();
        $this->api($m)->getJson('/api/v1/livreur/deliveries')->assertForbidden();
        $this->api()->postJson('/api/v1/orders', [])->assertUnauthorized();
    }

    public function test_a_merchant_cannot_act_on_another_shops_order(): void
    {
        $a = $this->makeMerchant();
        $b = $this->makeMerchant();
        $product = $this->makeProduct($a->shop);
        $id = $this->placeOrder($this->tokenFor($this->makeClient()), $this->orderPayload($a->shop, $product, 1))->json('data.id');

        $tokenB = $this->tokenFor($b);
        $this->api($tokenB)->getJson("/api/v1/merchant/orders/{$id}")->assertNotFound();
        $this->api($tokenB)->postJson("/api/v1/merchant/orders/{$id}/accept")->assertNotFound();
        $this->assertSame('pending', Order::find($id)->status->value);
    }

    public function test_concurrent_style_overselling_is_impossible(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 3]);
        $a = $this->tokenFor($this->makeClient());
        $b = $this->tokenFor($this->makeClient());

        $this->placeOrder($a, $this->orderPayload($merchant->shop, $product, 2))->assertCreated();
        $this->placeOrder($b, $this->orderPayload($merchant->shop, $product, 2))->assertStatus(409);

        $this->assertSame(1, Product::find($product->id)->stock_quantity);
    }
}
