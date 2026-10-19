<?php

namespace App\Services\Auth;

use App\Enums\OtpPurpose;
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
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => $data['password'],
                'role' => UserRole::from($data['role']),
                'status' => UserStatus::Active,
            ]);
            $user->save();

            $user->profile()->create([
                'shop_name' => $data['shop_name'] ?? null,
                'vehicle_model' => $data['vehicle_model'] ?? null,
                'vehicle_plate' => $data['vehicle_plate'] ?? null,
                'specialties' => $data['specialties'] ?? null,
            ]);

            // Un compte boutique ou restaurant possède toujours son commerce (modifiable ensuite via /merchant/shop).
            if ($kind = \App\Enums\ShopKind::forRole($user->role)) {
                $user->shop()->save(new Shop([
                    'kind' => $kind,
                    'name' => $data['shop_name'],
                    'categories' => $data['specialties'],
                    'phone' => $data['phone'],
                ]));
            }

            return $user;
        });

        $user->sendEmailVerificationNotification();

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

        if (! $user->isActive()) {
            throw ApiException::accountSuspended();
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        $user->forceFill(['last_login_at' => now()])->save();

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

        if ($user === null || ! $user->isActive()) {
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

        if ($user === null || ! $user->isActive() || ! $codeOk) {
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

    public function changePassword(User $user, string $newPassword, ?string $currentFamilyId): void
    {
        DB::transaction(function () use ($user, $newPassword, $currentFamilyId) {
            $user->forceFill(['password' => $newPassword])->save();
            // On garde uniquement la session courante.
            $this->tokens->revokeAllFor($user, $currentFamilyId);
        });
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
