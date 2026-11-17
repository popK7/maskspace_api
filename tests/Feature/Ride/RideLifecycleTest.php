<?php

namespace Tests\Feature\Ride;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\RideOffer;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class RideLifecycleTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    private MockInterface $push;

    protected function setUp(): void
    {
        parent::setUp();
        $this->push = $this->spy(PushNotificationService::class);
    }

    public function test_passenger_cancel_declines_pending_offers_and_notifies_bidders(): void
    {
        $passenger = $this->makeClient();
        $bidder = $this->makeDriver();
        $ride = $this->searchingRide($passenger);
        $offer = RideOffer::create([
            'ride_id' => $ride->id,
            'driver_id' => $bidder->id,
            'status' => 'pending',
            'price_cents' => 3000,
            'eta_minutes' => 5,
            'distance_to_pickup_km' => 1.2,
        ]);

        $this->api($this->tokenFor($passenger))
            ->postJson("/api/v1/rides/{$ride->id}/cancel", ['reason' => 'Plus besoin'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('declined', $offer->fresh()->status);
        $this->push->shouldHaveReceived('sendToUser')
            ->withArgs(fn (?User $u, string $title) => $u?->is($bidder) && $title === 'Course annulée')
            ->once();
        $this->push->shouldNotHaveReceived('sendToUser', [
            \Mockery::on(fn ($u) => $u instanceof User && $u->is($passenger)),
            \Mockery::any(), \Mockery::any(), \Mockery::any(),
        ]);
    }

    public function test_driver_cancel_notifies_passenger(): void
    {
        $passenger = $this->makeClient();
        $driver = $this->makeDriver();
        $ride = $this->searchingRide($passenger, [
            'driver_id' => $driver->id,
            'status' => RideStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $this->api($this->tokenFor($driver))
            ->postJson("/api/v1/driver/rides/{$ride->id}/cancel")
            ->assertOk();

        $this->push->shouldHaveReceived('sendToUser')
            ->withArgs(fn (?User $u, string $title) => $u?->is($passenger) && $title === 'Course annulée')
            ->once();
    }

    public function test_stale_searching_rides_expire_and_passenger_is_notified(): void
    {
        $passenger = $this->makeClient();
        $other = $this->makeClient();
        $bidder = $this->makeDriver();
        $stale = $this->searchingRide($passenger);
        $stale->forceFill(['created_at' => now()->subMinutes(20)])->save();
        RideOffer::create([
            'ride_id' => $stale->id,
            'driver_id' => $bidder->id,
            'status' => 'pending',
            'price_cents' => 3000,
            'eta_minutes' => 5,
            'distance_to_pickup_km' => 1.2,
        ]);
        $fresh = $this->searchingRide($other);

        $this->artisan('makspace:expire-rides')->assertSuccessful();

        $this->assertSame(RideStatus::Cancelled, $stale->fresh()->status);
        $this->assertSame(RideStatus::Searching, $fresh->fresh()->status);
        $this->assertSame(0, RideOffer::where('ride_id', $stale->id)->where('status', 'pending')->count());
        $this->push->shouldHaveReceived('sendToUser')
            ->withArgs(fn (?User $u, string $title) => $u?->is($passenger) && $title === 'Course annulée')
            ->once();
        $this->push->shouldHaveReceived('sendToUser')
            ->withArgs(fn (?User $u, string $title) => $u?->is($bidder) && $title === 'Course annulée')
            ->once();
    }

    public function test_active_direct_offer_is_not_expired(): void
    {
        $passenger = $this->makeClient();
        $driver = $this->makeDriver();
        $ride = $this->searchingRide($passenger, [
            'offered_driver_id' => $driver->id,
            'offer_expires_at' => now()->addMinutes(5),
        ]);
        $ride->forceFill(['created_at' => now()->subMinutes(20)])->save();

        $this->artisan('makspace:expire-rides')->assertSuccessful();

        $this->assertSame(RideStatus::Searching, $ride->fresh()->status);
    }

    public function test_accept_requires_a_fresh_location_even_for_a_direct_offer(): void
    {
        $passenger = $this->makeClient();
        $driver = $this->makeDriver(['location_updated_at' => now()->subMinutes(10)]);
        $direct = $this->searchingRide($passenger, [
            'offered_driver_id' => $driver->id,
            'offer_expires_at' => now()->addMinutes(5),
        ]);
        $open = $this->searchingRide($passenger);

        foreach ([$direct, $open] as $ride) {
            $this->api($this->tokenFor($driver))
                ->postJson("/api/v1/driver/rides/{$ride->id}/accept")
                ->assertStatus(409)
                ->assertJsonPath('code', 'location_required');
            $this->assertSame(RideStatus::Searching, $ride->fresh()->status);
        }

        $driver->forceFill(['location_updated_at' => now()])->save();
        $this->api($this->tokenFor($driver))
            ->postJson("/api/v1/driver/rides/{$direct->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
    }

    public function test_driver_cannot_hold_two_rides_at_once(): void
    {
        $driver = $this->makeDriver();
        $first = $this->searchingRide($this->makeClient());
        $second = $this->searchingRide($this->makeClient());

        $this->api($this->tokenFor($driver))->postJson("/api/v1/driver/rides/{$first->id}/accept")->assertOk();
        $this->api($this->tokenFor($driver))->postJson("/api/v1/driver/rides/{$second->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'ride_already_active');
    }

    /** @param  array<string, mixed>  $attrs */
    private function searchingRide(User $passenger, array $attrs = []): Ride
    {
        return Ride::create(array_merge([
            'passenger_id' => $passenger->id,
            'status' => RideStatus::Searching,
            'pickup_address' => 'Maarif, Casablanca',
            'pickup_latitude' => 33.5896,
            'pickup_longitude' => -7.6322,
            'dropoff_address' => 'Anfa, Casablanca',
            'dropoff_latitude' => 33.5902,
            'dropoff_longitude' => -7.6269,
            'distance_km' => 4.5,
            'price_cents' => 4200,
        ], $attrs));
    }
}
