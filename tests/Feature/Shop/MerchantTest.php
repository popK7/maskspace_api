<?php

namespace Tests\Feature\Shop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class MerchantTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_registration_creates_the_shop_and_merchant_reads_it(): void
    {
        $token = $this->registerActivated('boutique');

        $this->api($token)->getJson('/api/v1/merchant/shop')->assertOk()
            ->assertJsonPath('data.name', 'Epicerie Awa')
            ->assertJsonPath('data.categories.0', 'epicerie');
    }

    public function test_merchant_updates_shop_settings_and_hours(): void
    {
        $token = $this->tokenFor($this->makeMerchant());

        $this->api($token)->patchJson('/api/v1/merchant/shop', [
            'description' => 'Les meilleurs produits',
            'delivery_fee_cents' => 2000,
            'free_delivery_threshold_cents' => 15000,
            'min_order_cents' => 3000,
            'prep_minutes' => 45,
            'opening_hours' => ['mon' => [['open' => '09:00', 'close' => '13:00'], ['open' => '15:00', 'close' => '19:00']]],
        ])->assertOk()
            ->assertJsonPath('data.delivery_fee_cents', 2000)
            ->assertJsonPath('data.opening_hours.mon.1.open', '15:00');
    }

    public function test_shop_settings_validation(): void
    {
        $token = $this->tokenFor($this->makeMerchant());

        $this->api($token)->patchJson('/api/v1/merchant/shop', ['opening_hours' => ['funday' => [['open' => '09:00', 'close' => '10:00']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('opening_hours');
        $this->api($token)->patchJson('/api/v1/merchant/shop', ['opening_hours' => ['mon' => [['open' => '18:00', 'close' => '09:00']]]])
            ->assertUnprocessable();
        $this->api($token)->patchJson('/api/v1/merchant/shop', ['supports_delivery' => false, 'supports_pickup' => false])
            ->assertUnprocessable();
        $this->api($token)->patchJson('/api/v1/merchant/shop', ['categories' => ['inconnue']])->assertUnprocessable();
        $this->api($token)->patchJson('/api/v1/merchant/shop', ['latitude' => 33.5])->assertUnprocessable();
    }

    public function test_closed_by_schedule_shop_is_not_open_now(): void
    {
        $merchant = $this->makeMerchant(['opening_hours' => ['mon' => [['open' => '09:00', 'close' => '10:00']]]]);

        $this->assertTrue($merchant->shop->isOpenNow(now()->startOfWeek()->setTime(9, 30)));
        $this->assertFalse($merchant->shop->isOpenNow(now()->startOfWeek()->setTime(12, 0)));
        $this->assertFalse($merchant->shop->isOpenNow(now()->startOfWeek()->addDay()->setTime(9, 30)));
    }

    public function test_next_opening_label_is_exposed_when_closed_by_schedule(): void
    {
        $merchant = $this->makeMerchant(['opening_hours' => [
            'mon' => [['open' => '09:00', 'close' => '20:00']],
            'tue' => [['open' => '09:00', 'close' => '20:00']],
        ]]);
        $shop = $merchant->shop;

        $monday = now()->startOfWeek();
        $this->assertSame('Ouvre à 09:00', $shop->nextOpeningLabel($monday->copy()->setTime(7, 0)));
        $this->assertSame('Ouvre demain à 09:00', $shop->nextOpeningLabel($monday->copy()->setTime(22, 0)));
        $this->assertSame('Ouvre lundi à 09:00', $shop->nextOpeningLabel($monday->copy()->addDay()->setTime(22, 0)));
        $this->assertNull($shop->nextOpeningLabel($monday->copy()->setTime(12, 0)));
    }

    public function test_opening_the_switch_outside_hours_is_a_temporary_exceptional_opening(): void
    {
        $this->travelTo(now()->startOfWeek()->setTime(22, 30));
        $merchant = $this->makeMerchant(['is_open' => false, 'opening_hours' => ['mon' => [['open' => '09:00', 'close' => '20:00']]]]);
        $token = $this->tokenFor($merchant);

        $this->api($token)->patchJson('/api/v1/merchant/shop', ['is_open' => true])->assertOk()
            ->assertJsonPath('data.is_open_now', true)
            ->assertJsonPath('data.next_opening_label', null)
            ->assertJsonStructure(['data' => ['force_open_until']]);

        // L'ouverture exceptionnelle expire.
        $this->travel(config('shop.force_open_hours') + 1)->hours();
        $this->assertFalse($merchant->shop->fresh()->isOpenNow());

        // Fermer manuellement annule l'ouverture exceptionnelle.
        $this->travelBack();
        $this->travelTo(now()->startOfWeek()->setTime(22, 30));
        $this->api($token)->patchJson('/api/v1/merchant/shop', ['is_open' => false])->assertOk()
            ->assertJsonPath('data.is_open_now', false)
            ->assertJsonPath('data.force_open_until', null);
    }

    public function test_shop_image_upload_replaces_and_deletes_files(): void
    {
        Storage::fake('public');
        $token = $this->tokenFor($this->makeMerchant());

        $first = $this->api($token)->post('/api/v1/merchant/shop/images/logo', [
            'image' => UploadedFile::fake()->image('logo.png', 800, 600),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.logo_url');
        $this->assertNotNull($first);

        $this->api($token)->post('/api/v1/merchant/shop/images/logo', [
            'image' => UploadedFile::fake()->image('logo2.jpg', 300, 300),
        ], ['Accept' => 'application/json'])->assertOk();

        // L'ancienne image (et sa miniature) est supprimee : il reste image + miniature.
        $this->assertCount(2, Storage::disk('public')->allFiles());

        // Fichier non image refuse
        $this->api($token)->post('/api/v1/merchant/shop/images/cover', [
            'image' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->api($token)->deleteJson('/api/v1/merchant/shop/images/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_product_crud_with_promo_price_and_soft_delete(): void
    {
        $merchant = $this->makeMerchant();
        $token = $this->tokenFor($merchant);

        $id = $this->api($token)->postJson('/api/v1/merchant/products', [
            'name' => 'Attiéké', 'category' => 'epicerie', 'price_cents' => 2800, 'compare_at_price_cents' => 3500,
            'unit' => 'sachet 500 g', 'origin' => 'Côte d\'Ivoire', 'stock_quantity' => 12,
        ])->assertCreated()->assertJsonPath('data.price_cents', 2800)->assertJsonPath('data.stock_quantity', 12)->json('data.id');

        // Prix barre inferieur au prix : refuse
        $this->api($token)->patchJson("/api/v1/merchant/products/{$id}", ['compare_at_price_cents' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('compare_at_price_cents');

        $this->api($token)->patchJson("/api/v1/merchant/products/{$id}", ['price_cents' => 3000, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->api($token)->getJson('/api/v1/merchant/products?q=atti')->assertOk()->assertJsonCount(1, 'data');

        // Un produit masque n'apparait plus cote public
        $this->api()->getJson("/api/v1/shops/{$merchant->shop->id}/products")->assertOk()->assertJsonCount(0, 'data');

        $this->api($token)->deleteJson("/api/v1/merchant/products/{$id}")->assertNoContent();
        $this->api($token)->getJson("/api/v1/merchant/products/{$id}")->assertNotFound();
    }

    public function test_product_validation(): void
    {
        $token = $this->tokenFor($this->makeMerchant());

        $this->api($token)->postJson('/api/v1/merchant/products', ['name' => 'X', 'category' => 'xx', 'price_cents' => -5])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'category', 'price_cents']);
    }

    public function test_stock_adjustments_set_delta_and_never_go_negative(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 5]);
        $token = $this->tokenFor($merchant);
        $url = "/api/v1/merchant/products/{$product->id}/stock";

        $this->api($token)->patchJson($url, ['delta' => 10])->assertOk()->assertJsonPath('data.stock_quantity', 15);
        $this->api($token)->patchJson($url, ['delta' => -3])->assertOk()->assertJsonPath('data.stock_quantity', 12);
        $this->api($token)->patchJson($url, ['quantity' => 40])->assertOk()->assertJsonPath('data.stock_quantity', 40);
        $this->api($token)->patchJson($url, ['delta' => -41])->assertUnprocessable();
        $this->api($token)->patchJson($url, ['quantity' => 1, 'delta' => 1])->assertUnprocessable();
        $this->api($token)->patchJson($url, [])->assertUnprocessable();
    }

    public function test_low_stock_filter_and_stats_counter(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeProduct($merchant->shop, ['stock_quantity' => 2, 'low_stock_threshold' => 5]);
        $this->makeProduct($merchant->shop, ['stock_quantity' => 0]);
        $this->makeProduct($merchant->shop, ['stock_quantity' => 50]);
        $token = $this->tokenFor($merchant);

        $this->api($token)->getJson('/api/v1/merchant/products?stock=low')->assertOk()->assertJsonCount(2, 'data');
        $this->api($token)->getJson('/api/v1/merchant/products?stock=out')->assertOk()->assertJsonCount(1, 'data');
        $this->api($token)->getJson('/api/v1/merchant/stats')->assertOk()->assertJsonPath('data.low_stock_count', 2);
    }

    public function test_merchant_cannot_touch_another_merchants_products(): void
    {
        $a = $this->makeMerchant();
        $b = $this->makeMerchant();
        $product = $this->makeProduct($a->shop);
        $tokenB = $this->tokenFor($b);

        $this->api($tokenB)->getJson("/api/v1/merchant/products/{$product->id}")->assertNotFound();
        $this->api($tokenB)->patchJson("/api/v1/merchant/products/{$product->id}", ['name' => 'Piraté'])->assertNotFound();
        $this->api($tokenB)->patchJson("/api/v1/merchant/products/{$product->id}/stock", ['quantity' => 0])->assertNotFound();
        $this->api($tokenB)->deleteJson("/api/v1/merchant/products/{$product->id}")->assertNotFound();
        $this->api($tokenB)->getJson('/api/v1/merchant/products')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_product_images_upload_limit_and_delete(): void
    {
        Storage::fake('public');
        config(['shop.product_max_images' => 2]);
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $token = $this->tokenFor($merchant);
        $url = "/api/v1/merchant/products/{$product->id}/images";

        $this->api($token)->post($url, ['image' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.images');
        $res = $this->api($token)->post($url, ['image' => UploadedFile::fake()->image('b.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(2, 'data.images');
        $this->api($token)->post($url, ['image' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])
            ->assertUnprocessable();

        $imageId = $res->json('data.images.0.id');
        $this->api($token)->deleteJson("{$url}/{$imageId}")->assertOk()->assertJsonCount(1, 'data.images');
        $this->assertCount(2, Storage::disk('public')->allFiles()); // 1 image restante = pleine taille + miniature
    }

    public function test_uploaded_images_get_a_smaller_thumbnail_exposed_by_the_api(): void
    {
        Storage::fake('public');
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop);
        $token = $this->tokenFor($merchant);

        $res = $this->api($token)->post("/api/v1/merchant/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('big.jpg', 2000, 1500),
        ], ['Accept' => 'application/json'])->assertOk();

        $full = $res->json('data.images.0.url');
        $thumb = $res->json('data.images.0.thumb_url');
        $this->assertNotSame($full, $thumb);
        $this->assertStringContainsString('_thumb.', $thumb);

        $files = collect(Storage::disk('public')->allFiles());
        $thumbFile = $files->first(fn ($f) => str_contains($f, '_thumb.'));
        $fullFile = $files->first(fn ($f) => ! str_contains($f, '_thumb.'));
        [$tw] = getimagesizefromstring(Storage::disk('public')->get($thumbFile));
        [$fw] = getimagesizefromstring(Storage::disk('public')->get($fullFile));
        $this->assertSame(480, $tw);
        $this->assertSame(1280, $fw);
    }

    public function test_coupon_management(): void
    {
        $merchant = $this->makeMerchant();
        $token = $this->tokenFor($merchant);

        $id = $this->api($token)->postJson('/api/v1/merchant/coupons', [
            'code' => 'bienvenue', 'type' => 'percent', 'value' => 15, 'max_uses' => 100,
        ])->assertCreated()->assertJsonPath('data.code', 'BIENVENUE')->json('data.id');

        $this->api($token)->postJson('/api/v1/merchant/coupons', ['code' => 'BIENVENUE', 'type' => 'fixed', 'value' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->api($token)->postJson('/api/v1/merchant/coupons', ['code' => 'BAD', 'type' => 'percent', 'value' => 150])
            ->assertUnprocessable()->assertJsonValidationErrors('value');

        $this->api($token)->patchJson("/api/v1/merchant/coupons/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->api($token)->getJson('/api/v1/merchant/coupons')->assertOk()->assertJsonCount(1, 'data');

        // Un autre marchand peut reutiliser le meme code, mais pas toucher au notre
        $other = $this->tokenFor($this->makeMerchant());
        $this->api($other)->postJson('/api/v1/merchant/coupons', ['code' => 'BIENVENUE', 'type' => 'fixed', 'value' => 500])->assertCreated();
        $this->api($other)->deleteJson("/api/v1/merchant/coupons/{$id}")->assertNotFound();

        $this->api($token)->deleteJson("/api/v1/merchant/coupons/{$id}")->assertNoContent();
    }

    public function test_stats_and_reviews_reply(): void
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['price_cents' => 5000, 'stock_quantity' => 20, 'name' => 'Gari']);
        $client = $this->makeClient();
        $c = $this->tokenFor($client);
        $m = $this->tokenFor($merchant);

        // Deux commandes en retrait, une livree
        $ids = [];
        foreach ([2, 1] as $qty) {
            $r = $this->api($c)->postJson('/api/v1/orders', [
                'shop_id' => $merchant->shop->id, 'fulfillment_type' => 'pickup',
                'items' => [['product_id' => $product->id, 'quantity' => $qty]],
            ])->assertCreated();
            $ids[] = [$r->json('data.id'), $r->json('data.delivery_pin')];
        }

        [$id, $pin] = $ids[0];
        foreach (['accept', 'prepare', 'ready'] as $s) {
            $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/{$s}")->assertOk();
        }
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/complete", ['pin' => $pin])->assertOk();

        $stats = $this->api($m)->getJson('/api/v1/merchant/stats')->assertOk();
        $stats->assertJsonPath('data.delivered_count', 1)
            ->assertJsonPath('data.revenue_cents', 10000)
            ->assertJsonPath('data.commission_cents', 1000)
            ->assertJsonPath('data.net_cents', 9000)
            ->assertJsonPath('data.average_order_cents', 10000)
            ->assertJsonPath('data.pending_count', 1)
            ->assertJsonPath('data.today.orders', 1)
            ->assertJsonPath('data.yesterday.orders', 0)
            ->assertJsonPath('data.yesterday.revenue_cents', 0)
            ->assertJsonPath('data.top_products.0.name', 'Gari')
            ->assertJsonPath('data.top_products.0.quantity', 2);

        // Avis puis reponse du marchand
        $this->api($c)->postJson("/api/v1/orders/{$id}/review", ['rating' => 4, 'comment' => 'Bien'])->assertCreated();
        $reviewId = $this->api($m)->getJson('/api/v1/merchant/reviews')->assertOk()
            ->assertJsonPath('summary.rating_count', 1)->json('data.0.id');
        $this->api($m)->postJson("/api/v1/merchant/reviews/{$reviewId}/reply", ['reply' => 'Merci !'])
            ->assertOk()->assertJsonPath('data.reply', 'Merci !');

        // Un autre marchand ne peut pas repondre
        $other = $this->tokenFor($this->makeMerchant());
        $this->api($other)->postJson("/api/v1/merchant/reviews/{$reviewId}/reply", ['reply' => 'x'])->assertNotFound();

        // Public : avis visibles avec nom abrege
        $this->api()->getJson("/api/v1/shops/{$merchant->shop->id}/reviews")->assertOk()
            ->assertJsonPath('data.0.reply', 'Merci !');
    }
}
