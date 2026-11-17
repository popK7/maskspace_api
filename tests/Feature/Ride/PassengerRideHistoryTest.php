<?php

namespace Tests\Feature\Ride;

use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class PassengerRideHistoryTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    public function test_passenger_lists_only_own_rides_newest_first(): void
    {
        $driver = $this->makeDriver();
        $passenger = $this->makeClient();
        $other = $this->makeClient();

        $older = $this->ride($passenger, ['driver_id' => $driver->id, 'status' => RideStatus::Completed, 'completed_at' => now()]);
        $newer = $this->ride($passenger, ['status' => RideStatus::Cancelled, 'cancelled_at' => now()]);
        $this->ride($other);

        $this->api($this->tokenFor($passenger))
            ->getJson('/api/v1/rides')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.status', 'cancelled')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.1.status', 'completed')
            ->assertJsonPath('data.1.driver.id', $driver->id)
            ->assertJsonPath('data.1.dropoff.address', 'Anfa, Casablanca')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_ride_history_requires_authentication(): void
    {
        $this->api()->getJson('/api/v1/rides')->assertUnauthorized();
    }

    /** @param  array<string, mixed>  $attrs */
    private function ride(User $passenger, array $attrs = []): Ride
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
