<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Models\OneTimeCode;
use Illuminate\Support\Facades\DB;

/**
 * Codes a usage unique a 6 chiffres (reset de mot de passe, verification d'e-mail).
 *
 * - Stockes hashes (HMAC-SHA256 lie a l'e-mail + au motif) : une fuite de la table n'expose pas les codes.
 * - Un seul code valide a la fois par (e-mail, motif) : en emettre un nouveau invalide les precedents.
 * - Duree de vie courte et nombre d'essais limite (anti brute-force : 10^6 combinaisons / 5 essais).
 */
class OtpService
{
    public function issue(string $email, OtpPurpose $purpose): string
    {
        $email = strtolower($email);
        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($email, $purpose, $code) {
            OneTimeCode::where('email', $email)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->delete();

            OneTimeCode::create([
                'email' => $email,
                'purpose' => $purpose->value,
                'code_hash' => $this->hash($email, $purpose, $code),
                'expires_at' => now()->addMinutes((int) config('auth_tokens.otp_ttl_minutes')),
            ]);
        });

        return $code;
    }

    /**
     * Verifie le code et le consomme s'il est correct.
     * Retourne false pour tout echec (inconnu, expire, trop d'essais, incorrect) sans distinguer le motif.
     */
    public function verify(string $email, OtpPurpose $purpose, string $code): bool
    {
        $email = strtolower($email);

        return DB::transaction(function () use ($email, $purpose, $code) {
            $record = OneTimeCode::where('email', $email)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($record === null || $record->expires_at->isPast()) {
                return false;
            }

            if ($record->attempts >= (int) config('auth_tokens.otp_max_attempts')) {
                return false;
            }

            $record->increment('attempts');

            if (! hash_equals($record->code_hash, $this->hash($email, $purpose, $code))) {
                return false;
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return true;
        });
    }

    private function hash(string $email, OtpPurpose $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$email}|{$purpose->value}|{$code}", (string) config('app.key'));
    }
}
