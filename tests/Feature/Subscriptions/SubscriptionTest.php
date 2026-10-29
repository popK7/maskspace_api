<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\UserRole;
use App\Models\Shop;
use App\Models\SubscriptionReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    private function placeOrder(string $clientToken, array $payload)
    {
        return $this->api($clientToken)->postJson('/api/v1/orders', $payload);
    }

    public function test_professional_can_request_a_numbered_receipt_and_only_admin_can_activate_it(): void
    {
        $professional = User::factory()->role(UserRole::Boutique)->withProfile()->create();
        $professionalToken = $this->tokenFor($professional);

        $this->api($professionalToken)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.plans.0.code', 'orders_10')
            ->assertJsonPath('data.plans.3.code', 'contact')
            ->assertJsonPath('data.plans.0.price_label', 'Tarif à confirmer au point de vente');

        $receipt = $this->api($professionalToken)
            ->postJson('/api/v1/subscriptions', ['plan' => 'orders_10'])
            ->assertCreated()
            ->assertJsonPath('data.plan', 'orders_10')
            ->assertJsonPath('data.order_limit', 10)
            ->assertJsonPath('data.status', 'pending')
            ->json('data.receipt_number');

        $this->assertMatchesRegularExpression('/^MS-[A-Z0-9]{10}$/', $receipt);

        $adminToken = $this->tokenFor(User::factory()->admin()->create());
        $this->api($adminToken)
            ->postJson('/api/v1/admin/subscriptions/activate', ['receipt_number' => $receipt])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.activated_by', User::where('role', UserRole::Admin)->value('id'));

        $this->api($adminToken)
            ->postJson('/api/v1/admin/subscriptions/activate', ['receipt_number' => $receipt])
            ->assertStatus(409)
            ->assertJsonPath('code', 'subscription_receipt_processed');

        $this->assertSame(
            'active',
            SubscriptionReceipt::where('receipt_number', $receipt)->value('status'),
        );
    }

    public function test_client_cannot_access_professional_subscription_plans(): void
    {
        $clientToken = $this->tokenFor($this->makeClient());

        $this->api($clientToken)
            ->getJson('/api/v1/subscriptions')
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_not_available');
    }

    public function test_active_quota_is_shared_across_shop_and_restaurant_spaces_and_reserves_open_orders(): void
    {
        $merchant = $this->makeMerchant();
        SubscriptionReceipt::where('user_id', $merchant->id)->delete();
        $receipt = SubscriptionReceipt::create([
            'user_id' => $merchant->id,
            'receipt_number' => 'MS-LIMIT-'.str_pad((string) $merchant->id, 8, '0', STR_PAD_LEFT),
            'plan' => 'orders_10',
            'order_limit' => 1,
            'status' => 'active',
            'activated_at' => now()->subMinute(),
        ]);
        $restaurant = $merchant->shopForRole(UserRole::Restaurant)->save(
            new Shop([
                'kind' => 'restaurant',
                'name' => 'Restaurant partagé',
                'categories' => ['ivoirienne'],
                'city' => 'Casablanca',
                'latitude' => 33.5724,
                'longitude' => -7.6611,
                'delivery_fee_cents' => 1500,
                'min_order_cents' => 0,
                'prep_minutes' => 25,
            ]),
        );
        $product = $this->makeProduct($merchant->shop);
        $dish = $this->makeDish($restaurant);
        $clientToken = $this->tokenFor($this->makeClient());

        $this->placeOrder(
            $clientToken,
            $this->orderPayload($merchant->shop, $product, 1),
        )->assertCreated();

        $this->placeOrder(
            $clientToken,
            $this->orderPayload($restaurant, $dish, 1),
        )->assertStatus(409)->assertJsonPath('code', 'subscription_quota_reached');

        $this->assertSame('active', $receipt->fresh()->status);
    }

    public function test_order_is_rejected_until_the_receipt_has_been_activated(): void
    {
        $merchant = $this->makeMerchant();
        SubscriptionReceipt::where('user_id', $merchant->id)->delete();
        SubscriptionReceipt::create([
            'user_id' => $merchant->id,
            'receipt_number' => 'MS-PENDING-'.str_pad((string) $merchant->id, 7, '0', STR_PAD_LEFT),
            'plan' => 'orders_10',
            'order_limit' => 10,
            'status' => 'pending',
        ]);
        $product = $this->makeProduct($merchant->shop);
        $clientToken = $this->tokenFor($this->makeClient());

        $this->placeOrder(
            $clientToken,
            $this->orderPayload($merchant->shop, $product),
        )->assertStatus(409)->assertJsonPath('code', 'subscription_required');
    }

    public function test_only_delivered_orders_consume_the_quota_and_cancellations_release_reservations(): void
    {
        $merchant = $this->makeMerchant();
        SubscriptionReceipt::where('user_id', $merchant->id)->delete();
        SubscriptionReceipt::create([
            'user_id' => $merchant->id,
            'receipt_number' => 'MS-DELIVER-'.str_pad((string) $merchant->id, 7, '0', STR_PAD_LEFT),
            'plan' => 'orders_10',
            'order_limit' => 1,
            'status' => 'active',
            'activated_at' => now()->subMinute(),
        ]);
        $product = $this->makeProduct($merchant->shop);
        $clientToken = $this->tokenFor($this->makeClient());
        $merchantToken = $this->tokenFor($merchant);

        $cancelled = $this->placeOrder(
            $clientToken,
            $this->orderPayload($merchant->shop, $product, 1),
        )->assertCreated();
        $this->api($clientToken)
            ->postJson('/api/v1/orders/'.$cancelled->json('data.id').'/cancel')
            ->assertOk();

        $completed = $this->placeOrder($clientToken, [
            'shop_id' => $merchant->shop->id,
            'fulfillment_type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertCreated();
        $orderId = $completed->json('data.id');
        $pin = $completed->json('data.delivery_pin');
        foreach (['accept', 'prepare', 'ready'] as $step) {
            $this->api($merchantToken)
                ->postJson("/api/v1/merchant/orders/{$orderId}/{$step}")
                ->assertOk();
        }
        $this->api($merchantToken)
            ->postJson("/api/v1/merchant/orders/{$orderId}/complete", ['pin' => $pin])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');

        $this->api($merchantToken)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.receipt.status', 'exhausted')
            ->assertJsonPath('data.usage.completed', 1)
            ->assertJsonPath('data.usage.reserved', 0)
            ->assertJsonPath('data.usage.remaining', 0);
    }
}
