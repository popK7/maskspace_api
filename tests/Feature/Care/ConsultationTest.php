<?php

namespace Tests\Feature\Care;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_doctors_of_the_specialty_answer_and_the_client_chooses(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $cardio = $this->tokenFor($this->makeMedecin(['cardiologue', 'generaliste']));
        $other = $this->tokenFor($this->makeMedecin(['pediatre']));

        $id = $this->api($client)->postJson('/api/v1/consultations', [
            'specialty' => 'cardiologue',
            'mode' => 'cabinet',
            'note' => 'Tension élevée depuis une semaine.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'searching')
            ->assertJsonPath('data.specialty', 'cardiologue')
            ->json('data.id');

        $this->api($other)->getJson('/api/v1/medecin/consultations/available')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->api($cardio)->getJson('/api/v1/medecin/consultations/available')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->api($cardio)->postJson("/api/v1/medecin/consultations/{$id}/offer", [
            'message' => 'Je peux vous recevoir au cabinet demain matin.',
        ])->assertOk();

        $offerId = $this->api($client)->getJson("/api/v1/consultations/{$id}")
            ->assertOk()->assertJsonCount(1, 'data.offers')
            ->json('data.offers.0.id');

        $this->api($client)->postJson("/api/v1/consultations/{$id}/choose", [
            'offer_id' => $offerId,
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        $threads = $this->api($client)->getJson("/api/v1/chats?subject_type=consultation&subject_id={$id}")
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('customer_medecin', $threads[0]['channel']);
    }

    public function test_a_doctor_outside_the_specialty_cannot_offer(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $other = $this->tokenFor($this->makeMedecin(['pediatre']));
        $id = $this->api($client)->postJson('/api/v1/consultations', [
            'specialty' => 'cardiologue',
            'mode' => 'domicile',
        ])->assertCreated()->json('data.id');

        $this->api($other)->postJson("/api/v1/medecin/consultations/{$id}/offer", [
            'message' => 'Je peux quand même vous voir.',
        ])->assertStatus(403)->assertJsonPath('code', 'specialty_not_covered');
    }

    protected function makeMedecin(array $specialties): User
    {
        return User::factory()->role(UserRole::Medecin)->withProfile([
            'specialties' => $specialties,
        ])->create(['is_available' => true]);
    }
}
