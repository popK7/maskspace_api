<?php

namespace Tests\Feature\Transfer;

use App\Enums\TransferStatus;
use App\Models\MoneyTransfer;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\Concerns\InteractsWithTransfer;
use Tests\TestCase;

class MoneyTransferTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, InteractsWithTransfer, RefreshDatabase;

    public function test_client_publishes_a_transfer_and_matching_changeur_sees_it(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $senegal = $this->tokenFor($this->makeChangeur(['senegal']));
        $france = $this->tokenFor($this->makeChangeur(['france']));

        $transfer = $this->publishTransfer($client);

        $this->api($senegal)->getJson('/api/v1/changeur/transfers/available')->assertOk()
            ->assertJsonPath('data.0.id', $transfer->id)
            ->assertJsonPath('data.0.destination_country', 'senegal')
            ->assertJsonPath('data.0.amount', 150000)
            ->assertJsonMissingPath('data.0.client.phone');

        $this->api($france)->getJson('/api/v1/changeur/transfers/available')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_offline_changeur_does_not_see_transfers_and_cannot_offer(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $changeur = $this->makeChangeur();
        $token = $this->tokenFor($changeur);
        $transfer = $this->publishTransfer($client);

        $this->api($token)->patchJson('/api/v1/changeur/availability', ['is_available' => false])
            ->assertOk()->assertJsonPath('data.is_available', false);

        $this->api($token)->getJson('/api/v1/changeur/transfers/available')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.is_available', false);

        $this->api($token)->postJson("/api/v1/changeur/transfers/{$transfer->id}/offer")
            ->assertStatus(409)->assertJsonPath('code', 'changeur_offline');
    }

    public function test_client_chooses_an_offer_then_either_party_can_meet_and_complete(): void
    {
        [$transfer, $changeurToken, $clientToken] = $this->assignedTransfer();

        $this->api($changeurToken)->postJson("/api/v1/changeur/transfers/{$transfer->id}/meet")->assertOk()
            ->assertJsonPath('data.status', 'meeting');
        $this->api($clientToken)->postJson("/api/v1/transfers/{$transfer->id}/complete")->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(TransferStatus::Completed, $transfer->fresh()->status);
    }

    public function test_client_or_changeur_can_call_a_driver_to_meet(): void
    {
        $clientUser = $this->makeClient();
        $client = $this->tokenFor($clientUser);
        $changeurUser = $this->makeChangeur();
        $changeur = $this->tokenFor($changeurUser);
        $driver = $this->tokenFor($this->makeDriver());

        $transfer = $this->publishTransfer($client);
        $offerId = $this->api($changeur)->postJson("/api/v1/changeur/transfers/{$transfer->id}/offer")
            ->assertCreated()->json('data.id');
        $this->api($client)->postJson("/api/v1/transfers/{$transfer->id}/choose", ['offer_id' => $offerId])->assertOk();

        $this->api($client)->postJson("/api/v1/transfers/{$transfer->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'address' => 'Maârif',
        ])->assertCreated()->assertJsonPath('data.status', 'searching');

        $this->api($client)->getJson("/api/v1/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.ride.status', 'searching');

        $rideId = Ride::where('transfer_id', $transfer->id)->value('id');
        $this->api($driver)->getJson('/api/v1/driver/rides/available')->assertOk()
            ->assertJsonPath('data.0.id', $rideId)
            ->assertJsonPath('data.0.transfer_id', $transfer->id);

        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/pickup")->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertSame(TransferStatus::Meeting, $transfer->fresh()->status);

        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/complete")->assertOk();
        $this->assertSame(TransferStatus::Meeting, $transfer->fresh()->status);

        $this->api($changeur)->postJson("/api/v1/changeur/transfers/{$transfer->id}/complete")->assertOk()
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_changeur_can_also_request_a_ride(): void
    {
        [$transfer, $changeurToken] = $this->assignedTransfer();

        $this->api($changeurToken)->postJson("/api/v1/changeur/transfers/{$transfer->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'address' => 'Derb Omar',
        ])->assertCreated()->assertJsonPath('data.status', 'searching');

        $this->assertNotNull(Ride::where('transfer_id', $transfer->id)->first());
    }

    public function test_client_can_cancel_while_searching_and_cannot_open_a_second_transfer(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $transfer = $this->publishTransfer($client);

        $this->api($client)->postJson('/api/v1/transfers', $this->transferPayload(['note' => 'autre']))
            ->assertStatus(409)->assertJsonPath('code', 'transfer_already_active');

        $this->api($client)->postJson("/api/v1/transfers/{$transfer->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->api($client)->postJson('/api/v1/transfers', $this->transferPayload())->assertCreated();
    }

    public function test_matched_parties_can_chat(): void
    {
        [$transfer, $changeurToken, $clientToken] = $this->assignedTransfer();

        $threads = $this->api($clientToken)->getJson("/api/v1/chats?subject_type=transfer&subject_id={$transfer->id}")
            ->assertOk()->assertJsonCount(1, 'data')->json('data');
        $this->assertSame('customer_changeur', $threads[0]['channel']);

        $this->api($clientToken)->postJson("/api/v1/chats/{$threads[0]['id']}/messages", ['body' => 'On se voit à 18h ?'])
            ->assertCreated();
        $this->api($changeurToken)->getJson("/api/v1/chats/{$threads[0]['id']}")
            ->assertOk()->assertJsonPath('data.messages.0.body', 'On se voit à 18h ?');
    }

    public function test_client_reviews_a_completed_transfer(): void
    {
        [$transfer, $changeurToken, $clientToken] = $this->assignedTransfer();
        $this->api($changeurToken)->postJson("/api/v1/changeur/transfers/{$transfer->id}/meet")->assertOk();
        $this->api($changeurToken)->postJson("/api/v1/changeur/transfers/{$transfer->id}/complete")->assertOk();

        $this->api($clientToken)->postJson("/api/v1/transfers/{$transfer->id}/review", ['rating' => 5, 'comment' => 'Ponctuel'])
            ->assertOk()->assertJsonPath('data.rating', 5);
    }

    public function test_wrong_roles_are_forbidden(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $transfer = $this->publishTransfer($client);
        $driver = $this->tokenFor($this->makeDriver());

        $this->api($driver)->getJson('/api/v1/changeur/transfers/available')->assertForbidden();
        $this->api($this->tokenFor($this->makeChangeur()))->postJson('/api/v1/transfers', $this->transferPayload())->assertForbidden();
        $this->api($driver)->postJson("/api/v1/changeur/transfers/{$transfer->id}/offer")->assertForbidden();
    }

    /** @return array{0: MoneyTransfer, 1: string, 2?: string} */
    private function assignedTransfer(): array
    {
        $clientToken = $this->tokenFor($this->makeClient());
        $changeurToken = $this->tokenFor($this->makeChangeur());
        $transfer = $this->publishTransfer($clientToken);
        $offerId = $this->api($changeurToken)->postJson("/api/v1/changeur/transfers/{$transfer->id}/offer")
            ->assertCreated()->json('data.id');
        $this->api($clientToken)->postJson("/api/v1/transfers/{$transfer->id}/choose", ['offer_id' => $offerId])->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        return [$transfer->fresh(), $changeurToken, $clientToken];
    }
}
