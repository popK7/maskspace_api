<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_client_can_favorite_and_unfavorite_shops_and_products(): void
    {
        $shop = $this->makeMerchant(['name' => 'Chez Awa'])->shop;
        $product = $this->makeProduct($shop, ['name' => 'Attiéké']);
        $token = $this->tokenFor($this->makeClient());

        $this->api($token)->putJson("/api/v1/favorites/shop/{$shop->id}")->assertNoContent();
        $this->api($token)->putJson("/api/v1/favorites/product/{$product->id}")->assertNoContent();
        // Idempotent : un second appel ne cree pas de doublon.
        $this->api($token)->putJson("/api/v1/favorites/product/{$product->id}")->assertNoContent();

        $this->api($token)->getJson('/api/v1/favorites/ids')->assertOk()
            ->assertExactJson(['shops' => [$shop->id], 'products' => [$product->id]]);

        $this->api($token)->getJson('/api/v1/favorites')->assertOk()
            ->assertJsonPath('shops.0.name', 'Chez Awa')
            ->assertJsonPath('products.0.name', 'Attiéké')
            ->assertJsonPath('products.0.shop.id', $shop->id);

        $this->api($token)->deleteJson("/api/v1/favorites/shop/{$shop->id}")->assertNoContent();
        $this->api($token)->deleteJson("/api/v1/favorites/shop/{$shop->id}")->assertNoContent();
        $this->api($token)->getJson('/api/v1/favorites/ids')->assertJsonPath('shops', []);
    }

    public function test_favorites_are_private_per_user(): void
    {
        $shop = $this->makeMerchant()->shop;
        $alice = $this->tokenFor($this->makeClient());
        $bob = $this->tokenFor($this->makeClient());

        $this->api($alice)->putJson("/api/v1/favorites/shop/{$shop->id}")->assertNoContent();

        $this->api($bob)->getJson('/api/v1/favorites/ids')->assertJsonPath('shops', []);
    }

    public function test_cannot_favorite_unknown_or_inactive_items(): void
    {
        $inactive = $this->makeMerchant(['is_active' => false])->shop;
        $token = $this->tokenFor($this->makeClient());

        $this->api($token)->putJson('/api/v1/favorites/shop/99999')->assertNotFound();
        $this->api($token)->putJson("/api/v1/favorites/shop/{$inactive->id}")->assertNotFound();
        $this->api($token)->putJson('/api/v1/favorites/other/1')->assertNotFound();
    }

    public function test_favorites_require_the_client_role(): void
    {
        $merchant = $this->makeMerchant();

        $this->api()->getJson('/api/v1/favorites')->assertUnauthorized();
        $this->api($this->tokenFor($merchant))->getJson('/api/v1/favorites')->assertForbidden();
    }

    public function test_deactivated_favorites_are_hidden_from_the_list(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $token = $this->tokenFor($this->makeClient());

        $this->api($token)->putJson("/api/v1/favorites/product/{$product->id}")->assertNoContent();
        $product->update(['is_active' => false]);

        $this->api($token)->getJson('/api/v1/favorites')->assertJsonPath('products', []);
    }
}
