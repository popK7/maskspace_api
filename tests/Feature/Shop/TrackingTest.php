<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    // Boutique : 33.5724, -7.6611 — client : 33.58, -7.62
    private const FAR = ['latitude' => 33.62, 'longitude' => -7.58];

    private const AT_SHOP = ['latitude' => 33.5724, 'longitude' => -7.6611];

    private const AT_HOME = ['latitude' => 33.58, 'longitude' => -7.62];

    /** @return array{0: string, 1: string, 2: int, 3: string, 4: User} merchantToken, clientToken, orderId, livreurToken, livreur */
    private function preparingOrder(): array
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 20]);
        $client = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))->assertCreated()->json('data.id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept")->assertOk();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare")->assertOk();

        $livreur = $this->makeLivreur();

        return [$m, $client, $id, $this->tokenFor($livreur), $livreur];
    }

    public function test_client_sees_a_searching_phase_until_a_courier_takes_the_order(): void
    {
        [, $client, $id] = $this->preparingOrder();

        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertOk()
            ->assertJsonPath('data.phase', 'searching')
            ->assertJsonPath('data.livreur', null)
            ->assertJsonPath('data.courier', null)
            ->assertJsonPath('data.eta', null)
            ->assertJsonPath('data.shop.latitude', 33.5724)
            ->assertJsonPath('data.destination.latitude', 33.58);
    }

    public function test_client_follows_the_courier_from_shop_pickup_to_the_door(): void
    {
        [$m, $client, $id, $l, $livreur] = $this->preparingOrder();
        $pin = Order::find($id)->delivery_pin;

        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();

        // Vers la boutique
        $this->api($l)->postJson('/api/v1/livreur/location', self::FAR + ['heading' => 200, 'speed_kmh' => 30])
            ->assertOk()->assertJsonPath('data.recorded', true);

        $to = $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertOk()
            ->assertJsonPath('data.phase', 'to_shop')
            ->assertJsonPath('data.livreur.id', $livreur->id)
            ->assertJsonPath('data.courier.latitude', 33.62)
            ->assertJsonPath('data.courier.stale', false)
            ->assertJsonPath('data.eta.to', 'shop');
        $eta = $to->json('data.eta');
        $this->assertGreaterThan(0, $eta['minutes']);
        $this->assertGreaterThan($eta['minutes'], $eta['total_minutes']);
        $this->assertNotEmpty($to->json('data.livreur.name'));

        // Sur place
        $this->api($l)->postJson('/api/v1/livreur/location', self::AT_SHOP)->assertOk();
        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertJsonPath('data.phase', 'at_shop');

        // Recuperee : en route vers le client
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/ready")->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/pickup")->assertOk();
        $this->api($l)->postJson('/api/v1/livreur/location', ['latitude' => 33.575, 'longitude' => -7.64])->assertOk();

        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertOk()
            ->assertJsonPath('data.phase', 'to_customer')
            ->assertJsonPath('data.eta.to', 'customer')
            ->assertJsonPath('data.eta.total_minutes', null);

        // A la porte
        $this->api($l)->postJson('/api/v1/livreur/location', self::AT_HOME)->assertOk();
        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")
            ->assertJsonPath('data.phase', 'arrived')->assertJsonPath('data.eta', null);

        // Livree : plus de suivi
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/deliver", ['pin' => $pin])->assertOk();
        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertOk()
            ->assertJsonPath('data.phase', 'done')->assertJsonPath('data.courier', null);
    }

    public function test_position_is_ignored_without_an_active_delivery(): void
    {
        $l = $this->tokenFor($this->makeLivreur());

        $this->api($l)->postJson('/api/v1/livreur/location', self::FAR)
            ->assertOk()->assertJsonPath('data.recorded', false);
    }

    public function test_a_stale_position_is_flagged_and_gives_no_eta(): void
    {
        [, $client, $id, $l] = $this->preparingOrder();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();
        $this->api($l)->postJson('/api/v1/livreur/location', self::FAR)->assertOk();

        Carbon::setTestNow(now()->addMinutes(3));

        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertOk()
            ->assertJsonPath('data.courier.stale', true)
            ->assertJsonPath('data.eta', null)
            ->assertJsonPath('data.phase', 'to_shop');

        Carbon::setTestNow();
    }

    public function test_only_the_orders_customer_can_track_and_only_livreurs_can_publish(): void
    {
        [, $client, $id, $l] = $this->preparingOrder();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();

        $stranger = $this->tokenFor($this->makeClient());
        $this->api($stranger)->getJson("/api/v1/orders/{$id}/tracking")->assertNotFound();
        $this->api($l)->getJson("/api/v1/orders/{$id}/tracking")->assertForbidden();
        $this->api($client)->postJson('/api/v1/livreur/location', self::FAR)->assertForbidden();
    }

    public function test_location_payload_is_validated(): void
    {
        $l = $this->tokenFor($this->makeLivreur());

        $this->api($l)->postJson('/api/v1/livreur/location', ['latitude' => 120, 'longitude' => 0])->assertUnprocessable();
        $this->api($l)->postJson('/api/v1/livreur/location', ['latitude' => 10])->assertUnprocessable();
        $this->api($l)->postJson('/api/v1/livreur/location', ['latitude' => 10, 'longitude' => 5, 'heading' => 400])->assertUnprocessable();
    }

    public function test_pickup_orders_have_a_pickup_phase_without_courier(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $client = $this->tokenFor($this->makeClient());

        $id = $this->api($client)->postJson('/api/v1/orders', [
            'shop_id' => $merchant->shop->id, 'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->api($client)->getJson("/api/v1/orders/{$id}/tracking")->assertOk()
            ->assertJsonPath('data.phase', 'pickup')->assertJsonPath('data.is_delivery', false);
    }
}
