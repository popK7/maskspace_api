<?php

namespace Tests\Feature\Notifications;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Dispatch\RideService;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class ActiveRoleDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ride_requests_are_only_sent_to_transporters_in_the_active_role(): void
    {
        $inactiveRoleUser = User::factory()->withProfile()->create([
            'is_visible' => true,
            'is_available' => true,
            'latitude' => 33.5899,
            'longitude' => -7.6039,
            'location_updated_at' => now(),
        ]);
        $inactiveRoleUser->profileForRole(UserRole::Driver)->create([
            'role' => UserRole::Driver->value,
            'vehicle_model' => 'Toyota Corolla',
            'vehicle_plate' => 'A 12345',
        ]);
        DB::table('user_roles')->insert([
            'user_id' => $inactiveRoleUser->id,
            'role' => UserRole::Driver->value,
            'is_visible' => true,
            'profile_completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $activeDriver = User::factory()->role(UserRole::Driver)->withProfile()->create([
            'is_visible' => true,
            'is_available' => true,
            'latitude' => 33.5899,
            'longitude' => -7.6039,
            'location_updated_at' => now(),
        ]);
        $passenger = User::factory()->withProfile()->create();

        $this->mock(PushNotificationService::class, function (MockInterface $mock) use ($activeDriver): void {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn (User $recipient): bool => $recipient->is($activeDriver));
        });

        $ride = app(RideService::class)->request($passenger, [
            'pickup_address' => 'Casablanca',
            'pickup_latitude' => 33.5899,
            'pickup_longitude' => -7.6039,
            'dropoff_address' => 'Maarif',
            'dropoff_latitude' => 33.5833,
            'dropoff_longitude' => -7.6333,
        ]);

        $this->assertNotNull($ride->id);
    }
}
