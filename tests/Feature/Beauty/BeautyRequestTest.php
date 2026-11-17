<?php

namespace Tests\Feature\Beauty;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class BeautyRequestTest extends TestCase
{
    use InteractsWithAuth, InteractsWithShop, RefreshDatabase;

    public function test_client_can_open_multiple_beauty_requests(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $payload = ['specialty' => 'coiffeur', 'mode' => 'salon'];

        $firstId = $this->api($client)->postJson('/api/v1/beauty-requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');
        $secondId = $this->api($client)->postJson('/api/v1/beauty-requests', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->api($client)->getJson("/api/v1/beauty-requests/{$firstId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
        $this->api($client)->getJson("/api/v1/beauty-requests/{$secondId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
    }

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

    public function test_pro_sees_the_nearest_clients_first_and_only_within_reach(): void
    {
        $push = $this->spy(PushNotificationService::class);
        $pro = $this->makeBeaute(['coiffeur']);
        $pro->forceFill(['latitude' => 33.5890, 'longitude' => -7.6200, 'location_updated_at' => now()])->save();
        $farPro = $this->makeBeaute(['coiffeur']);
        $farPro->forceFill(['latitude' => 34.0209, 'longitude' => -6.8416, 'location_updated_at' => now()])->save(); // Rabat
        $client = $this->tokenFor($this->makeClient());

        $publish = fn (float $lat, float $lng) => $this->api($client)->postJson('/api/v1/beauty-requests', [
            'specialty' => 'coiffeur', 'mode' => 'salon', 'latitude' => $lat, 'longitude' => $lng,
        ])->assertCreated()->json('data.id');
        $farther = $publish(33.5890, -7.6700); // ~4,6 km
        $closest = $publish(33.5895, -7.6210); // ~0,1 km
        $outside = $publish(34.0300, -6.8400); // Rabat, hors rayon du pro

        $this->api($this->tokenFor($pro))->getJson('/api/v1/beaute/requests/available')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $closest)
            ->assertJsonPath('data.1.id', $farther);
        $this->api($this->tokenFor($pro))->postJson("/api/v1/beaute/requests/{$outside}/offer", [
            'message' => 'Je peux venir demain matin.',
        ])->assertForbidden()->assertJsonPath('code', 'beauty_outside_service_area');

        $push->shouldHaveReceived('sendToUser')
            ->withArgs(fn (?User $to, string $title) => $to?->is($pro) && $title === 'Nouvelle demande beauté')
            ->twice();
        $push->shouldHaveReceived('sendToUser')
            ->withArgs(fn (?User $to, string $title) => $to?->is($farPro) && $title === 'Nouvelle demande beauté')
            ->once();
    }

    protected function makeBeaute(array $specialties): User
    {
        return User::factory()->role(UserRole::Beaute)->withProfile([
            'specialties' => $specialties,
        ])->create(['is_available' => true]);
    }
}
