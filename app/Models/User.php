<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Notifications\VerifyEmailCodeNotification;
use App\Services\Auth\OtpService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

// `role` et `status` ne sont volontairement PAS fillable : jamais assignables depuis une requete.
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password'])]
class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'is_available' => 'boolean',
            'is_visible' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'email_verified_at' => 'datetime',
            'profile_completed_at' => 'datetime',
            'activation_requested_at' => 'datetime',
            'last_login_at' => 'datetime',
            'location_updated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class, 'owner_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function clientJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'client_id');
    }

    public function artisanJobs(): HasMany
    {
        return $this->hasMany(Job::class, 'artisan_id');
    }

    public function passengerRides(): HasMany
    {
        return $this->hasMany(Ride::class, 'passenger_id');
    }

    public function driverRides(): HasMany
    {
        return $this->hasMany(Ride::class, 'driver_id');
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof UserRole ? $role : UserRole::tryFrom($role);
            if ($role !== null && $this->role === $role) {
                return true;
            }
        }

        return false;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::Suspended;
    }

    public function hasCompletedProfile(): bool
    {
        return $this->profile_completed_at !== null;
    }

    public function ensureVisible(): void
    {
        if (! $this->is_visible) {
            throw new \App\Exceptions\ApiException(
                'Votre compte n’est pas encore visible. Demandez-le : un administrateur doit valider.',
                403,
                'account_not_visible',
            );
        }
    }

    /** Envoie un code a 6 chiffres plutot qu'un lien (adapte au mobile). */
    public function sendEmailVerificationNotification(): void
    {
        $code = app(OtpService::class)->issue($this->email, \App\Enums\OtpPurpose::EmailVerification);
        $this->notify(new VerifyEmailCodeNotification($code));
    }

    // ---- JWTSubject ----

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Claims informatifs pour le client. L'autorisation cote serveur se base toujours
     * sur le role lu en base (un role/statut modifie prend effet immediatement).
     */
    public function getJWTCustomClaims(): array
    {
        return ['role' => $this->role->value];
    }
}
