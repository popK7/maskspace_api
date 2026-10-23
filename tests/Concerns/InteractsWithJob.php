<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Job;
use App\Models\User;

trait InteractsWithJob
{
    protected function makeArtisan(array $trades = ['plomberie'], array $attrs = []): User
    {
        return User::factory()->role(UserRole::Artisan)->withProfile(['specialties' => $trades])->create(array_merge([
            'is_available' => true,
            'latitude' => 33.5896,
            'longitude' => -7.6322,
            'location_updated_at' => now(),
        ], $attrs));
    }

    protected function makeDriver(array $attrs = []): User
    {
        return User::factory()->role(UserRole::Driver)->withProfile()->create(array_merge([
            'is_available' => true,
            'latitude' => 33.5902,
            'longitude' => -7.6269,
            'location_updated_at' => now(),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    protected function jobPayload(array $overrides = []): array
    {
        return array_merge([
            'trade' => 'plomberie',
            'address' => '12 Avenue de la Paix, Maârif, Casablanca',
            'neighborhood' => 'Maârif',
            'city' => 'Casablanca',
            'latitude' => 33.5896,
            'longitude' => -7.6041,
            'note' => 'Fuite sous l’évier depuis ce matin.',
        ], $overrides);
    }

    protected function publishJob(string $clientToken, array $overrides = []): Job
    {
        $id = $this->api($clientToken)->postJson('/api/v1/jobs', $this->jobPayload($overrides))
            ->assertCreated()->json('data.id');

        return Job::findOrFail($id);
    }
}
