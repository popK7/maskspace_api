<?php

namespace Tests\Feature\Care;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_client_can_open_multiple_consultations(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $payload = $this->consultationPayload();

        $firstId = $this->api($client)->postJson('/api/v1/consultations', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');
        $secondId = $this->api($client)->postJson('/api/v1/consultations', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->api($client)->getJson("/api/v1/consultations/{$firstId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
        $this->api($client)->getJson("/api/v1/consultations/{$secondId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
    }

    public function test_doctors_of_the_specialty_answer_and_the_client_chooses(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $cardio = $this->tokenFor($this->makeMedecin(['cardiologue', 'generaliste']));
        $other = $this->tokenFor($this->makeMedecin(['pediatre']));

        $id = $this->api($client)->postJson('/api/v1/consultations', [
            ...$this->consultationPayload(['specialty' => 'cardiologue']),
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
            ...$this->consultationPayload(['specialty' => 'cardiologue']),
            'mode' => 'domicile',
        ])->assertCreated()->json('data.id');

        $this->api($other)->postJson("/api/v1/medecin/consultations/{$id}/offer", [
            'message' => 'Je peux quand même vous voir.',
        ])->assertStatus(403)->assertJsonPath('code', 'specialty_not_covered');
    }

    public function test_consultation_cannot_be_completed_until_patient_confirms_doctors_price(): void
    {
        [$id, $client, $doctor] = $this->assignedConsultation();

        $this->api($doctor)->postJson("/api/v1/medecin/consultations/{$id}/complete")
            ->assertForbidden()
            ->assertJsonPath('code', 'price_confirmation_required');

        $this->api($client)->postJson("/api/v1/consultations/{$id}/complete")
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_not_submitted');

        $this->api($doctor)->postJson("/api/v1/medecin/consultations/{$id}/price", [
            'price_dh' => 350,
        ])->assertOk()
            ->assertJsonPath('data.price_dh', 350)
            ->assertJsonPath('data.price_status', 'pending');

        $this->api($client)->postJson("/api/v1/consultations/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.price_status', 'accepted');

        $this->api($doctor)->postJson("/api/v1/medecin/consultations/{$id}/price", [
            'price_dh' => 500,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'consultation_bad_status');
    }

    public function test_doctor_earnings_include_only_their_confirmed_completed_consultations_in_period(): void
    {
        $doctor = $this->makeMedecin(['generaliste']);
        $otherDoctor = $this->makeMedecin(['generaliste']);
        $client = $this->makeClient();

        Consultation::create([
            'client_id' => $client->id,
            'medecin_id' => $doctor->id,
            'specialty' => 'generaliste',
            'mode' => 'cabinet',
            'status' => 'completed',
            'price_dh' => 350,
            'price_status' => 'accepted',
            'completed_at' => now()->subDays(2),
        ]);
        Consultation::create([
            'client_id' => $client->id,
            'medecin_id' => $doctor->id,
            'specialty' => 'generaliste',
            'mode' => 'domicile',
            'status' => 'completed',
            'price_dh' => 500,
            'price_status' => 'accepted',
            'completed_at' => now()->subDays(10),
        ]);
        Consultation::create([
            'client_id' => $client->id,
            'medecin_id' => $doctor->id,
            'specialty' => 'generaliste',
            'mode' => 'cabinet',
            'status' => 'cancelled',
            'price_dh' => 900,
            'price_status' => 'accepted',
            'completed_at' => now()->subDay(),
        ]);
        Consultation::create([
            'client_id' => $client->id,
            'medecin_id' => $otherDoctor->id,
            'specialty' => 'generaliste',
            'mode' => 'cabinet',
            'status' => 'completed',
            'price_dh' => 1200,
            'price_status' => 'accepted',
            'completed_at' => now()->subDay(),
        ]);

        $doctorToken = $this->tokenFor($doctor);

        $this->api($doctorToken)->getJson('/api/v1/medecin/earnings?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.confirmed_total_dh', 350)
            ->assertJsonPath('data.completed_count', 1)
            ->assertJsonPath('data.average_dh', 350)
            ->assertJsonPath('data.daily.0.total_dh', 350)
            ->assertJsonPath('data.recent.0.price_dh', 350);

        $this->api($doctorToken)->getJson('/api/v1/medecin/earnings?days=30')
            ->assertOk()
            ->assertJsonPath('data.confirmed_total_dh', 850)
            ->assertJsonPath('data.completed_count', 2);

        $this->api($doctorToken)->getJson('/api/v1/medecin/earnings?days=14')
            ->assertUnprocessable();
    }

    public function test_only_the_assigned_doctor_can_propose_consultation_price(): void
    {
        [$id, , $doctor] = $this->assignedConsultation();
        $other = $this->tokenFor($this->makeMedecin(['generaliste']));

        $this->api($doctor)->postJson("/api/v1/medecin/consultations/{$id}/price", [
            'price_dh' => 0,
        ])->assertUnprocessable();

        $this->api($other)->postJson("/api/v1/medecin/consultations/{$id}/price", [
            'price_dh' => 350,
        ])->assertNotFound();
    }

    public function test_patient_can_cancel_searching_or_assigned_consultation(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $id = $this->api($client)->postJson('/api/v1/consultations', $this->consultationPayload())
            ->assertCreated()->json('data.id');

        $this->api($client)->postJson("/api/v1/consultations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelling_searching_consultation_closes_pending_doctor_offers(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $doctor = $this->tokenFor($this->makeMedecin(['generaliste']));
        $id = $this->api($client)->postJson('/api/v1/consultations', $this->consultationPayload())
            ->assertCreated()->json('data.id');
        $this->api($doctor)->postJson(
            "/api/v1/medecin/consultations/{$id}/offer",
            ['message' => 'Je peux vous recevoir demain matin.'],
        )->assertOk();

        $this->api($client)->postJson("/api/v1/consultations/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.offers.0.status', 'declined');
    }

    public function test_selected_doctor_can_cancel_assigned_consultation(): void
    {
        [$id, , $doctor] = $this->assignedConsultation();

        $this->api($doctor)->postJson("/api/v1/medecin/consultations/{$id}/cancel", [
            'reason' => 'Indisponibilité exceptionnelle',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_doctors_only_see_and_answer_requests_in_their_live_service_area(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $doctorUser = $this->makeMedecin(['generaliste']);
        $doctor = $this->tokenFor($doctorUser);
        $rabatId = $this->api($client)->postJson('/api/v1/consultations', $this->consultationPayload([
            'region' => 'Rabat',
            'latitude' => 34.0209,
            'longitude' => -6.8416,
        ]))->assertCreated()->json('data.id');

        $this->api($doctor)->getJson('/api/v1/medecin/consultations/available')
            ->assertOk()->assertJsonCount(0, 'data');
        $this->api($doctor)->postJson("/api/v1/medecin/consultations/{$rabatId}/offer", [
            'message' => 'Je peux vous recevoir demain matin.',
        ])->assertForbidden()->assertJsonPath('code', 'consultation_outside_service_area');

        $this->api($doctor)->postJson('/api/v1/medecin/location', [
            'latitude' => 34.0209,
            'longitude' => -6.8416,
        ])->assertOk();

        $this->api($doctor)->getJson('/api/v1/medecin/consultations/available')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.region', 'Rabat');
    }

    public function test_doctor_without_a_fresh_location_does_not_receive_requests(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $doctorUser = $this->makeMedecin(['generaliste']);
        $doctorUser->forceFill(['location_updated_at' => now()->subMinutes(5)])->save();
        $doctor = $this->tokenFor($doctorUser);
        $this->api($client)->postJson('/api/v1/consultations', $this->consultationPayload())
            ->assertCreated();

        $this->api($doctor)->getJson('/api/v1/medecin/consultations/available')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array{0: int, 1: string, 2: string} */
    private function assignedConsultation(): array
    {
        $client = $this->tokenFor($this->makeClient());
        $doctor = $this->tokenFor($this->makeMedecin(['generaliste']));
        $id = $this->api($client)->postJson('/api/v1/consultations', $this->consultationPayload())
            ->assertCreated()->json('data.id');
        $offerId = $this->api($doctor)->postJson(
            "/api/v1/medecin/consultations/{$id}/offer",
            ['message' => 'Je peux vous recevoir demain matin.'],
        )->assertOk()->json('data.offers.0.id');

        $this->api($client)->postJson("/api/v1/consultations/{$id}/choose", [
            'offer_id' => $offerId,
        ])->assertOk()->assertJsonPath('data.status', 'assigned');

        return [$id, $client, $doctor];
    }

    /** @return array<string, mixed> */
    private function consultationPayload(array $overrides = []): array
    {
        return array_merge([
            'specialty' => 'generaliste',
            'region' => 'Casablanca',
            'latitude' => 33.5890,
            'longitude' => -7.6328,
        ], $overrides);
    }

    protected function makeMedecin(array $specialties): User
    {
        return User::factory()->role(UserRole::Medecin)->withProfile([
            'specialties' => $specialties,
        ])->create([
            'is_available' => true,
            'latitude' => 33.5890,
            'longitude' => -7.6328,
            'location_updated_at' => now(),
        ]);
    }
}
