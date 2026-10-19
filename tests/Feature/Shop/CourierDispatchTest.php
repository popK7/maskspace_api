<?php

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\ShopCourier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class CourierDispatchTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    /** Commande en livraison, en preparation, prete a etre attribuee. @return array{0: User, 1: string, 2: int} */
    private function preparingOrder(?User $merchant = null): array
    {
        $merchant ??= $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 20]);
        $client = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))
            ->assertCreated()->json('data.id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept")->assertOk();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare")->assertOk();

        return [$merchant, $m, $id];
    }

    private function trust(User $merchant, User $livreur): void
    {
        ShopCourier::create(['shop_id' => $merchant->shop->id, 'livreur_id' => $livreur->id]);
    }

    private function available(string $token): array
    {
        return $this->api($token)->getJson('/api/v1/livreur/deliveries/available')->assertOk()->json('data');
    }

    public function test_merchant_manages_trusted_couriers(): void
    {
        $merchant = $this->makeMerchant();
        $m = $this->tokenFor($merchant);
        $livreur = $this->makeLivreur();

        $this->api($m)->postJson('/api/v1/merchant/couriers', ['livreur_id' => $livreur->id])
            ->assertCreated()->assertJsonPath('data.saved', true)->assertJsonPath('data.name', $livreur->name);
        // Idempotent
        $this->api($m)->postJson('/api/v1/merchant/couriers', ['livreur_id' => $livreur->id])->assertCreated();
        $this->assertSame(1, ShopCourier::count());

        $this->api($m)->getJson('/api/v1/merchant/couriers')->assertOk()->assertJsonCount(1, 'data');

        // Seuls les livreurs actifs peuvent etre enregistres
        $this->api($m)->postJson('/api/v1/merchant/couriers', ['livreur_id' => $this->makeClient()->id])->assertUnprocessable();

        $this->api($m)->deleteJson("/api/v1/merchant/couriers/{$livreur->id}")->assertNoContent();
        $this->api($m)->deleteJson("/api/v1/merchant/couriers/{$livreur->id}")->assertNoContent();
        $this->assertSame(0, ShopCourier::count());

        // Reserve au role boutique
        $this->api($this->tokenFor($livreur))->getJson('/api/v1/merchant/couriers')->assertForbidden();
        $this->api($this->tokenFor($this->makeClient()))->getJson('/api/v1/merchant/couriers')->assertForbidden();
    }

    public function test_search_finds_livreurs_only_and_masks_phone(): void
    {
        $m = $this->tokenFor($this->makeMerchant());
        $livreur = User::factory()->role(\App\Enums\UserRole::Livreur)->withProfile()->create(['name' => 'Moussa Diallo', 'phone' => '+212612345678']);
        User::factory()->create(['name' => 'Moussa Client']);

        $this->api($m)->getJson('/api/v1/merchant/couriers/search?q=Mo')->assertUnprocessable();

        $res = $this->api($m)->getJson('/api/v1/merchant/couriers/search?q=Moussa')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($livreur->id, $res->json('data.0.id'));
        $this->assertStringNotContainsString('612345678', (string) $res->json('data.0.phone'));
        $this->assertStringEndsWith('78', (string) $res->json('data.0.phone'));
        $this->assertFalse($res->json('data.0.saved'));

        $this->api($m)->getJson('/api/v1/merchant/couriers/search?q='.$livreur->email)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_offer_is_exclusive_to_the_chosen_courier(): void
    {
        [$merchant, $m, $id] = $this->preparingOrder();
        $chosen = $this->makeLivreur();
        $other = $this->makeLivreur();
        $this->trust($merchant, $chosen);
        $c = $this->tokenFor($chosen);
        $o = $this->tokenFor($other);

        // Avant : visible de tous
        $this->assertCount(1, $this->available($o));

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier', 'livreur_id' => $chosen->id])
            ->assertOk()
            ->assertJsonPath('data.dispatch.state', 'offered')
            ->assertJsonPath('data.dispatch.offered_livreur.id', $chosen->id);

        // Les autres ne la voient plus et ne peuvent pas la prendre
        $this->assertCount(0, $this->available($o));
        $this->api($o)->postJson("/api/v1/livreur/deliveries/{$id}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'delivery_unavailable');

        // Le livreur choisi la voit, marquee "pour moi", et la prend
        $rows = $this->available($c);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['reserved_for_me']);
        $this->assertNotNull($rows[0]['offer_expires_at']);
        $this->api($c)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();

        $order = Order::find($id);
        $this->assertSame($chosen->id, $order->livreur_id);
        $this->assertNull($order->offered_livreur_id);
        $this->api($m)->getJson("/api/v1/merchant/orders/{$id}")->assertJsonPath('data.dispatch.state', 'assigned');
    }

    public function test_cannot_offer_to_a_courier_who_is_not_trusted(): void
    {
        [, $m, $id] = $this->preparingOrder();
        $stranger = $this->makeLivreur();

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier', 'livreur_id' => $stranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors('livreur_id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier'])
            ->assertUnprocessable()->assertJsonValidationErrors('livreur_id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'nope'])->assertUnprocessable();
    }

    public function test_expired_offer_becomes_visible_to_everyone_again(): void
    {
        [$merchant, $m, $id] = $this->preparingOrder();
        $chosen = $this->makeLivreur();
        $other = $this->makeLivreur();
        $this->trust($merchant, $chosen);

        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier', 'livreur_id' => $chosen->id])->assertOk();
        $this->assertCount(0, $this->available($this->tokenFor($other)));

        Order::where('id', $id)->update(['offer_expires_at' => now()->subMinute()]);

        $this->assertCount(1, $this->available($this->tokenFor($other)));
        $this->api($m)->getJson("/api/v1/merchant/orders/{$id}")->assertJsonPath('data.dispatch.state', 'expired');
        $this->api($this->tokenFor($other))->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();
    }

    public function test_courier_can_decline_and_merchant_can_reopen_the_search(): void
    {
        [$merchant, $m, $id] = $this->preparingOrder();
        $chosen = $this->makeLivreur();
        $other = $this->makeLivreur();
        $this->trust($merchant, $chosen);
        $c = $this->tokenFor($chosen);
        $o = $this->tokenFor($other);

        // Decline
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier', 'livreur_id' => $chosen->id])->assertOk();
        $this->api($o)->postJson("/api/v1/livreur/deliveries/{$id}/decline")->assertStatus(409);
        $this->api($c)->postJson("/api/v1/livreur/deliveries/{$id}/decline")->assertOk();
        $this->assertCount(1, $this->available($o));
        $this->api($m)->getJson("/api/v1/merchant/orders/{$id}")->assertJsonPath('data.dispatch.state', 'open');

        // Re-propose puis recherche ouverte
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'courier', 'livreur_id' => $chosen->id])->assertOk();
        $this->assertCount(0, $this->available($o));
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'open'])
            ->assertOk()->assertJsonPath('data.dispatch.state', 'open')->assertJsonPath('data.dispatch.offered_livreur', null);
        $this->assertCount(1, $this->available($o));
    }

    public function test_dispatch_is_guarded_by_ownership_state_and_fulfillment(): void
    {
        [$merchant, $m, $id] = $this->preparingOrder();
        $chosen = $this->makeLivreur();
        $this->trust($merchant, $chosen);

        // Une autre boutique ne peut pas toucher a cette commande
        $intruder = $this->tokenFor($this->makeMerchant());
        $this->api($intruder)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'open'])->assertNotFound();

        // Course deja prise : plus de changement possible
        $this->api($this->tokenFor($chosen))->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'open'])
            ->assertStatus(409)->assertJsonPath('code', 'delivery_taken');

        // Commande en retrait : pas de livreur
        $merchant2 = $this->makeMerchant();
        $product = $this->makeProduct($merchant2->shop);
        $client = $this->tokenFor($this->makeClient());
        $pickup = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant2->shop, $product, 1, [
            'fulfillment_type' => 'pickup', 'delivery_address' => null,
        ]))->assertCreated()->json('data.id');
        $m2 = $this->tokenFor($merchant2);
        $this->api($m2)->postJson("/api/v1/merchant/orders/{$pickup}/accept")->assertOk();
        $this->api($m2)->postJson("/api/v1/merchant/orders/{$pickup}/prepare")->assertOk();
        $this->api($m2)->postJson("/api/v1/merchant/orders/{$pickup}/dispatch", ['mode' => 'open'])->assertStatus(409);
    }

    public function test_dispatch_requires_preparing_or_ready_status(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $client = $this->tokenFor($this->makeClient());
        $m = $this->tokenFor($merchant);

        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product, 1))->assertCreated()->json('data.id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/dispatch", ['mode' => 'open'])->assertStatus(409);
    }

    public function test_recent_couriers_are_suggested_until_saved(): void
    {
        [$merchant, $m, $id] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);
        $pin = Order::find($id)->delivery_pin;

        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/ready")->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/pickup")->assertOk();
        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/deliver", ['pin' => $pin])->assertOk();

        $res = $this->api($m)->getJson('/api/v1/merchant/couriers')->assertOk();
        $res->assertJsonCount(0, 'data')->assertJsonCount(1, 'recent');
        $this->assertSame($livreur->id, $res->json('recent.0.id'));
        $this->assertSame(1, $res->json('recent.0.deliveries_for_shop'));

        $this->trust($merchant, $livreur);
        $this->api($m)->getJson('/api/v1/merchant/couriers')->assertJsonCount(1, 'data')->assertJsonCount(0, 'recent');
    }
}
