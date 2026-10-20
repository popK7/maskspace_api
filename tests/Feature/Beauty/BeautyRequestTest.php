<?php

namespace Tests\Feature\Beauty;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class BeautyRequestTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_pros_of_the_specialty_answer_and_the_client_chooses(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $coiffeur = $this->tokenFor($this->makeBeaute(['coiffeur', 'tresses']));
        $other = $this->tokenFor($this->makeBeaute(['manucure']));

        $id = $this->api($client)->postJson('/api/v1/beauty-requests', [
            'specialty' => 'coiffeur',
            'mode' => 'salon',
            'note' => 'Tresses collées pour un mariage samedi.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'searching')
            ->assertJsonPath('data.specialty', 'coiffeur')
            ->json('data.id');

        $this->api($other)->getJson('/api/v1/beaute/requests/available')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->api($coiffeur)->getJson('/api/v1/beaute/requests/available')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->api($coiffeur)->postJson("/api/v1/beaute/requests/{$id}/offer", [
            'message' => 'Je peux vous recevoir au salon demain à 10 h.',
        ])->assertOk();

        $offerId = $this->api($client)->getJson("/api/v1/beauty-requests/{$id}")
            ->assertOk()->assertJsonCount(1, 'data.offers')
            ->json('data.offers.0.id');

        $this->api($client)->postJson("/api/v1/beauty-requests/{$id}/choose", [
            'offer_id' => $offerId,
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $threads = $this->api($client)->getJson("/api/v1/chats?subject_type=beauty&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('customer_beaute', $threads[0]['channel']);
    }

    public function test_a_pro_outside_the_specialty_cannot_offer(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $other = $this->tokenFor($this->makeBeaute(['manucure']));
        $id = $this->api($client)->postJson('/api/v1/beauty-requests', [
            'specialty' => 'coiffeur',
            'mode' => 'domicile',
        ])->assertCreated()->json('data.id');

        $this->api($other)->postJson("/api/v1/beaute/requests/{$id}/offer", [
            'message' => 'Je peux quand même vous coiffer.',
        ])->assertStatus(403)->assertJsonPath('code', 'specialty_not_covered');
    }

    protected function makeBeaute(array $specialties): User
    {
        return User::factory()->role(UserRole::Beaute)->withProfile([
            'specialties' => $specialties,
        ])->create(['is_available' => true]);
    }
}
