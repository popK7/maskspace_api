<?php

namespace Tests\Feature\Shop;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class RestaurantTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_restaurant_registration_creates_a_restaurant_with_its_cuisine(): void
    {
        $token = $this->registerActivated('restaurant');

        $this->api($token)->getJson('/api/v1/merchant/shop')->assertOk()
            ->assertJsonPath('data.kind', 'restaurant')
            ->assertJsonPath('data.name', 'Chez Tantie')
            ->assertJsonPath('data.categories.0', 'ivoirienne');
    }

    public function test_cuisines_and_grocery_aisles_are_not_interchangeable(): void
    {
        $resto = $this->tokenFor($this->makeRestaurant());
        $boutique = $this->tokenFor($this->makeMerchant());

        $this->api($resto)->patchJson('/api/v1/merchant/shop', ['categories' => ['senegalaise', 'camerounaise']])->assertOk();
        $this->api($resto)->patchJson('/api/v1/merchant/shop', ['categories' => ['epicerie']])->assertUnprocessable();
        $this->api($boutique)->patchJson('/api/v1/merchant/shop', ['categories' => ['senegalaise']])->assertUnprocessable();
    }

    public function test_dishes_use_menu_sections_and_are_not_stock_tracked_by_default(): void
    {
        $token = $this->tokenFor($this->makeRestaurant());

        $this->api($token)->postJson('/api/v1/merchant/products', [
            'name' => 'Poulet braisé', 'category' => 'plat', 'price_cents' => 6500,
            'is_featured' => true, 'spice_level' => 2, 'is_vegetarian' => false, 'prep_minutes' => 35,
        ])->assertCreated()
            ->assertJsonPath('data.category', 'plat')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.spice_level', 2)
            ->assertJsonPath('data.prep_minutes', 35)
            ->assertJsonPath('data.track_stock', false)
            ->assertJsonPath('data.available', true);

        $this->api($token)->postJson('/api/v1/merchant/products', ['name' => 'Riz', 'category' => 'epicerie', 'price_cents' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->api($token)->postJson('/api/v1/merchant/products', ['name' => 'Riz', 'category' => 'plat', 'price_cents' => 1000, 'spice_level' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('spice_level');
    }

    public function test_catalog_separates_restaurants_from_boutiques_and_filters_by_cuisine(): void
    {
        $this->makeRestaurant(['name' => 'Teranga', 'categories' => ['senegalaise']]);
        $this->makeRestaurant(['name' => 'Chez Tantie', 'categories' => ['ivoirienne']]);
        $this->makeMerchant(['name' => 'Epicerie Awa']);

        $names = fn (string $qs) => collect($this->api()->getJson("/api/v1/shops?{$qs}")->assertOk()->json('data'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['Chez Tantie', 'Teranga'], $names('kind=restaurant'));
        $this->assertSame(['Epicerie Awa'], $names('kind=boutique'));
        $this->assertSame(['Teranga'], $names('kind=restaurant&category=senegalaise'));
        $this->assertCount(3, $names(''));
    }

    public function test_featured_dishes_come_first_in_the_public_menu(): void
    {
        $shop = $this->makeRestaurant()->shop;
        $this->makeDish($shop, ['name' => 'Alloco']);
        $this->makeDish($shop, ['name' => 'Zébu grillé', 'is_featured' => true]);

        $this->api()->getJson("/api/v1/shops/{$shop->id}/products")->assertOk()
            ->assertJsonPath('data.0.name', 'Zébu grillé')
            ->assertJsonPath('data.0.is_featured', true);
    }

    public function test_catalog_exposes_the_featured_dish_of_each_restaurant(): void
    {
        $shop = $this->makeRestaurant()->shop;
        $this->makeDish($shop, ['name' => 'Alloco']);
        $this->makeDish($shop, ['name' => 'Yassa poulet', 'is_featured' => true, 'price_cents' => 7000]);

        $this->api()->getJson('/api/v1/shops?kind=restaurant')->assertOk()
            ->assertJsonPath('data.0.featured_dish.name', 'Yassa poulet')
            ->assertJsonPath('data.0.featured_dish.price_cents', 7000);
    }

    public function test_sold_out_today_blocks_orders_and_lifts_at_midnight(): void
    {
        $merchant = $this->makeRestaurant();
        $dish = $this->makeDish($merchant->shop);
        $token = $this->tokenFor($merchant);
        $client = $this->tokenFor($this->makeClient());

        $this->api($token)->patchJson("/api/v1/merchant/products/{$dish->id}/availability", ['sold_out' => true])
            ->assertOk()->assertJsonPath('data.sold_out', true)->assertJsonPath('data.available', false);

        $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $dish, 1))
            ->assertStatus(409)->assertJsonPath('code', 'product_sold_out');

        // Le lendemain, le plat est de nouveau commandable sans intervention.
        $this->travel(1)->days();
        $this->api()->getJson("/api/v1/shops/{$merchant->shop->id}/products")->assertOk()
            ->assertJsonPath('data.0.sold_out', false)->assertJsonPath('data.0.available', true);
        $this->travelBack();

        $this->api($token)->patchJson("/api/v1/merchant/products/{$dish->id}/availability", ['sold_out' => false])
            ->assertOk()->assertJsonPath('data.sold_out', false);
        $this->api($client)->postJson('/api/v1/orders/quote', $this->orderPayload($merchant->shop, $dish, 1))->assertOk();
    }

    public function test_only_the_owner_can_toggle_availability(): void
    {
        $dish = $this->makeDish($this->makeRestaurant()->shop);
        $stranger = $this->tokenFor($this->makeRestaurant());
        $client = $this->tokenFor($this->makeClient());

        $this->api($stranger)->patchJson("/api/v1/merchant/products/{$dish->id}/availability", ['sold_out' => true])->assertNotFound();
        $this->api($client)->patchJson("/api/v1/merchant/products/{$dish->id}/availability", ['sold_out' => true])->assertForbidden();
    }

    public function test_rush_mode_extends_the_announced_delay_and_expires(): void
    {
        $merchant = $this->makeRestaurant(['prep_minutes' => 25]);
        $token = $this->tokenFor($merchant);

        $this->api($token)->patchJson('/api/v1/merchant/shop', ['rush_minutes' => 20])->assertOk()
            ->assertJsonPath('data.prep_minutes', 25)
            ->assertJsonPath('data.prep_minutes_effective', 45)
            ->assertJsonPath('data.rush_extra_minutes', 20);

        $this->api()->getJson("/api/v1/shops/{$merchant->shop->id}")
            ->assertJsonPath('data.prep_minutes_effective', 45);

        $this->travel(config('shop.rush_hours') + 1)->hours();
        $this->api()->getJson("/api/v1/shops/{$merchant->shop->id}")
            ->assertJsonPath('data.prep_minutes_effective', 25)
            ->assertJsonPath('data.rush_extra_minutes', 0);
        $this->travelBack();

        $this->api($token)->patchJson('/api/v1/merchant/shop', ['rush_minutes' => 0])->assertOk()
            ->assertJsonPath('data.prep_minutes_effective', 25);
    }

    public function test_accepting_an_order_announces_the_slowest_dish_plus_rush(): void
    {
        $merchant = $this->makeRestaurant(['prep_minutes' => 25]);
        $token = $this->tokenFor($merchant);
        $client = $this->tokenFor($this->makeClient());
        $quick = $this->makeDish($merchant->shop, ['prep_minutes' => 10]);
        $slow = $this->makeDish($merchant->shop, ['prep_minutes' => 40]);

        $place = fn (array $items) => $this->api($client)->postJson('/api/v1/orders', [
            ...$this->orderPayload($merchant->shop, $quick),
            'items' => $items,
        ])->assertCreated()->json('data.id');

        // Plat rapide seul : le délai habituel du restaurant s'applique.
        $first = $place([['product_id' => $quick->id, 'quantity' => 1]]);
        $this->api($token)->postJson("/api/v1/merchant/orders/{$first}/accept")->assertOk()
            ->assertJsonPath('data.prep_minutes', 25);

        // Un plat long dans le panier : c'est lui qui donne le délai.
        $second = $place([['product_id' => $quick->id, 'quantity' => 1], ['product_id' => $slow->id, 'quantity' => 1]]);
        $this->api($token)->postJson("/api/v1/merchant/orders/{$second}/accept")->assertOk()
            ->assertJsonPath('data.prep_minutes', 40);

        // En plein rush, le délai est majoré.
        $this->api($token)->patchJson('/api/v1/merchant/shop', ['rush_minutes' => 15])->assertOk();
        $third = $place([['product_id' => $slow->id, 'quantity' => 1]]);
        $this->api($token)->postJson("/api/v1/merchant/orders/{$third}/accept")->assertOk()
            ->assertJsonPath('data.prep_minutes', 55);
    }

    public function test_restaurant_order_exposes_the_shop_kind_and_follows_the_usual_flow(): void
    {
        $merchant = $this->makeRestaurant();
        $dish = $this->makeDish($merchant->shop, ['price_cents' => 6500]);
        $token = $this->tokenFor($merchant);
        $client = $this->tokenFor($this->makeClient());

        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $dish, 2))
            ->assertCreated()->assertJsonPath('data.shop.kind', 'restaurant')->json('data.id');

        foreach (['accept', 'prepare', 'ready'] as $action) {
            $this->api($token)->postJson("/api/v1/merchant/orders/{$id}/{$action}")->assertOk();
        }

        $this->assertSame(OrderStatus::Ready, Order::find($id)->status);
        // Aucun stock à décrémenter pour un plat préparé à la commande.
        $this->assertSame(0, $dish->fresh()->stock_quantity);
    }
}
