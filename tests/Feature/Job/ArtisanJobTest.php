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

        $this->api($token)->postJson("/api/v1/artisan/jobs/{$job->id}/offer")
            ->assertStatus(409)->assertJsonPath('code', 'artisan_offline');
    }

    public function test_client_chooses_an_offer_then_artisan_goes_and_completes(): void
    {
        [$job, $artisanToken] = $this->assignedJob();

        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/en-route")->assertOk()
            ->assertJsonPath('data.status', 'en_route');
        $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/arrive")->assertOk()
            ->assertJsonPath('data.status', 'arrived');
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
        $driver = $this->tokenFor($this->makeDriver());

        $job = $this->publishJob($client);
        $offerId = $this->api($artisan)->postJson("/api/v1/artisan/jobs/{$job->id}/offer")->assertCreated()->json('data.id');
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

    public function test_client_can_cancel_while_searching_and_cannot_open_a_second_job(): void
    {
        $client = $this->tokenFor($this->makeClient());
        $job = $this->publishJob($client);

        $this->api($client)->postJson('/api/v1/jobs', $this->jobPayload(['note' => 'autre']))
            ->assertStatus(409)->assertJsonPath('code', 'job_already_active');

        $this->api($client)->postJson("/api/v1/jobs/{$job->id}/cancel")->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->api($client)->postJson('/api/v1/jobs', $this->jobPayload())->assertCreated();
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
        $offerId = $this->api($artisanToken)->postJson("/api/v1/artisan/jobs/{$job->id}/offer")->assertCreated()->json('data.id');
        $this->api($clientToken)->postJson("/api/v1/jobs/{$job->id}/choose", ['offer_id' => $offerId])->assertOk()
            ->assertJsonPath('data.status', 'assigned');

        return [$job->fresh(), $artisanToken, $clientToken];
    }
}
