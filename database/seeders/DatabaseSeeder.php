<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Comptes de demonstration (correspondent a AuthStore.seeded cote Flutter).
 * Autorises en local et en production : mots de passe faibles, reserves a la demo.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'production'])) {
            $this->command?->warn('Comptes de demo ignores (hors environnement local ou production).');

            return;
        }

        $demo = [
            [UserRole::Client, 'Eric Kiwia', 'client@transport.app', '+212 6 12 34 56 78', []],
            [UserRole::Driver, 'Jean Pierre', 'transporteur@transport.app', '+212 6 98 76 54 32',
                ['vehicle_model' => 'Toyota Corolla', 'vehicle_plate' => 'A 12345']],
            [UserRole::Artisan, 'Karim Bennani', 'artisan@transport.app', '+212 6 55 44 33 22',
                ['specialties' => ['plomberie', 'electricite', 'carrelage']]],
            [UserRole::Livreur, 'Yassine Idrissi', 'livreur@transport.app', '+212 6 22 11 00 99',
                ['vehicle_model' => 'Yamaha NMAX', 'vehicle_plate' => 'B 67890']],
            [UserRole::Boutique, 'Fatou Diallo', 'boutique@transport.app', '+212 6 44 33 22 11',
                ['shop_name' => 'Épicerie Mama Awa', 'specialties' => ['epicerie', 'saveurs']]],
            [UserRole::Restaurant, 'Adjoua Kouassi', 'restaurant@transport.app', '+212 6 77 88 99 00',
                ['shop_name' => 'Chez Tantie', 'specialties' => ['ivoirienne']]],
            [UserRole::Changeur, 'Awa Ndiaye', 'changeur@transport.app', '+212 6 33 22 11 00',
                ['specialties' => ['senegal', 'cote_ivoire', 'mali', 'france', 'belgique']]],
            [UserRole::Hote, 'Aminata Fall', 'hote@transport.app', '+212 6 18 27 36 45',
                ['specialties' => ['appartement', 'chambre', 'studio', 'villa']]],
            [UserRole::Demarcheur, 'Mamadou Ba', 'demarcheur@transport.app', '+212 6 19 28 37 46',
                ['specialties' => ['gironde', 'maarif', 'anfa']]],
            [UserRole::Medecin, 'Awa Diop', 'medecin@transport.app', '+212 6 20 39 48 57',
                ['specialties' => ['generaliste', 'cardiologue', 'pediatre']]],
            [UserRole::Beaute, 'Amina Fall', 'beaute@transport.app', '+212 6 21 40 59 68',
                ['specialties' => ['coiffeur', 'manucure', 'epilation', 'tresses']]],
        ];

        foreach ($demo as [$role, $name, $email, $phone, $profile]) {
            $user = User::where('email', $email)->first() ?? new User;

            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make('123456'),
                'role' => $role,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ])->save();

            $user->profile()->updateOrCreate([], $profile);
        }

        $this->call(BoutiqueSeeder::class);
        $this->call(RestaurantSeeder::class);
        $this->call(ArtisanSeeder::class);
        $this->call(KeurSeeder::class);
    }
}
