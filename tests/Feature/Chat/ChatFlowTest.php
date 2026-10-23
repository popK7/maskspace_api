<?php

namespace Tests\Feature\Chat;

use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class ChatFlowTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    public function test_client_and_merchant_chat_on_a_new_order(): void
    {
        $merchant = $this->makeMerchant();
        $client = $this->makeClient();
        $c = $this->tokenFor($client);
        $m = $this->tokenFor($merchant);
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 10]);
        $id = $this->api($c)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))
            ->assertCreated()->json('data.id');

        $threads = $this->api($c)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('customer_shop', $threads[0]['channel']);
        $this->assertSame($merchant->shop->name, $threads[0]['title']);
        $this->assertTrue($threads[0]['can_write']);

        $this->api($m)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'customer_shop')
            ->assertJsonPath('data.0.title', $client->name);

        $cid = $threads[0]['id'];
        $this->api($c)->postJson("/api/v1/chats/{$cid}/messages", ['body' => 'Bonjour, je peux ajouter du pain ?'])
            ->assertCreated()->assertJsonPath('data.body', 'Bonjour, je peux ajouter du pain ?')
            ->assertJsonPath('data.sender_id', $client->id);

        $this->api($m)->getJson("/api/v1/chats/{$cid}")
            ->assertOk()->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Bonjour, je peux ajouter du pain ?');
    }

    public function test_courier_threads_appear_only_after_assignment(): void
    {
        [$merchant, $m, $id, $clientToken, $client] = $this->preparingOrder();
        $livreur = $this->makeLivreur();
        $l = $this->tokenFor($livreur);

        $this->api($l)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")->assertNotFound();
        $this->api($clientToken)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->api($m)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->api($l)->postJson("/api/v1/livreur/deliveries/{$id}/accept")->assertOk();

        $this->api($m)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.channel', 'customer_shop')
            ->assertJsonPath('data.1.channel', 'shop_courier');

        $this->api($clientToken)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.channel', 'customer_courier')
            ->assertJsonPath('data.1.title', $livreur->name);

        $livreurThreads = $this->api($l)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $channels = array_column($livreurThreads, 'channel');
        $this->assertEqualsCanonicalizing(['shop_courier', 'customer_courier'], $channels);

        $toClient = collect($livreurThreads)->firstWhere('channel', 'customer_courier');
        $this->api($l)->postJson("/api/v1/chats/{$toClient['id']}/messages", ['body' => 'J’arrive dans 5 min'])
            ->assertCreated();
        $this->api($clientToken)->getJson("/api/v1/chats/{$toClient['id']}")
            ->assertOk()->assertJsonPath('data.messages.0.body', 'J’arrive dans 5 min');

        $toShop = collect($livreurThreads)->firstWhere('channel', 'shop_courier');
        $this->api($l)->postJson("/api/v1/chats/{$toShop['id']}/messages", ['body' => 'Je suis en bas'])
            ->assertCreated();
        $this->api($m)->getJson("/api/v1/chats/{$toShop['id']}")
            ->assertOk()->assertJsonPath('data.messages.0.body', 'Je suis en bas');

        $this->assertSame($client->id, $toClient['peer']['id']);
    }

    public function test_stranger_cannot_read_or_write_and_empty_body_is_rejected(): void
    {
        $merchant = $this->makeMerchant();
        $client = $this->tokenFor($this->makeClient());
        $product = $this->makeProduct($merchant->shop);
        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))
            ->assertCreated()->json('data.id');
        $cid = $this->api($client)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->json('data.0.id');

        $stranger = $this->tokenFor($this->makeClient());
        $this->api($stranger)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")->assertNotFound();
        $this->api($stranger)->getJson("/api/v1/chats/{$cid}")->assertNotFound();
        $this->api($stranger)->postJson("/api/v1/chats/{$cid}/messages", ['body' => 'intrus'])->assertNotFound();

        $this->api($client)->postJson("/api/v1/chats/{$cid}/messages", ['body' => '   '])->assertUnprocessable();
        $this->api($client)->postJson("/api/v1/chats/{$cid}/messages", ['body' => str_repeat('a', 1001)])->assertUnprocessable();
    }

    public function test_cancelled_order_closes_the_chat(): void
    {
        $merchant = $this->makeMerchant();
        $client = $this->tokenFor($this->makeClient());
        $product = $this->makeProduct($merchant->shop);
        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))
            ->assertCreated()->json('data.id');
        $cid = $this->api($client)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")->json('data.0.id');

        $this->api($client)->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Erreur'])->assertOk();
        $this->api($client)->getJson("/api/v1/chats?subject_type=order&subject_id={$id}")
            ->assertOk()->assertJsonPath('data.0.can_write', false);
        $this->api($client)->postJson("/api/v1/chats/{$cid}/messages", ['body' => 'encore'])
            ->assertStatus(409)->assertJsonPath('code', 'chat_closed');
    }

    public function test_artisan_and_client_chat_after_assignment(): void
    {
        $clientUser = $this->makeClient();
        $client = $this->tokenFor($clientUser);
        $artisanUser = $this->makeArtisan();
        $artisan = $this->tokenFor($artisanUser);
        $job = $this->publishJob($client);

        $this->api($client)->getJson("/api/v1/chats?subject_type=job&subject_id={$job->id}")->assertNotFound();

        $offerId = $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", [
            'estimated_price_dh' => 150,
        ])->assertCreated()->json('data.id');
        $this->api($client)->postJson("/api/v1/jobs/{$job->id}/choose", ['offer_id' => $offerId])->assertOk();

        $cid = $this->api($client)->getJson("/api/v1/chats?subject_type=job&subject_id={$job->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.channel', 'customer_artisan')
            ->assertJsonPath('data.0.title', $artisanUser->name)->json('data.0.id');

        $this->api($artisan)->postJson("/api/v1/chats/{$cid}/messages", ['body' => 'J’arrive avec le matériel'])
            ->assertCreated();
        $this->api($client)->getJson("/api/v1/chats/{$cid}")
            ->assertOk()->assertJsonPath('data.messages.0.body', 'J’arrive avec le matériel');
    }

    public function test_driver_chats_with_artisan_and_client_once_the_ride_is_accepted(): void
    {
        $clientUser = $this->makeClient();
        $client = $this->tokenFor($clientUser);
        $artisanUser = $this->makeArtisan();
        $artisan = $this->tokenFor($artisanUser);
        $driverUser = $this->makeDriver();
        $driver = $this->tokenFor($driverUser);

        $job = $this->publishJob($client);
        $offerId = $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", [
            'estimated_price_dh' => 150,
        ])->assertCreated()->json('data.id');
        $this->api($client)->postJson("/api/v1/jobs/{$job->id}/choose", ['offer_id' => $offerId])->assertOk();
        $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'address' => 'Maârif',
        ])->assertCreated();
        $rideId = Ride::where('job_id', $job->id)->value('id');
        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/accept")->assertOk();

        $driverThreads = $this->api($driver)->getJson("/api/v1/chats?subject_type=job&subject_id={$job->id}")
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing(
            ['artisan_driver', 'customer_driver'],
            array_column($driverThreads, 'channel'),
        );

        $viaRide = $this->api($driver)->getJson("/api/v1/chats?subject_type=ride&subject_id={$rideId}")
            ->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            array_column($driverThreads, 'id'),
            array_column($viaRide, 'id'),
        );

        $toArtisan = collect($driverThreads)->firstWhere('channel', 'artisan_driver');
        $this->api($driver)->postJson("/api/v1/chats/{$toArtisan['id']}/messages", ['body' => 'Je suis en bas'])
            ->assertCreated();
        $this->api($artisan)->getJson("/api/v1/chats/{$toArtisan['id']}")
            ->assertOk()->assertJsonPath('data.messages.0.body', 'Je suis en bas');
    }

    /** @return array{0: \App\Models\User, 1: string, 2: int, 3: string, 4: \App\Models\User} */
    private function preparingOrder(): array
    {
        $merchant = $this->makeMerchant();
        $product = $this->makeProduct($merchant->shop, ['stock_quantity' => 20]);
        $clientUser = $this->makeClient();
        $client = $this->tokenFor($clientUser);
        $m = $this->tokenFor($merchant);

        $id = $this->api($client)->postJson('/api/v1/orders', $this->orderPayload($merchant->shop, $product))->assertCreated()->json('data.id');
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/accept")->assertOk();
        $this->api($m)->postJson("/api/v1/merchant/orders/{$id}/prepare")->assertOk();

        return [$merchant, $m, $id, $client, $clientUser];
    }
}
