<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
use App\Enums\ShopKind;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\ResetPasswordCodeNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly OtpService $otp,
        private readonly GoogleIdentity $google,
    ) {}

    /**
     * @param  array<string, mixed>  $data  donnees validees (voir RegisterRequest)
     * @return array{user: User, tokens: array}
     */
    public function register(array $data, Request $request): array
    {
        $user = DB::transaction(function () use ($data) {
            // role / status sont assignes explicitement : jamais via mass-assignment.
            $user = (new User)->forceFill([
                'name' => 'Nouveau compte',
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => UserRole::from($data['role'] ?? UserRole::Client->value),
                'status' => UserStatus::Active,
                'is_available' => false,
                'is_visible' => false,
            ]);
            $user->save();
            $user->profile()->create(['role' => $user->role->value]);

            return $user;
        });

        return [
            'user' => $user->load('profile'),
            'tokens' => $this->tokens->issue($user, $request, $data['device_name'] ?? null),
        ];
    }

    /** @return array{user: User, tokens: array} */
    public function login(string $email, string $password, Request $request, ?string $deviceName = null): array
    {
        $user = User::with('profile')->where('email', strtolower($email))->first();

        // Hash::check est execute meme si l'utilisateur n'existe pas : temps de reponse constant,
        // pas d'enumeration de comptes.
        $valid = Hash::check($password, $user?->password ?? $this->dummyHash());

        if ($user === null || ! $valid) {
            throw ApiException::invalidCredentials();
        }

        if ($user->isSuspended()) {
            throw ApiException::accountSuspended();
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        $user->forceFill(['last_login_at' => now(), 'last_seen_at' => now()])->save();

        return [
            'user' => $user,
            'tokens' => $this->tokens->issue($user, $request, $deviceName),
        ];
    }

    /** @return array{user: User, tokens: array} */
    public function loginWithGoogle(string $idToken, Request $request, ?string $deviceName = null): array
    {
        $profile = $this->google->profile($idToken);

        $user = User::with('profile')->where('google_id', $profile['sub'])->first()
            ?? User::with('profile')->where('email', $profile['email'])->first();

        if ($user !== null && $user->isSuspended()) {
            throw ApiException::accountSuspended();
        }

        if ($user === null) {
            $user = DB::transaction(function () use ($profile) {
                $created = (new User)->forceFill([
                    'name' => $profile['name'],
                    'email' => $profile['email'],
                    'google_id' => $profile['sub'],
                    'phone' => $this->googlePhone($profile['sub']),
                    'password' => Str::password(40),
                    'role' => UserRole::Client,
                    'status' => UserStatus::Active,
                    'is_available' => false,
                    'is_visible' => false,
                    'email_verified_at' => now(),
                ]);
                $created->save();
                $created->profile()->create(['role' => $created->role->value]);

                return $created->load('profile');
            });
        } else {
            $user->forceFill([
                'google_id' => $user->google_id ?? $profile['sub'],
                'email_verified_at' => $user->email_verified_at ?? now(),
                'last_login_at' => now(),
                'last_seen_at' => now(),
            ])->save();
        }

        return [
            'user' => $user,
            'tokens' => $this->tokens->issue($user, $request, $deviceName),
        ];
    }

    /**
     * Envoie un code de reinitialisation si le compte existe et est actif.
     * Ne revele jamais si l'e-mail existe (la reponse HTTP est identique).
     */
    public function sendPasswordResetCode(string $email): void
    {
        $user = User::where('email', strtolower($email))->first();

        if ($user === null || $user->isSuspended()) {
            return;
        }

        $code = $this->otp->issue($user->email, OtpPurpose::PasswordReset);
        $user->notify(new ResetPasswordCodeNotification($code));
    }

    public function resetPassword(string $email, string $code, string $newPassword): void
    {
        $email = strtolower($email);
        $user = User::where('email', $email)->first();

        // On verifie toujours le code (meme sans utilisateur) pour garder un comportement uniforme.
        $codeOk = $this->otp->verify($email, OtpPurpose::PasswordReset, $code);

        if ($user === null || $user->isSuspended() || ! $codeOk) {
            throw ValidationException::withMessages(['code' => [__('auth.invalid_code')]]);
        }

        DB::transaction(function () use ($user, $newPassword) {
            $user->forceFill([
                'password' => $newPassword,
                // Recevoir le code par e-mail prouve la possession de la boite.
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            // Toutes les sessions sont fermees : un eventuel attaquant est deconnecte.
            $this->tokens->revokeAllFor($user);
        });
    }

    public function verifyEmail(User $user, string $code): void
    {
        if ($user->hasVerifiedEmail()) {
            return;
        }

        if (! $this->otp->verify($user->email, OtpPurpose::EmailVerification, $code)) {
            throw ValidationException::withMessages(['code' => [__('auth.invalid_code')]]);
        }

        $user->markEmailAsVerified();
    }

    /** Enregistre les informations obligatoires d’un rôle, sans modifier les autres espaces du compte. */
    public function completeProfile(User $user, array $data): User
    {
        $role = UserRole::from($data['role']);
        if ($user->hasCompletedRoleProfile($role)) {
            throw new ApiException('Cet espace existe déjà sur votre compte.', 409, 'role_already_enabled');
        }

        return DB::transaction(function () use ($user, $data, $role) {
            $isFirstCompletedRole = ! $user->hasCompletedRoleProfile($user->role);
            $now = now();
            $user->forceFill([
                'name' => $data['name'],
                'role' => $isFirstCompletedRole ? $role : $user->role,
                'profile_completed_at' => $isFirstCompletedRole ? $now : $user->profile_completed_at,
                'is_available' => false,
                'is_visible' => true,
                'activation_requested_at' => null,
            ])->save();

            $user->profileForRole($role)->updateOrCreate(['role' => $role->value], [
                'shop_name' => $data['shop_name'] ?? null,
                'vehicle_model' => $data['vehicle_model'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'specialties' => $data['specialties'] ?? null,
                'housing_zones' => $data['housing_zones'] ?? null,
            ]);

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role' => $role->value],
                [
                    'is_visible' => true,
                    'profile_completed_at' => $now,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $freshUser = $user->fresh(['profile', 'shop']);
            if ($role->requiresShopName()) {
                $this->syncShopForRole($freshUser, $role, $data);
            }

            return $freshUser;
        });
    }

    public function switchRole(User $user, UserRole $role): User
    {
        if ($role !== UserRole::Client && ! $user->hasCompletedRoleProfile($role)) {
            throw new ApiException(
                'Complétez et validez le profil de cet espace avant d’y accéder.',
                403,
                'role_not_enabled',
            );
        }

        $user->forceFill([
            'role' => $role,
            'is_available' => false,
        ])->save();

        return $user->fresh(['profile', 'shop']);
    }

    /** Compatibilité avec les anciennes versions : le profil complet suffit à être visible. */
    public function requestActivation(User $user): void
    {
        if ($user->isSuspended()) {
            throw ApiException::accountSuspended();
        }

        $user->ensureProfileComplete();
        if (! $user->is_visible && $user->activation_requested_at === null) {
            throw new ApiException(
                'Votre compte est masqué. Contactez le support pour obtenir de l’aide.',
                403,
                'account_not_visible',
            );
        }
        $user->forceFill([
            'is_visible' => true,
            'activation_requested_at' => null,
        ])->save();
    }

    public function syncShop(User $user, array $data): void
    {
        $this->syncShopForRole($user, $user->role, $data);
    }

    private function syncShopForRole(User $user, UserRole $role, array $data): void
    {
        $kind = ShopKind::forRole($role);
        $shop = $user->shopForRole($role)->first();

        if ($kind === null) {
            $shop?->delete();

            return;
        }

        $attributes = [
            'kind' => $kind,
            'name' => $data['shop_name'],
            'categories' => $data['specialties'] ?? [],
            'phone' => $user->phone,
        ];

        if ($shop) {
            $shop->forceFill($attributes)->save();

            return;
        }

        $user->shopForRole($role)->save(new Shop($attributes));
    }

    public function changePassword(User $user, string $newPassword, ?string $currentFamilyId): void
    {
        DB::transaction(function () use ($user, $newPassword, $currentFamilyId) {
            $user->forceFill(['password' => $newPassword])->save();
            // On garde uniquement la session courante.
            $this->tokens->revokeAllFor($user, $currentFamilyId);
        });
    }

    private function googlePhone(string $sub): string
    {
        $digits = preg_replace('/\D/', '', $sub) ?: (string) abs(crc32($sub));

        return '+990'.substr($digits, 0, 12);
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
