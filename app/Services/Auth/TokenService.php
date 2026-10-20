<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Emission / rotation / revocation des tokens.
 *
 * - Access token : JWT court (JWT_TTL), stateless, porte `sub`, `role` et `sid` (id de session).
 * - Refresh token : chaine opaque aleatoire de longue duree, stockee hashee, a usage unique (rotation).
 *   Si un refresh token deja utilise est presente de nouveau, on suppose un vol : toute la session est revoquee.
 */
class TokenService
{
    /**
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    public function issue(User $user, Request $request, ?string $deviceName = null, ?string $familyId = null): array
    {
        $familyId ??= (string) Str::uuid();
        $plainRefresh = Str::random(64);

        RefreshToken::create([
            'user_id' => $user->id,
            'family_id' => $familyId,
            'token_hash' => $this->hash($plainRefresh),
            'device_name' => $deviceName ? Str::limit($deviceName, 100, '') : null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'last_used_at' => now(),
            'expires_at' => now()->addDays((int) config('auth_tokens.refresh_ttl_days')),
        ]);

        $access = JWTAuth::claims(['sid' => $familyId])->fromUser($user);

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'refresh_token' => $plainRefresh,
        ];
    }

    /**
     * Echange un refresh token contre une nouvelle paire (l'ancien est invalide).
     *
     * @return array{user: User, tokens: array}
     */
    public function rotate(string $plainRefresh, Request $request): array
    {
        $result = DB::transaction(function () use ($plainRefresh, $request) {
            $token = RefreshToken::where('token_hash', $this->hash($plainRefresh))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return null;
            }

            // Reutilisation d'un token deja tourne => probable vol : on coupe toute la session.
            if ($token->isRevoked()) {
                $this->revokeFamily($token->family_id);

                return null;
            }

            if ($token->isExpired()) {
                return null;
            }

            $user = User::with('profile')->find($token->user_id);

            if ($user === null || $user->isSuspended()) {
                $this->revokeFamily($token->family_id);

                return null;
            }

            $token->forceFill(['revoked_at' => now()])->save();

            return [
                'user' => $user,
                'tokens' => $this->issue($user, $request, $token->device_name, $token->family_id),
            ];
        });

        return $result ?? throw ApiException::invalidRefreshToken();
    }

    public function revokeFamily(string $familyId): void
    {
        RefreshToken::where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /** Revoque toutes les sessions d'un utilisateur, sauf eventuellement la session courante. */
    public function revokeAllFor(User $user, ?string $exceptFamilyId = null): void
    {
        RefreshToken::where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->when($exceptFamilyId, fn ($q) => $q->where('family_id', '!=', $exceptFamilyId))
            ->update(['revoked_at' => now()]);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
