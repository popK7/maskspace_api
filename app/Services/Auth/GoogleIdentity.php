<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;

/**
 * Vérifie un jeton d'identité Google (compte Gmail) auprès de Google.
 */
class GoogleIdentity
{
    /**
     * @return array{sub: string, email: string, name: string}
     */
    public function profile(string $idToken): array
    {
        $clientIds = config('services.google.client_ids', []);

        if ($clientIds === []) {
            throw new ApiException(
                'La connexion Google n’est pas encore configurée.',
                503,
                'google_unconfigured',
            );
        }

        $response = Http::timeout(8)->acceptJson()->get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        $data = $response->json();

        if (! $response->ok() || ! is_array($data)) {
            throw new ApiException('Le compte Google n’a pas pu être vérifié.', 401, 'google_invalid');
        }

        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $sub = trim((string) ($data['sub'] ?? ''));
        $verified = filter_var($data['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $issuer = (string) ($data['iss'] ?? '');
        $audience = (string) ($data['aud'] ?? '');
        $expires = (int) ($data['exp'] ?? 0);

        $trustedIssuer = in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true);

        if (
            ! $trustedIssuer
            || $expires < time()
            || ! in_array($audience, $clientIds, true)
            || ! $verified
            || $email === ''
            || ! str_contains($email, '@')
            || $sub === ''
            || strlen($sub) > 255
        ) {
            throw new ApiException('Le compte Google n’a pas pu être vérifié.', 401, 'google_invalid');
        }

        $name = trim((string) ($data['name'] ?? ''));

        return [
            'sub' => $sub,
            'email' => $email,
            'name' => $name !== '' ? $name : 'Nouveau compte',
        ];
    }
}
