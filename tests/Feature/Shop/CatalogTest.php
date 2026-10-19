<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_catalog_is_public_and_hides_inactive_shops_and_private_fields(): void
    {
        $visible = $this->makeMerchant(['name' => 'Visible']);
        $this->makeMerchant(['name' => 'Cachee', 'is_active' => false]);
        $product = $this->makeProduct($visible->shop, ['stock_quantity' => 9]);

        $list = $this->api()->getJson('/api/v1/shops')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Visible', $list->json('data.0.name'));

        $this->api()->getJson('/api/v1/shops/'.$visible->shop->id)->assertOk();
        $this->api()->getJson('/api/v1/shops/'.$this->makeMerchant(['is_active' => false])->shop->id)->assertNotFound();

        // Le stock exact est reserve au marchand
        $res = $this->api()->getJson("/api/v1/shops/{$visible->shop->id}/products")->assertOk();
        $this->assertArrayNotHasKey('stock_quantity', $res->json('data.0'));
        $this->assertTrue($res->json('data.0.available'));
    }

    public function test_search_filters_by_text_category_and_open_now(): void
    {
        $this->makeMerchant(['name' => 'Mama Awa', 'categories' => ['epicerie']]);
        $this->makeMerchant(['name' => 'Maison du Wax', 'categories' => ['wax']]);
        $this->makeMerchant(['name' => 'Fermee Epicerie', 'categories' => ['epicerie'], 'is_open' => false]);

        $this->api()->getJson('/api/v1/shops?q=wax')->assertOk()->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/v1/shops?category=epicerie')->assertOk()->assertJsonCount(2, 'data');
        $this->api()->getJson('/api/v1/shops?category=epicerie&open_now=1')->assertOk()->assertJsonCount(1, 'data');
        $this->api()->getJson('/api/v1/shops?category=nope')->assertUnprocessable();
    }

    public function test_geo_search_sorts_by_distance_and_applies_radius(): void
    {
        // Point de reference : centre de Casablanca (33.5731, -7.5898)
        $this->makeMerchant(['name' => 'Proche', 'latitude' => 33.5750, 'longitude' => -7.5900]);
        $this->makeMerchant(['name' => 'Moyen', 'latitude' => 33.6200, 'longitude' => -7.6200]);
        $this->makeMerchant(['name' => 'Loin', 'latitude' => 31.6295, 'longitude' => -7.9811]); // Marrakech

        $res = $this->api()->getJson('/api/v1/shops?lat=33.5731&lng=-7.5898&sort=distance')->assertOk();
        $this->assertSame(['Proche', 'Moyen', 'Loin'], array_column($res->json('data'), 'name'));
        $this->assertLessThan(1, $res->json('data.0.distance_km'));

        $near = $this->api()->getJson('/api/v1/shops?lat=33.5731&lng=-7.5898&radius_km=10')->assertOk();
        $this->assertSame(['Proche', 'Moyen'], array_column($near->json('data'), 'name'));

        // radius sans position : erreur de validation
        $this->api()->getJson('/api/v1/shops?radius_km=5')->assertUnprocessable();
    }

    public function test_out_of_stock_product_is_unavailable_and_filterable(): void
    {
        $m = $this->makeMerchant();
        $this->makeProduct($m->shop, ['name' => 'Dispo', 'stock_quantity' => 3]);
        $this->makeProduct($m->shop, ['name' => 'Epuise', 'stock_quantity' => 0]);

        $all = $this->api()->getJson("/api/v1/shops/{$m->shop->id}/products")->assertOk();
        $this->assertCount(2, $all->json('data'));

        $this->api()->getJson("/api/v1/shops/{$m->shop->id}/products?in_stock=1")->assertOk()->assertJsonCount(1, 'data');
    }
}
