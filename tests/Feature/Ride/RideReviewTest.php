<?php

namespace Tests\Feature\Ride;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\RideOffer;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class RideReviewTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy(PushNotificationService::class);
    }

    public function test_passenger_rates_completed_ride_once(): void
    {
        $passenger = $this->makeClient();
        $driver = $this->makeDriver();
        $ride = $this->ride($passenger, $driver, RideStatus::Completed);
        $token = $this->tokenFor($passenger);

        $this->api($token)
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 4, 'comment' => 'Ponctuel'])
            ->assertOk()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.review', 'Ponctuel');

        $this->api($token)
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 1])
            ->assertStatus(409)
            ->assertJsonPath('code', 'already_reviewed');

        $this->assertSame(4, $ride->fresh()->rating);
    }

    public function test_ride_cannot_be_rated_before_completion(): void
    {
        $passenger = $this->makeClient();
        $ride = $this->ride($passenger, $this->makeDriver(), RideStatus::InProgress);

        $this->api($this->tokenFor($passenger))
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 5])
            ->assertStatus(409)
            ->assertJsonPath('code', 'ride_not_completed');
    }

    public function test_only_the_passenger_can_rate(): void
    {
        $ride = $this->ride($this->makeClient(), $this->makeDriver(), RideStatus::Completed);

        $this->api($this->tokenFor($this->makeClient()))
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 5])
            ->assertNotFound();
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        $passenger = $this->makeClient();
        $ride = $this->ride($passenger, $this->makeDriver(), RideStatus::Completed);

        $this->api($this->tokenFor($passenger))
            ->postJson("/api/v1/rides/{$ride->id}/review", ['rating' => 6])
            ->assertUnprocessable();
    }

    public function test_offers_show_driver_average_rating(): void
    {
        $driver = $this->makeDriver();
        $this->ride($this->makeClient(), $driver, RideStatus::Completed)->forceFill(['rating' => 5])->save();
        $this->ride($this->makeClient(), $driver, RideStatus::Completed)->forceFill(['rating' => 4])->save();
        $unrated = $this->makeDriver();

        $passenger = $this->makeClient();
        $searching = $this->ride($passenger, null, RideStatus::Searching);
        foreach ([[$driver, 3000], [$unrated, 3500]] as [$bidder, $price]) {
            RideOffer::create([
                'ride_id' => $searching->id,
                'driver_id' => $bidder->id,
                'status' => 'pending',
                'price_cents' => $price,
                'eta_minutes' => 5,
                'distance_to_pickup_km' => 1.2,
            ]);
        }

        $this->api($this->tokenFor($passenger))
            ->getJson("/api/v1/rides/{$searching->id}/offers")
            ->assertOk()
            ->assertJsonPath('data.0.driver_id', $driver->id)
            ->assertJsonPath('data.0.rating', 4.5)
            ->assertJsonPath('data.0.rating_count', 2)
            ->assertJsonPath('data.1.driver_id', $unrated->id)
            ->assertJsonPath('data.1.rating', null)
            ->assertJsonPath('data.1.rating_count', 0);
    }

    public function test_driver_dashboard_exposes_real_rating_and_lifetime_earnings(): void
    {
        $driver = $this->makeDriver();
        $this->ride($this->makeClient(), $driver, RideStatus::Completed)->forceFill(['rating' => 5])->save();
        $this->ride($this->makeClient(), $driver, RideStatus::Completed)->forceFill(['rating' => 3])->save();

        $this->api($this->tokenFor($driver))
            ->getJson('/api/v1/driver/dashboard')
            ->assertOk()
            ->assertJsonPath('data.lifetime_rides', 2)
            ->assertJsonPath('data.lifetime_earnings_cents', 8400)
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.rating_count', 2);
    }

    /** @param  array<string, mixed>  $attrs */
    private function ride(User $passenger, ?User $driver, RideStatus $status, array $attrs = []): Ride
    {
        return Ride::create(array_merge([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver?->id,
            'status' => $status,
            'pickup_address' => 'Maarif, Casablanca',
            'pickup_latitude' => 33.5896,
            'pickup_longitude' => -7.6322,
            'dropoff_address' => 'Anfa, Casablanca',
            'dropoff_latitude' => 33.5902,
            'dropoff_longitude' => -7.6269,
            'distance_km' => 4.5,
            'price_cents' => 4200,
            'completed_at' => $status === RideStatus::Completed ? now() : null,
        ], $attrs));
    }
}
