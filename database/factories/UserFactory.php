<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+212 6 '.fake()->numerify('## ## ## ##'),
            'role' => UserRole::Client,
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function admin(): static
    {
        return $this->role(UserRole::Admin);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => UserStatus::Suspended]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /** Cree aussi le profil attendu pour le role courant. */
    public function withProfile(array $overrides = []): static
    {
        return $this->afterCreating(function (User $user) use ($overrides) {
            $role = $user->role;
            $defaults = match (true) {
                $role->requiresVehicle() => ['vehicle_model' => 'Toyota Corolla', 'vehicle_plate' => 'A 12345'],
                $role === UserRole::Boutique => ['shop_name' => 'Epicerie Test', 'specialties' => ['epicerie']],
                $role === UserRole::Restaurant => ['shop_name' => 'Chez Test', 'specialties' => ['ivoirienne']],
                $role === UserRole::Artisan => ['specialties' => ['plomberie']],
                $role === UserRole::Changeur => ['specialties' => ['senegal']],
                $role === UserRole::Hote => ['specialties' => ['appartement', 'chambre']],
                $role === UserRole::Demarcheur => ['specialties' => ['gironde', 'maarif']],
                default => [],
            };
            $user->profile()->create([...$defaults, ...$overrides]);
        });
    }
}
