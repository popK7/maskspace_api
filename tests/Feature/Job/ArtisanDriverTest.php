<?php

namespace Tests\Feature\Job;

use App\Models\ArtisanDriver;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class ArtisanDriverTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    private function trust($artisan, $driver): void
    {
        ArtisanDriver::create(['artisan_id' => $artisan->id, 'driver_id' => $driver->id]);
    }

    /** @return array{0: \App\Models\User, 1: string, 2: \App\Models\Job} */
    private function assigned(): array
    {
        $client = $this->tokenFor($this->makeClient());
        $artisan = $this->makeArtisan();
        $token = $this->tokenFor($artisan);
        $job = $this->publishJob($client);
        $offerId = $this->api($token)->postJson("/api/v1/artisan/jobs/{$job->id}/offer", [
            'estimated_price_dh' => 150,
        ])->assertCreated()->json('data.id');
        $this->api($client)->postJson("/api/v1/jobs/{$job->id}/choose", ['offer_id' => $offerId])->assertOk();

        return [$artisan, $token, $job->fresh()];
    }

    private function available(string $token): array
    {
        return $this->api($token)->getJson('/api/v1/driver/rides/available')->assertOk()->json('data');
    }

    public function test_artisan_manages_trusted_drivers(): void
    {
        $artisan = $this->makeArtisan();
        $a = $this->tokenFor($artisan);
        $driver = $this->makeDriver();

        $this->api($a)->postJson('/api/v1/artisan/drivers', ['driver_id' => $driver->id])
            ->assertCreated()->assertJsonPath('data.saved', true)->assertJsonPath('data.name', $driver->name);
        $this->api($a)->postJson('/api/v1/artisan/drivers', ['driver_id' => $driver->id])->assertCreated();
        $this->assertSame(1, ArtisanDriver::count());

        $this->api($a)->getJson('/api/v1/artisan/drivers')->assertOk()->assertJsonCount(1, 'data');

        $this->api($a)->postJson('/api/v1/artisan/drivers', ['driver_id' => $this->makeClient()->id])->assertUnprocessable();

        $this->api($a)->deleteJson("/api/v1/artisan/drivers/{$driver->id}")->assertNoContent();
        $this->api($a)->deleteJson("/api/v1/artisan/drivers/{$driver->id}")->assertNoContent();
        $this->assertSame(0, ArtisanDriver::count());

        $this->api($this->tokenFor($driver))->getJson('/api/v1/artisan/drivers')->assertForbidden();
        $this->api($this->tokenFor($this->makeClient()))->getJson('/api/v1/artisan/drivers')->assertForbidden();
    }

    public function test_search_finds_drivers_only_and_masks_phone(): void
    {
        $a = $this->tokenFor($this->makeArtisan());
        $driver = $this->makeDriver(['name' => 'Jean Pierre', 'phone' => '+212698765432']);
        $this->makeArtisan(['plomberie'], ['name' => 'Jean Client']);

        $this->api($a)->getJson('/api/v1/artisan/drivers/search?q=Je')->assertUnprocessable();

        $res = $this->api($a)->getJson('/api/v1/artisan/drivers/search?q=Jean')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($driver->id, $res->json('data.0.id'));
        $this->assertStringNotContainsString('698765432', (string) $res->json('data.0.phone'));
        $this->assertStringEndsWith('32', (string) $res->json('data.0.phone'));
        $this->assertFalse($res->json('data.0.saved'));

        $this->api($a)->getJson('/api/v1/artisan/drivers/search?q='.$driver->email)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_offer_is_exclusive_to_the_chosen_driver(): void
    {
        [$artisan, $a, $job] = $this->assigned();
        $chosen = $this->makeDriver();
        $other = $this->makeDriver();
        $this->trust($artisan, $chosen);
        $c = $this->tokenFor($chosen);
        $o = $this->tokenFor($other);

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'driver_id' => $chosen->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'searching')
            ->assertJsonPath('data.offer.exclusive', true)
            ->assertJsonPath('data.offer.driver_id', $chosen->id);

        $this->api($a)->getJson("/api/v1/artisan/jobs/{$job->id}")
            ->assertOk()
            ->assertJsonPath('data.ride.status', 'searching')
            ->assertJsonPath('data.ride.offer.exclusive', true)
            ->assertJsonPath('data.ride.offer.driver_id', $chosen->id)
            ->assertJsonPath('data.ride.offer.driver_name', $chosen->name);

        $this->assertCount(0, $this->available($o));
        $rideId = Ride::where('job_id', $job->id)->value('id');
        $this->api($o)->postJson("/api/v1/driver/rides/{$rideId}/accept")
            ->assertStatus(409)->assertJsonPath('code', 'ride_reserved');

        $rows = $this->available($c);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['reserved_for_me']);
        $this->assertNotNull($rows[0]['offer_expires_at']);
        $this->api($c)->postJson("/api/v1/driver/rides/{$rideId}/accept")->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_direct_offer_is_visible_even_if_the_driver_is_far(): void
    {
        [$artisan, $a, $job] = $this->assigned();
        $chosen = $this->makeDriver(['latitude' => 34.0209, 'longitude' => -6.8416]);
        $this->trust($artisan, $chosen);

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'driver_id' => $chosen->id,
        ])->assertCreated();

        $this->assertCount(1, $this->available($this->tokenFor($chosen)));
        $this->assertTrue($this->available($this->tokenFor($chosen))[0]['reserved_for_me']);
    }

    public function test_emulator_gps_far_from_the_job_is_replaced_by_artisan_location(): void
    {
        [$artisan, $a, $job] = $this->assigned();

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 37.4219983, 'longitude' => -122.084, 'address' => 'Ma position',
        ])->assertCreated();

        $ride = Ride::where('job_id', $job->id)->first();
        $this->assertEqualsWithDelta((float) $artisan->latitude, (float) $ride->pickup_latitude, 0.001);
        $this->assertEqualsWithDelta((float) $artisan->longitude, (float) $ride->pickup_longitude, 0.001);
    }

    public function test_cannot_offer_to_a_driver_who_is_not_trusted(): void
    {
        [, $a, $job] = $this->assigned();
        $stranger = $this->makeDriver();

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'driver_id' => $stranger->id,
        ])->assertUnprocessable()->assertJsonPath('code', 'driver_not_trusted');
    }

    public function test_expired_offer_becomes_visible_to_everyone_again(): void
    {
        [$artisan, $a, $job] = $this->assigned();
        $chosen = $this->makeDriver();
        $other = $this->makeDriver();
        $this->trust($artisan, $chosen);

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'driver_id' => $chosen->id,
        ])->assertCreated();
        $this->assertCount(0, $this->available($this->tokenFor($other)));

        Ride::where('job_id', $job->id)->update(['offer_expires_at' => now()->subMinute()]);

        $this->assertCount(1, $this->available($this->tokenFor($other)));
        $this->api($this->tokenFor($other))->postJson('/api/v1/driver/rides/'.Ride::where('job_id', $job->id)->value('id').'/accept')->assertOk();
    }

    public function test_driver_can_decline_and_artisan_can_open_the_search(): void
    {
        [$artisan, $a, $job] = $this->assigned();
        $chosen = $this->makeDriver();
        $other = $this->makeDriver();
        $this->trust($artisan, $chosen);
        $c = $this->tokenFor($chosen);
        $o = $this->tokenFor($other);

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'driver_id' => $chosen->id,
        ])->assertCreated();
        $rideId = Ride::where('job_id', $job->id)->value('id');

        $this->api($o)->postJson("/api/v1/driver/rides/{$rideId}/decline")->assertStatus(409);
        $this->api($c)->postJson("/api/v1/driver/rides/{$rideId}/decline")->assertOk();
        $this->assertCount(1, $this->available($o));

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322, 'driver_id' => $chosen->id,
        ])->assertOk();
        $this->assertCount(0, $this->available($o));

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322,
        ])->assertOk()->assertJsonPath('data.offer.exclusive', false);
        $this->assertCount(1, $this->available($o));
    }

    public function test_recent_drivers_are_suggested_until_saved(): void
    {
        [$artisan, $a, $job] = $this->assigned();
        $driver = $this->makeDriver();
        $d = $this->tokenFor($driver);

        $this->api($a)->postJson("/api/v1/artisan/jobs/{$job->id}/ride", [
            'latitude' => 33.5896, 'longitude' => -7.6322,
        ])->assertCreated();
        $rideId = Ride::where('job_id', $job->id)->value('id');
        $this->api($d)->postJson("/api/v1/driver/rides/{$rideId}/accept")->assertOk();
        $this->api($d)->postJson("/api/v1/driver/rides/{$rideId}/pickup")->assertOk();
        $this->api($d)->postJson("/api/v1/driver/rides/{$rideId}/complete")->assertOk();

        $res = $this->api($a)->getJson('/api/v1/artisan/drivers')->assertOk();
        $res->assertJsonCount(0, 'data')->assertJsonCount(1, 'recent');
        $this->assertSame($driver->id, $res->json('recent.0.id'));
        $this->assertSame(1, $res->json('recent.0.rides_together'));

        $this->trust($artisan, $driver);
        $this->api($a)->getJson('/api/v1/artisan/drivers')->assertJsonCount(1, 'data')->assertJsonCount(0, 'recent');
    }
}
