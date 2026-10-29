<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\UserRole;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class SubscriptionPlanManagementTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_admin_can_create_update_and_deactivate_a_plan(): void
    {
        $adminToken = $this->tokenFor(User::factory()->admin()->create());

        $created = $this->api($adminToken)
            ->postJson('/api/v1/admin/subscription-plans', [
                'name' => 'Équipe locale',
                'order_limit' => 22,
                'description' => 'Pour les équipes.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Équipe locale')
            ->assertJsonPath('data.order_limit', 22)
            ->assertJsonPath('data.price_label', 'Tarif à confirmer au point de vente')
            ->json('data');

        $this->assertSame('equipe_locale', $created['code']);

        $this->api($adminToken)
            ->patchJson('/api/v1/admin/subscription-plans/'.$created['id'], [
                'name' => 'Équipe Plus',
                'order_limit' => 35,
                'description' => 'Formule modifiée.',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Équipe Plus')
            ->assertJsonPath('data.order_limit', 35)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse(
            SubscriptionPlan::whereKey($created['id'])->firstOrFail()->is_active,
        );
    }

    public function test_plan_updates_do_not_change_existing_receipts_and_deactivated_plan_cannot_be_requested(): void
    {
        $professional = User::factory()->role(UserRole::Boutique)->withProfile()->create();
        $professionalToken = $this->tokenFor($professional);
        $adminToken = $this->tokenFor(User::factory()->admin()->create());

        $created = $this->api($adminToken)
            ->postJson('/api/v1/admin/subscription-plans', [
                'name' => 'Formule locale',
                'order_limit' => 7,
            ])
            ->assertCreated()
            ->json('data');

        $receipt = $this->api($professionalToken)
            ->postJson('/api/v1/subscriptions', ['plan' => $created['code']])
            ->assertCreated()
            ->assertJsonPath('data.plan_name', 'Formule locale')
            ->assertJsonPath('data.order_limit', 7);

        $this->api($adminToken)
            ->patchJson('/api/v1/admin/subscription-plans/'.$created['id'], [
                'name' => 'Nouvelle formule locale',
                'order_limit' => 20,
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('subscription_receipts', [
            'receipt_number' => $receipt->json('data.receipt_number'),
            'plan_name' => 'Formule locale',
            'order_limit' => 7,
        ]);

        $this->api($professionalToken)
            ->postJson('/api/v1/subscriptions', ['plan' => $created['code']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_subscription_plan');

        $this->api($professionalToken)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->assertJsonMissing(['code' => $created['code']]);
    }

    public function test_non_admin_cannot_manage_plans(): void
    {
        $token = $this->tokenFor($this->makeClient());

        $this->api($token)->getJson('/api/v1/admin/subscription-plans')->assertForbidden();
        $this->api($token)
            ->postJson('/api/v1/admin/subscription-plans', [
                'name' => 'Interdit',
                'order_limit' => 5,
            ])
            ->assertForbidden();
    }
}
