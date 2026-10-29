<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

class AdminTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Compte admin de test ignoré (autorisé uniquement en local/testing).');

            return;
        }

        $email = strtolower((string) env('ADMIN_TEST_EMAIL', 'admin@makspace.test'));
        $password = (string) env('ADMIN_TEST_PASSWORD', 'MakSpaceAdmin2026!');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('ADMIN_TEST_EMAIL doit être une adresse e-mail valide.');
        }
        if (strlen($password) < 12) {
            throw new InvalidArgumentException('ADMIN_TEST_PASSWORD doit contenir au moins 12 caractères.');
        }

        $user = User::query()->where('email', $email)->first();
        if ($user !== null && $user->role !== UserRole::Admin) {
            throw new RuntimeException("L’adresse {$email} est déjà utilisée par un compte non administrateur.");
        }

        $user ??= new User;
        $user->forceFill([
            'name' => 'Administrateur de test',
            'email' => $email,
            'phone' => '+212600000001',
            'password' => Hash::make($password),
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
            'is_visible' => true,
            'profile_completed_at' => now(),
            'email_verified_at' => now(),
        ])->save();

        $user->roles()->updateOrCreate(
            ['role' => UserRole::Admin->value],
            ['is_visible' => true, 'profile_completed_at' => now()],
        );

        $this->command?->info("Admin de test prêt : {$email}");
    }
}
