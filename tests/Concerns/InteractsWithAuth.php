<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

trait InteractsWithAuth
{
    /** Payload d'inscription valide pour chaque role. */
    protected function registrationPayload(UserRole|string $role, array $overrides = []): array
    {
        $role = $role instanceof UserRole ? $role : UserRole::from($role);

        $base = [
            'role' => $role->value,
            'name' => 'Test '.ucfirst($role->value),
            'email' => $role->value.'@example.com',
            'phone' => '+212 6 12 34 56 78',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'device_name' => 'phpunit',
        ];

        $extra = match ($role) {
            UserRole::Driver, UserRole::Livreur => ['vehicle_model' => 'Yamaha NMAX', 'vehicle_plate' => 'a 12345'],
            UserRole::Artisan => ['specialties' => ['plomberie', 'electricite']],
            UserRole::Changeur => ['specialties' => ['senegal', 'cote_ivoire']],
            UserRole::Hote => ['specialties' => ['appartement', 'chambre']],
            UserRole::Demarcheur => ['specialties' => ['gironde', 'maarif']],
            UserRole::Boutique => ['shop_name' => 'Epicerie Awa', 'specialties' => ['epicerie']],
            UserRole::Restaurant => ['shop_name' => 'Chez Tantie', 'specialties' => ['ivoirienne']],
            default => [],
        };

        return [...$base, ...$extra, ...$overrides];
    }

    /** Connecte un utilisateur par l'API et retourne la reponse JSON decodee. */
    protected function loginAs(User $user, string $password = 'Password123'): array
    {
        return $this->api()->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json();
    }

    /** Extrait le code a 6 chiffres du dernier e-mail envoye (notification fake). */
    protected function sentCode(User $user, string $notificationClass): string
    {
        $notifications = Notification::sent($user, $notificationClass);
        $this->assertNotEmpty($notifications, "Aucune notification {$notificationClass} envoyee.");

        $mail = $notifications->last()->toMail($user);
        foreach ($mail->introLines as $line) {
            if (preg_match('/\*\*(\d{6})\*\*/', (string) $line, $m)) {
                return $m[1];
            }
        }

        $this->fail('Aucun code a 6 chiffres dans le message.');
    }
}
