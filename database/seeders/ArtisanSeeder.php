<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ArtisanDriver;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Artisans et transporteurs de demonstration autour de Casablanca. */
class ArtisanSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $people = [
            [UserRole::Artisan, 'Karim Bennani', 'artisan@transport.app', '+212 6 55 44 33 22',
                ['specialties' => ['plomberie', 'electricite', 'carrelage']], 33.5896, -7.6322],
            [UserRole::Artisan, 'Awa Traoré', 'plombier@transport.app', '+212 6 55 10 20 30',
                ['specialties' => ['plomberie']], 33.5867, -7.6281],
            [UserRole::Artisan, 'Moussa Diop', 'electricien@transport.app', '+212 6 55 11 21 31',
                ['specialties' => ['electricite', 'climatisation']], 33.5952, -7.6164],
            [UserRole::Artisan, 'Fatou Ndoye', 'peintre@transport.app', '+212 6 55 12 22 32',
                ['specialties' => ['peinture', 'nettoyage']], 33.5768, -7.6611],
            [UserRole::Driver, 'Jean Pierre', 'transporteur@transport.app', '+212 6 98 76 54 32',
                ['vehicle_model' => 'Toyota Corolla', 'vehicle_plate' => 'A 12345'], 33.5902, -7.6269],
            [UserRole::Driver, 'Ibrahim Sow', 'course@transport.app', '+212 6 98 10 20 30',
                ['vehicle_model' => 'Dacia Logan', 'vehicle_plate' => 'C 33445'], 33.5811, -7.6455],
        ];

        foreach ($people as [$role, $name, $email, $phone, $profile, $lat, $lng]) {
            $user = User::where('email', $email)->first() ?? new User;
            $user->forceFill([
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => Hash::make('123456'),
                'role' => $role,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'is_available' => true,
                'latitude' => $lat,
                'longitude' => $lng,
                'location_updated_at' => now(),
            ])->save();
            $user->profile()->updateOrCreate([], $profile);
        }

        $karim = User::where('email', 'artisan@transport.app')->first();
        $awa = User::where('email', 'plombier@transport.app')->first();
        $jean = User::where('email', 'transporteur@transport.app')->first();
        $ibrahim = User::where('email', 'course@transport.app')->first();
        if ($karim && $jean) {
            ArtisanDriver::firstOrCreate(['artisan_id' => $karim->id, 'driver_id' => $jean->id]);
        }
        if ($awa && $ibrahim) {
            ArtisanDriver::firstOrCreate(['artisan_id' => $awa->id, 'driver_id' => $ibrahim->id]);
        }
    }
}
