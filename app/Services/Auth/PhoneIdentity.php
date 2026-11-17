<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Factory;
use Throwable;

/**
 * Verifie le jeton Firebase obtenu par l'application apres saisie du code SMS
 * et renvoie le numero de telephone prouve (format E.164, ex. +212612345678).
 */
class PhoneIdentity
{
    public function verifiedPhone(string $firebaseIdToken): string
    {
        $auth = $this->auth();

        try {
            $token = $auth->verifyIdToken($firebaseIdToken);
        } catch (Throwable) {
            throw new ApiException('La vérification du numéro a expiré. Recommencez.', 401, 'phone_token_invalid');
        }

        $phone = $token->claims()->get('phone_number');
        if (! is_string($phone) || ! preg_match('/^\+[1-9]\d{6,14}$/', $phone)) {
            throw new ApiException('Ce jeton ne contient pas de numéro vérifié.', 401, 'phone_token_invalid');
        }

        return $phone;
    }

    private function auth(): FirebaseAuth
    {
        // Permet d'injecter un client (tests, configuration alternative).
        if (app()->bound(FirebaseAuth::class)) {
            return app(FirebaseAuth::class);
        }

        $credentials = config('services.firebase.credentials');
        if (! is_string($credentials) || $credentials === '' || ! is_file($credentials)) {
            throw new ApiException('La vérification par SMS n’est pas encore configurée.', 503, 'phone_auth_unconfigured');
        }

        try {
            return (new Factory)->withServiceAccount($credentials)->createAuth();
        } catch (Throwable $exception) {
            Log::error('Firebase Auth could not be initialized.', ['exception' => $exception]);

            throw new ApiException('La vérification par SMS est indisponible.', 503, 'phone_auth_unconfigured');
        }
    }

    /** Normalise une saisie libre en E.164 (sans garantir qu'elle existe). */
    public static function normalize(string $input): string
    {
        $input = trim($input);
        $digits = preg_replace('/\D/', '', $input) ?? '';
        if (str_starts_with($input, '00')) {
            $digits = substr($digits, 2);
        }

        return '+'.ltrim($digits, '+');
    }
}
