<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\MoneyTransfer;
use App\Models\User;

trait InteractsWithTransfer
{
    protected function makeChangeur(array $corridors = ['senegal'], array $attrs = []): User
    {
        return User::factory()->role(UserRole::Changeur)->withProfile(['specialties' => $corridors])->create(array_merge([
            'is_available' => true,
            'latitude' => 33.5896,
            'longitude' => -7.6322,
        ], $attrs));
    }

    /** @return array<string, mixed> */
    protected function transferPayload(array $overrides = []): array
    {
        return array_merge([
            'amount' => 150000,
            'currency' => 'XOF',
            'destination_country' => 'senegal',
            'address' => '12 Avenue de la Paix, Maârif, Casablanca',
            'neighborhood' => 'Maârif',
            'city' => 'Casablanca',
            'latitude' => 33.5896,
            'longitude' => -7.6041,
            'note' => 'Remise en main propre ce soir.',
        ], $overrides);
    }

    protected function publishTransfer(string $clientToken, array $overrides = []): MoneyTransfer
    {
        $id = $this->api($clientToken)->postJson('/api/v1/transfers', $this->transferPayload($overrides))
            ->assertCreated()->json('data.id');

        return MoneyTransfer::findOrFail($id);
    }
}
