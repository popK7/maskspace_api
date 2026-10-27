<?php

namespace Tests\Feature\Job;

use App\Enums\JobStatus;
use App\Enums\RideStatus;
use App\Models\Job;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class ArtisanJobTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    public function test_client_publishes_a_job_and_artisan_matching_the_trade_sees_it(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $plumber = $this->tokenFor($this->makeArtisan(['plomberie']));
        $painter = $this->tokenFor($this->makeArtisan(['peinture']));

        $job = $this->publishJob($client);

        $this->api($plumber)->getJson('/api/v1/artisan/jobs/available')->assertOk()
            ->assertJsonPath('data.0.id', $job->id)
            ->assertJsonPath('data.0.trade', 'plomberie')
            ->assertJsonMissingPath('data.0.client.phone');

        $this->api($painter)->getJson('/api/v1/artisan/jobs/available')->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_offline_artisan_does_not_see_jobs_and_cannot_offer(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $artisan = $this->makeArtisan();
        $token = $this->tokenFor($artisan);
        $job = $this->publishJob($client);

        $this->api($token)->patchJson('/api/v1/artisan/availability', ['is_available' => false])
            ->assertOk()->assertJsonPath('data.is_available', false);

        $this->api($token)->getJson('/api/v1/artisan/jobs/available')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.is_available', false);

        $this->api($token)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", ['estimated_price_dh' => 150])
            ->assertStatus(409)->assertJsonPath('code', 'artisan_offline');
    }

    public function test_artisan_estimate_is_required_and_visible_to_the_client(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $artisan = $this->tokenFor($this->makeArtisan());
        $job = $this->publishJob($client);

        $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/offer")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estimated_price_dh');
        $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", [
            'estimated_price_dh' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('estimated_price_dh');

        $offerId = $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", [
            'estimated_price_dh' => 275,
        ])->assertCreated()
            ->assertJsonPath('data.estimated_price_dh', 275)
            ->json('data.id');

        $this->api($client)->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.offers.0.estimated_price_dh', 275);
        $this->api($client)->postJson("/api/v1/jobs/{$job->id}/choose", [
            'offer_id' => $offerId,
        ])->assertOk()
            ->assertJsonPath('data.offers.0.estimated_price_dh', 275)
            ->assertJsonPath('data.final_price_status', 'not_submitted');
    }

    public function test_artisan_only_sees_jobs_in_the_current_service_zone(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $artisan = $this->makeArtisan();
        $token = $this->tokenFor($artisan);
        $job = $this->publishJob($client);

        $artisan->forceFill([
            'latitude' => 34.0209,
            'longitude' => -6.8416,
            'location_updated_at' => now(),
        ])->save();

        $this->api($token)->getJson('/api/v1/artisan/jobs/available')->assertOk()
            ->assertJsonCount(0, 'data');
        $this->api($token)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", ['estimated_price_dh' => 150])
            ->assertStatus(409)->assertJsonPath('code', 'outside_service_area');

        $artisan->forceFill([
            'latitude' => 33.5896,
            'longitude' => -7.6322,
            'location_updated_at' => now()->subMinutes(5),
        ])->save();
        $this->api($token)->getJson('/api/v1/artisan/jobs/available')->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.location_required', true);

        $this->assertSame('searching', $job->fresh()->status->value);
    }

    public function test_client_chooses_an_offer_then_artisan_goes_and_completes(): void
    {
        [$job, $artisanToken, $clientToken] = $this->assignedJob();

        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/en-route")->assertOk()
            ->assertJsonPath('data.status', 'en_route');
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/arrive")->assertOk()
            ->assertJsonPath('data.status', 'arrived');
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/start")
            ->assertStatus(409)->assertJsonPath('code', 'final_price_not_accepted');
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/final-price", [
            'final_price_dh' => 325,
        ])->assertOk()
            ->assertJsonPath('data.final_price_dh', 325)
            ->assertJsonPath('data.final_price_status', 'pending');
        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/accept-final-price")
            ->assertOk()->assertJsonPath('data.final_price_status', 'accepted');
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/start")->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/complete")->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(JobStatus::Completed, $job->fresh()->status);
    }

    public function test_artisan_calls_a_driver_to_reach_the_client(): void
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
        ])->assertCreated()->assertJsonPath('data.status', 'searching');

        $this->api($artisan)->getJson("/api/v1/artisan/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.ride.status', 'searching')
            ->assertJsonPath('data.ride.offer.exclusive', false);
        $this->api($artisan)->getJson('/api/v1/artisan/jobs')
            ->assertOk()
            ->assertJsonPath('data.0.ride.status', 'searching');

        $rideId = Ride::where('job_id', $job->id)->value('id');
        $this->api($artisan)->getJson("/api/v1/rides/{$rideId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
        $driverUser->forceFill([
            'latitude' => 34.0209,
            'longitude' => -6.8416,
            'location_updated_at' => now(),
        ])->save();
        $this->api($driver)->getJson('/api/v1/driver/rides/available')->assertOk()
            ->assertJsonCount(0, 'data');
        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'outside_service_area');
        $driverUser->forceFill([
            'latitude' => 33.5902,
            'longitude' => -7.6269,
            'location_updated_at' => now(),
        ])->save();
        $this->api($driver)->getJson('/api/v1/driver/rides/available')->assertOk()
            ->assertJsonPath('data.0.id', $rideId);

        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/pickup")->assertOk()
            ->assertJsonPath('data.status', 'in_progress');
        $this->assertSame(JobStatus::EnRoute, $job->fresh()->status);

        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/complete")->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame(JobStatus::Arrived, $job->fresh()->status);

        $this->api($client)->getJson("/api/v1/jobs/{$job->id}/tracking")->assertOk()
            ->assertJsonPath('data.status', 'arrived');
    }

    public function test_client_receives_real_driver_price_offers_and_can_choose_one(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $driverUser = $this->makeDriver();
        $driver = $this->tokenFor($driverUser);

        $rideId = $this->api($client)->postJson('/api/v1/rides', [
            'pickup_address' => 'Maârif, Casablanca',
            'pickup_latitude' => 33.5896,
            'pickup_longitude' => -7.6322,
            'dropoff_address' => 'Gauthier, Casablanca',
            'dropoff_latitude' => 33.5922,
            'dropoff_longitude' => -7.6250,
        ])->assertCreated()->json('data.id');

        $this->api($driver)->postJson("/api/v1/driver/rides/{$rideId}/offer", [
            'price_cents' => 4200,
        ])->assertCreated()->assertJsonPath('data.price_cents', 4200);

        $this->api($driver)->getJson('/api/v1/driver/rides/available')
            ->assertOk()
            ->assertJsonPath('data.0.my_offer.status', 'pending')
            ->assertJsonPath('data.0.my_offer.price_cents', 4200);

        $offerId = $this->api($client)->getJson("/api/v1/rides/{$rideId}/offers")
            ->assertOk()
            ->assertJsonPath('data.0.driver_name', $driverUser->name)
            ->assertJsonPath('data.0.vehicle', 'Toyota Corolla')
            ->assertJsonPath('data.0.price_cents', 4200)
            ->json('data.0.id');

        $this->api($client)->postJson("/api/v1/rides/{$rideId}/offers/{$offerId}/choose")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.price_cents', 4200)
            ->assertJsonPath('data.driver.id', $driverUser->id);
    }

    public function test_client_can_open_multiple_rides(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $payload = [
            'pickup_address' => 'Maârif, Casablanca',
            'pickup_latitude' => 33.5896,
            'pickup_longitude' => -7.6322,
            'dropoff_address' => 'Gauthier, Casablanca',
            'dropoff_latitude' => 33.5922,
            'dropoff_longitude' => -7.6250,
        ];

        $firstId = $this->api($client)->postJson('/api/v1/rides', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');
        $secondId = $this->api($client)->postJson('/api/v1/rides', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'searching')->json('data.id');

        $this->assertNotSame($firstId, $secondId);
        $this->api($client)->getJson("/api/v1/rides/{$firstId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
        $this->api($client)->getJson("/api/v1/rides/{$secondId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
    }

    public function test_client_can_open_multiple_jobs_and_cancel_each_independently(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $job = $this->publishJob($client);

        $secondJobId = $this->api($client)->postJson('/api/v1/jobs', $this->jobPayload(['note' => 'autre']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'searching')
            ->json('data.id');
        $this->assertNotSame($job->id, $secondJobId);
        $this->api($client)->getJson("/api/v1/jobs/{$job->id}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
        $this->api($client)->getJson("/api/v1/jobs/{$secondJobId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');

        $this->api($client)->postJson("/api/v1/jobs/{$job->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->api($client)->getJson("/api/v1/jobs/{$secondJobId}")
            ->assertOk()->assertJsonPath('data.status', 'searching');
    }

    public function test_client_can_cancel_a_job_after_work_has_started(): void
    {
        [$job, $artisanToken, $clientToken] = $this->assignedJob();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/arrive")->assertOk();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/final-price", [
            'final_price_dh' => 250,
        ])->assertOk();
        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/accept-final-price")->assertOk();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/start")->assertOk();

        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_wrong_roles_are_forbidden(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $job = $this->publishJob($client);
        $driver = $this->tokenFor($this->makeDriver());

        $this->api($driver)->getJson('/api/v1/artisan/jobs/available')->assertForbidden();
        $this->api($client)->getJson('/api/v1/driver/rides/available')->assertForbidden();
        $this->api($this->tokenFor($this->makeArtisan()))->postJson('/api/v1/jobs', $this->jobPayload())->assertForbidden();
        $this->api($driver)->postJson("/api/v1/artisan/jobs/{$job->id}/offer")->assertForbidden();
    }

    public function test_client_reviews_a_completed_job(): void
    {
        [$job, $artisanToken, $clientToken] = $this->assignedJob();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/en-route")->assertOk();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/arrive")->assertOk();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/final-price", [
            'final_price_dh' => 250,
        ])->assertOk();
        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/accept-final-price")->assertOk();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/start")->assertOk();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/complete")->assertOk();

        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/review", ['rating' => 5, 'comment' => 'Rapide et propre'])
            ->assertOk()->assertJsonPath('data.rating', 5);
    }

    public function test_tracking_exposes_artisan_gps_to_the_client(): void
    {
        [$job, $artisanToken, $clientToken] = $this->assignedJob();
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/en-route")->assertOk();
        $this->api($artisanToken)->postJson('/api/v1/artisan/location', [
            'latitude' => 33.588, 'longitude' => -7.62,
        ])->assertOk();

        $this->api($clientToken)->getJson("/api/v1/jobs/{$job->id}/tracking")->assertOk()
            ->assertJsonPath('data.status', 'en_route')
            ->assertJsonPath('data.artisan.position.latitude', 33.588);
    }

    /** @return array{0: Job, 1: string, 2?: string} */
    private function assignedJob(): array
    {
        $clientToken = $this->tokenFor($this->makeClient());
        $artisanToken = $this->tokenFor($this->makeArtisan());
        $job = $this->publishJob($clientToken);
        $offerId = $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", [
            'estimated_price_dh' => 150,
        ])->assertCreated()->json('data.id');
        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/choose", ['offer_id' => $offerId])->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        return [$job->fresh(), $artisanToken, $clientToken];
    }
}
