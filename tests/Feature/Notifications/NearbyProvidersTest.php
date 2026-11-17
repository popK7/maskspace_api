<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithJob;
use Tests\Concerns\InteractsWithShop;
use Tests\TestCase;

class NearbyProvidersTest extends TestCase
{
    use InteractsWithAuth, InteractsWithJob, InteractsWithShop, RefreshDatabase;

    public function test_new_job_is_announced_only_to_the_nearest_matching_artisans(): void
    {
        config(['job.notify_max' => 2]);
        $push = $this->spy(PushNotificationService::class);

        $closest = $this->makeArtisan(['plomberie'], ['latitude' => 33.5896, 'longitude' => -7.6045]);
        $second = $this->makeArtisan(['plomberie'], ['latitude' => 33.5896, 'longitude' => -7.6100]);
        $third = $this->makeArtisan(['plomberie'], ['latitude' => 33.5896, 'longitude' => -7.6300]); // au-dela du plafond
        $otherTrade = $this->makeArtisan(['electricite'], ['latitude' => 33.5896, 'longitude' => -7.6041]);
        $far = $this->makeArtisan(['plomberie'], ['latitude' => 34.0209, 'longitude' => -6.8416]); // Rabat
        $stale = $this->makeArtisan(['plomberie'], ['location_updated_at' => now()->subMinutes(10)]);

        $this->publishJob($this->tokenFor($this->makeClient()));

        foreach ([$closest, $second] as $artisan) {
            $push->shouldHaveReceived('sendToUser')
                ->withArgs(fn (?User $to, string $title) => $to?->is($artisan) && $title === 'Nouvelle demande d’intervention')
                ->once();
        }
        foreach ([$third, $otherTrade, $far, $stale] as $artisan) {
            $push->shouldNotHaveReceived('sendToUser', [
                Mockery::on(fn ($u) => $u instanceof User && $u->is($artisan)),
                'Nouvelle demande d’intervention', Mockery::any(), Mockery::any(),
            ]);
        }
    }
}
