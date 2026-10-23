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

    public function test_changeur_only_sees_and_can_offer_transfers_inside_the_current_zone(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $changeurUser = $this->makeChangeur();
        $changeur = $this->tokenFor($changeurUser);
        $transfer = $this->publishTransfer($client);

        $changeurUser->forceFill([
            'latitude' => 34.0209,
            'longitude' => -6.8416,
            'location_updated_at' => now(),
        ])->save();

        $this->api($changeur)->getJson('/api/v1/changeur/transfers/available')->assertOk()
            ->assertJsonCount(0, 'data');
        $this->api($changeur)->postJson(
            "/api/v1/changeur/transfers/{$transfer->id}/offer",
            $this->quotePayload(),
        )
            ->assertStatus(409)->assertJsonPath('code', 'outside_service_area');
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

        $this->api($token)->postJson(
            "/api/v1/changeur/transfers/{$transfer->id}/offer",
            $this->quotePayload(),
        )
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
        $offerId = $this->api($changeur)->postJson(
            "/api/v1/changeur/transfers/{$transfer->id}/offer",
            $this->quotePayload(),
        )
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

    public function test_client_can_open_multiple_transfers_and_cancel_each_independently(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $transfer = $this->publishTransfer($client);

        $secondTransferId = $this->api($client)->postJson('/api/v1/transfers', $this->transferPayload(['note' => 'autre']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'searching')
            ->json('data.id');
        $this->assertNotSame($transfer->id, $secondTransferId);
        $this->assertDatabaseHas('money_transfers', ['id' => $transfer->id, 'status' => 'searching']);
        $this->assertDatabaseHas('money_transfers', ['id' => $secondTransferId, 'status' => 'searching']);

        $this->api($client)->postJson("/api/v1/transfers/{$transfer->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->api($client)->getJson("/api/v1/transfers/{$secondTransferId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
    }

    public function test_client_can_cancel_a_transfer_after_the_meeting_has_started(): void
    {
        [$transfer, $changeurToken, $clientToken] = $this->assignedTransfer();
        $this->api($changeurToken)->postJson("/api/v1/changeur/transfers/{$transfer->id}/meet")
            ->assertOk()->assertJsonPath('data.status', 'meeting');

        $this->api($clientToken)->postJson("/api/v1/transfers/{$transfer->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_changeur_quotes_are_returned_to_the_client_with_net_amount(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $changeur = $this->tokenFor($this->makeChangeur());
        $transfer = $this->publishTransfer($client);

        $offerId = $this->api($changeur)->postJson(
            "/api/v1/changeur/transfers/{$transfer->id}/offer",
            $this->quotePayload(['exchange_rate' => '1.25', 'fee_amount' => 10000]),
        )->assertCreated()
            ->assertJsonPath('data.exchange_rate', 1.25)
            ->assertJsonPath('data.fee_amount', 10000)
            ->assertJsonPath('data.recipient_amount', 175000)
            ->json('data.id');

        $this->api($client)->getJson("/api/v1/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.offers.0.id', $offerId)
            ->assertJsonPath('data.offers.0.exchange_rate', 1.25)
            ->assertJsonPath('data.offers.0.fee_amount', 10000)
            ->assertJsonPath('data.offers.0.recipient_amount', 175000)
            ->assertJsonPath('data.offers.0.recipient_currency', 'XOF');

        $this->api($client)->postJson("/api/v1/transfers/{$transfer->id}/choose", [
            'offer_id' => $offerId,
        ])->assertOk();
        $this->api($client)->getJson('/api/v1/transfers?per_page=50')
            ->assertOk()
            ->assertJsonPath('data.0.offers.0.status', 'chosen')
            ->assertJsonPath('data.0.offers.0.exchange_rate', 1.25)
            ->assertJsonPath('data.0.offers.0.fee_amount', 10000)
            ->assertJsonPath('data.0.offers.0.recipient_amount', 175000);
    }

    public function test_changeur_manages_pricing_per_corridor_and_source_currency(): void
    {
        $changeurUser = $this->makeChangeur(['senegal', 'france']);
        $changeur = $this->tokenFor($changeurUser);

        $this->api($changeur)->getJson('/api/v1/changeur/transfer-pricing')
            ->assertOk()
            ->assertJsonPath('data.corridors.0', 'senegal')
            ->assertJsonPath('data.quotes', []);

        $quotes = [
            'senegal' => [
                'MAD' => ['exchange_rate' => '0.065', 'fee_amount' => 20],
                'EUR' => ['exchange_rate' => '0.071', 'fee_amount' => 2],
            ],
            'france' => [
                'MAD' => ['exchange_rate' => '0.11', 'fee_amount' => 25],
            ],
        ];
        $this->api($changeur)->putJson('/api/v1/changeur/transfer-pricing', ['quotes' => $quotes])
            ->assertOk()
            ->assertJsonPath('data.quotes.senegal.MAD.exchange_rate', 0.065)
            ->assertJsonPath('data.quotes.senegal.MAD.fee_amount', 20)
            ->assertJsonPath('data.quotes.france.MAD.exchange_rate', 0.11);

        $client = $this->tokenFor($this->makeClient());
        $transfer = $this->publishTransfer($client, [
            'currency' => 'MAD',
            'amount' => 500,
        ]);
        $this->api($changeur)->getJson('/api/v1/changeur/transfers/available')
            ->assertOk()
            ->assertJsonPath('data.0.id', $transfer->id)
            ->assertJsonPath('data.0.default_exchange_rate', 0.065)
            ->assertJsonPath('data.0.default_fee_amount', 20);

        $this->api($changeur)->putJson('/api/v1/changeur/transfer-pricing', [
            'quotes' => [
                'mali' => ['MAD' => ['exchange_rate' => '0.065', 'fee_amount' => 20]],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('quotes.mali');
    }

    public function test_changeur_quote_rejects_nonpositive_rates_and_fees_at_or_above_amount(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $changeur = $this->tokenFor($this->makeChangeur());
        $transfer = $this->publishTransfer($client);
        $endpoint = "/api/v1/changeur/transfers/{$transfer->id}/offer";

        $this->api($changeur)->postJson($endpoint, $this->quotePayload(['exchange_rate' => '0']))
            ->assertUnprocessable()->assertJsonValidationErrors('exchange_rate');
        $this->api($changeur)->postJson($endpoint, $this->quotePayload(['fee_amount' => 150000]))
            ->assertUnprocessable()->assertJsonValidationErrors('fee_amount');
        $this->api($changeur)->postJson($endpoint, $this->quotePayload(['fee_amount' => 150001]))
            ->assertUnprocessable()->assertJsonValidationErrors('fee_amount');

        $this->assertDatabaseCount('transfer_offers', 0);
    }

    public function test_client_can_cancel_a_ride_after_pickup(): void
    {
        [$transfer, , $clientToken] = $this->assignedTransfer();
        $driverToken = $this->tokenFor($this->makeDriver());
        $this->api($clientToken)->postJson("/api/v1/transfers/{$transfer->id}/ride", [
            'latitude' => 33.5896,
            'longitude' => -7.6322,
            'address' => 'Derb Omar',
        ])->assertCreated();
        $rideId = Ride::where('transfer_id', $transfer->id)->value('id');
        $this->api($driverToken)->postJson("/api/v1/driver/rides/{$rideId}/accept")->assertOk();
        $this->api($driverToken)->postJson("/api/v1/driver/rides/{$rideId}/pickup")
            ->assertOk()->assertJsonPath('data.status', 'in_progress');

        $this->api($clientToken)->postJson("/api/v1/rides/{$rideId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
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
        $offerId = $this->api($changeurToken)->postJson(
            "/api/v1/changeur/transfers/{$transfer->id}/offer",
            $this->quotePayload(),
        )
            ->assertCreated()->json('data.id');
        $this->api($clientToken)->postJson("/api/v1/transfers/{$transfer->id}/choose", ['offer_id' => $offerId])->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        return [$transfer->fresh(), $changeurToken, $clientToken];
    }

    /** @return array{exchange_rate: string, fee_amount: int} */
    private function quotePayload(array $overrides = []): array
    {
        return array_merge(['exchange_rate' => '1.25', 'fee_amount' => 1000], $overrides);
    }
}
