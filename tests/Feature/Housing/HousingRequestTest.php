<?php

namespace Tests\Feature\Housing;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class HousingRequestTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_client_can_open_multiple_housing_requests(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $payload = ['kind' => 'appartement', 'zone' => 'gironde'];

        $firstId = $this->api($client)->postJson('/api/v1/housing-requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');
        $secondId = $this->api($client)->postJson('/api/v1/housing-requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->api($client)->getJson("/api/v1/housing-requests/{$firstId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
        $this->api($client)->getJson("/api/v1/housing-requests/{$secondId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
    }

    public function test_demarcheurs_of_the_zone_can_answer_and_the_client_chooses(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $local = $this->tokenFor($this->makeDemarcheur(['gironde', 'maarif']));
        $elsewhere = $this->tokenFor($this->makeDemarcheur(['anfa']));

        $id = $this->api($client)->postJson('/api/v1/housing-requests', [
            'kind' => 'appartement',
            'zone' => 'gironde',
            'billing' => 'month',
            'budget_cents' => 600000,
            'guests' => 2,
            'note' => 'Proche du marché, si possible au mois.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'searching')
            ->assertJsonPath('data.zone', 'gironde')
            ->json('data.id');

        $this->api($elsewhere)->getJson('/api/v1/demarcheur/requests/available')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->api($local)->getJson('/api/v1/demarcheur/requests/available')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->api($local)->postJson("/api/v1/demarcheur/requests/{$id}/offer", [
            'message' => 'J’ai un 2 pièces à la Gironde, 6 000 DH par mois.',
        ])->assertOk();

        $offerId = $this->api($client)->getJson("/api/v1/housing-requests/{$id}")
            ->assertOk()->assertJsonCount(1, 'data.offers')
            ->json('data.offers.0.id');

        $this->api($client)->postJson("/api/v1/housing-requests/{$id}/choose", [
            'offer_id' => $offerId,
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $threads = $this->api($client)->getJson("/api/v1/chats?subject_type=housing&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('customer_demarcheur', $threads[0]['channel']);
    }

    public function test_a_demarcheur_outside_the_zone_cannot_offer(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $elsewhere = $this->tokenFor($this->makeDemarcheur(['anfa']));
        $id = $this->api($client)->postJson('/api/v1/housing-requests', [
            'kind' => 'chambre',
            'zone' => 'gironde',
        ])->assertCreated()->json('data.id');

        $this->api($elsewhere)->postJson("/api/v1/demarcheur/requests/{$id}/offer", [
            'message' => 'Je peux chercher hors de ma zone.',
        ])->assertStatus(403)->assertJsonPath('code', 'zone_not_covered');
    }

    protected function makeDemarcheur(array $zones): User
    {
        return User::factory()->role(UserRole::Demarcheur)->withProfile([
            'specialties' => $zones,
        ])->create(['is_available' => true]);
    }
}
