<?php

namespace Tests\Concerns;

use App\Enums\ShopKind;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Str;

trait InteractsWithShop
{
    protected function makeMerchant(array $shop = []): User
    {
        $user = User::factory()->role(UserRole::Boutique)->create();
        $user->shop()->save(new Shop(array_merge([
            'name' => 'Boutique '.Str::random(5),
            'categories' => ['epicerie'],
            'city' => 'Casablanca',
            'latitude' => 33.5724,
            'longitude' => -7.6611,
            'delivery_fee_cents' => 1800,
            'min_order_cents' => 0,
            'prep_minutes' => 20,
        ], $shop)));

        return $user->load('shop');
    }

    protected function makeRestaurant(array $shop = []): User
    {
        $user = User::factory()->role(UserRole::Restaurant)->create();
        $user->shop()->save(new Shop(array_merge([
            'kind' => ShopKind::Restaurant,
            'name' => 'Resto '.Str::random(5),
            'categories' => ['ivoirienne'],
            'city' => 'Casablanca',
            'latitude' => 33.5724,
            'longitude' => -7.6611,
            'delivery_fee_cents' => 1500,
            'min_order_cents' => 0,
            'prep_minutes' => 25,
        ], $shop)));

        return $user->load('shop');
    }

    protected function makeDish(Shop $shop, array $attrs = []): Product
    {
        return $this->makeProduct($shop, array_merge([
            'name' => 'Plat '.Str::random(4),
            'category' => 'plat',
            'track_stock' => false,
            'stock_quantity' => 0,
        ], $attrs));
    }

    protected function makeProduct(Shop $shop, array $attrs = []): Product
    {
        return $shop->products()->create(array_merge([
            'name' => 'Produit '.Str::random(4),
            'category' => 'epicerie',
            'price_cents' => 2500,
            'track_stock' => true,
            'stock_quantity' => 10,
        ], $attrs));
    }

    protected function makeClient(): User
    {
        return User::factory()->create();
    }

    protected function makeLivreur(): User
    {
        return User::factory()->role(UserRole::Livreur)->withProfile()->create();
    }

    /** Jeton d'acces pour un utilisateur (sans passer par /login). */
    protected function tokenFor(User $user): string
    {
        return $this->loginAs($user)['tokens']['access_token'];
    }

    /** @return array<string, mixed> corps de commande valide */
    protected function orderPayload(Shop $shop, Product $product, int $qty = 2, array $overrides = []): array
    {
        return array_merge([
            'shop_id' => $shop->id,
            'fulfillment_type' => 'delivery',
            'delivery_address' => '12 rue des Fleurs, Casablanca',
            'delivery_latitude' => 33.58,
            'delivery_longitude' => -7.62,
            'items' => [['product_id' => $product->id, 'quantity' => $qty]],
        ], $overrides);
    }
}
