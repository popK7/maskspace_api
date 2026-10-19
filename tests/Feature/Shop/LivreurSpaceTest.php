<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\ShopCourier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class LivreurSpaceTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    /** Commande en preparation. @return array{0: User, 1: string, 2: int, 3: string} merchant, merchantToken, orderId, clientToken */
    private function preparingOrder(?User $merchant = null): array
    {
        $merchant ??= $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 20]);
        $client = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))->assertCreated()->json('data.id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept")->assertOk();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare")->assertOk();

        return [$merchant, $m, $id, $client];
    }

    /** Course livree de bout en bout par $livreurToken. */
    private function deliver(string $merchantToken, string $livreurToken, int $id): void
    {
        $pin = Order::find($id)->delivery_pin;
        $this->api($livreurToken)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();
        $this->api($merchantToken)->postJson("/api/v1/merchant/orders/{$id}/ready")->assertOk();
        $this->api($livreurToken)->postJson("/api/v1/livreur/deliveries/{$id}/pickup")->assertOk();
        $this->api($livreurToken)->postJson("/api/v1/livreur/deliveries/{$id}/deliver", ['pin' => $pin])->assertOk();
    }

    public function test_livreur_can_go_off_duty_and_loses_access_to_deliveries(): void
    {
        [, , $id] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);

        $this->api($l)->getJson('/api/v1/livreur/deliveries/available')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.is_available', true);

        $this->api($l)->patchJson('/api/v1/livreur/availability', ['is_available' => false])
            ->assertOk()->assertJsonPath('data.is_available', false);

        $this->api($l)->getJson('/api/v1/livreur/deliveries/available')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.is_available', false);
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'livreur_unavailable');

        $this->api($l)->patchJson('/api/v1/livreur/availability', ['is_available' => true])->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();

        $this->api($l)->patchJson('/api/v1/livreur/availability', [])->assertUnprocessable();
        $this->api($this->tokenFor($this->makeClient()))->patchJson('/api/v1/livreur/availability', ['is_available' => true])->assertForbidden();
    }

    public function test_cannot_offer_a_course_to_an_off_duty_courier(): void
    {
        [$merchant, $m, $id] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        ShopCourier::create(['shop_id' => $merchant->shop->id, 'livreur_id' => $livreur->id]);
        $livreur->forceFill(['is_available' => false])->save();

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier', 'livreur_id' => $livreur->id])
            ->assertUnprocessable()->assertJsonValidationErrors('livreur_id');

        $this->api($m)->getJson('/api/v1/merchant/couriers')->assertJsonPath('data.0.is_available', false);
    }

    public function test_dashboard_summarises_the_day_and_the_pipeline(): void
    {
        $merchant = $this->makeMerchant();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);

        $this->api($l)->getJson('/api/v1/livreur/dashboard')->assertOk()
            ->assertJsonPath('data.today.deliveries', 0)
            ->assertJsonPath('data.rating.count', 0)
            ->assertJsonPath('data.available_count', 0);

        [, $m, $id] = $this->preparingOrder($merchant);
        $this->api($l)->getJson('/api/v1/livreur/dashboard')->assertJsonPath('data.available_count', 1);

        $this->deliver($m, $l, $id);

        $this->api($l)->getJson('/api/v1/livreur/dashboard')->assertOk()
            ->assertJsonPath('data.today.deliveries', 1)
            ->assertJsonPath('data.today.earnings_cents', 1800)
            ->assertJsonPath('data.lifetime.deliveries', 1)
            ->assertJsonPath('data.active_count', 0)
            ->assertJsonPath('data.is_available', true);

        $this->api($this->tokenFor($this->makeClient()))->getJson('/api/v1/livreur/dashboard')->assertForbidden();
    }

    public function test_earnings_report_has_daily_series_average_and_best_day(): void
    {
        [, $m, $id] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);
        $this->deliver($m, $l, $id);

        $res = $this->api($l)->getJson('/api/v1/livreur/earnings?from='.now()->subDays(6)->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.deliveries_count', 1)
            ->assertJsonPath('data.earnings_cents', 1800)
            ->assertJsonPath('data.average_cents', 1800)
            ->assertJsonPath('data.lifetime_deliveries', 1)
            ->assertJsonCount(7, 'data.daily');

        $this->assertSame(now()->toDateString(), $res->json('data.best_day.date'));
        $this->assertSame(0, $res->json('data.daily.0.deliveries'));
        $this->assertSame(1, $res->json('data.daily.6.deliveries'));
    }

    public function test_customer_can_rate_the_courier_and_it_feeds_the_courier_rating(): void
    {
        [, $m, $id, $c] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);
        $this->deliver($m, $l, $id);

        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 4, 'courier_rating' => 5, 'comment' => 'Top'])
            ->assertCreated()->assertJsonPath('data.courier_rating', 5);

        $this->api($l)->getJson('/api/v1/livreur/dashboard')
            ->assertJsonPath('data.rating.avg', 5)->assertJsonPath('data.rating.count', 1);
        $this->api($c)->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.review.courier_rating', 5);

        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 4, 'courier_rating' => 9])->assertUnprocessable();
    }

    public function test_courier_rating_is_ignored_for_pickup_orders(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $c = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $id = $this->api($c)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product, 1, [
            'fulfillment_type' => 'pickup', 'delivery_address' => null,
        ]))->assertCreated()->json('data.id');
        $pin = Order::find($id)->delivery_pin;
        foreach (['accept', 'prepare', 'ready'] as $action) {
            $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/{$action}")->assertOk();
        }
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/complete", ['pin' => $pin])->assertOk();

        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 5, 'courier_rating' => 1])
            ->assertCreated()->assertJsonPath('data.courier_rating', null);
    }

    public function test_courier_rating_is_visible_to_merchants_when_choosing_a_courier(): void
    {
        [$merchant, $m, $id, $c] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);
        $this->deliver($m, $l, $id);
        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 5, 'courier_rating' => 4])->assertCreated();

        ShopCourier::create(['shop_id' => $merchant->shop->id, 'livreur_id' => $livreur->id]);

        $this->api($m)->getJson('/api/v1/merchant/couriers')
            ->assertJsonPath('data.0.rating.avg', 4)->assertJsonPath('data.0.rating.count', 1)
            ->assertJsonPath('data.0.vehicle', 'Toyota Corolla');
    }

    public function test_available_courses_expose_trip_length_without_revealing_the_address(): void
    {
        [, , $id] = $this->preparingOrder();
        $l = $this->tokenFor($this->makeLivreur());

        $rows = $this->api($l)->getJson('/api/v1/livreur/deliveries/available')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, $rows[0]['trip_km']);
        $this->assertArrayNotHasKey('delivery_address', $rows[0]);
        $this->assertSame($id, $rows[0]['id']);
    }
}
