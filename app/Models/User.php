<?php

namespace App\Models;

use App\Enums\OtpPurpose;
use App\Enums\ShopKind;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Notifications\VerifyEmailCodeNotification;
use App\Services\Auth\OtpService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

// `role` et `status` ne sont volontairement PAS fillable : jamais assignables depuis une requete.
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password'])]
class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if ($user->role === null) {
                return;
            }
            if (! $user->wasRecentlyCreated && ! $user->wasChanged('profile_completed_at')) {
                return;
            }

            $storedUser = DB::table('users')
                ->where('id', $user->id)
                ->first(['is_visible', 'profile_completed_at']);

            DB::table('user_roles')->updateOrInsert(
                ['user_id' => $user->id, 'role' => $user->role->value],
                [
                    'is_visible' => (bool) ($user->is_visible ?? $storedUser?->is_visible),
                    'profile_completed_at' => $user->profile_completed_at ?? $storedUser?->profile_completed_at,
                    'created_at' => $user->created_at ?? now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

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
            'last_seen_at' => 'datetime',
            'location_updated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class)
            ->join('users as profile_owner', 'profile_owner.id', '=', 'user_profiles.user_id')
            ->whereColumn('user_profiles.role', 'profile_owner.role')
            ->select('user_profiles.*');
    }

    public function profileForRole(UserRole|string $role): HasOne
    {
        $role = $role instanceof UserRole ? $role->value : $role;

        return $this->hasOne(UserProfile::class)->where('role', $role);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(UserRoleMembership::class);
    }

    public function hasCompletedRoleProfile(UserRole|string $role): bool
    {
        $role = $role instanceof UserRole ? $role->value : $role;

        if ($role === UserRole::Admin->value) {
            return $this->roles()->where('role', $role)->exists();
        }

        return $this->roles()
            ->where('role', $role)
            ->whereNotNull('profile_completed_at')
            ->exists();
    }

    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class, 'owner_id')
            ->join('users as shop_owner', 'shop_owner.id', '=', 'shops.owner_id')
            ->whereColumn('shops.kind', 'shop_owner.role')
            ->select('shops.*');
    }

    public function shopForRole(UserRole|string $role): HasOne
    {
        $kind = ShopKind::forRole($role instanceof UserRole ? $role : UserRole::from($role));
        $relation = $this->hasOne(Shop::class, 'owner_id');

        return $kind === null ? $relation : $relation->where('kind', $kind->value);
    }

    public function scopeForRole(Builder $query, UserRole|string $role): Builder
    {
        $role = $role instanceof UserRole ? $role->value : $role;

        if ($role === UserRole::Admin->value) {
            return $query->whereHas('roles', fn (Builder $membership) => $membership
                ->where('role', $role));
        }

        return $query->whereHas('roles', fn (Builder $membership) => $membership
            ->where('role', $role)
            ->whereNotNull('profile_completed_at'));
    }

    public function scopeForActiveRole(Builder $query, UserRole|string $role): Builder
    {
        $role = $role instanceof UserRole ? $role->value : $role;

        return $query->where('users.role', $role)->forRole($role);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function pushDevices(): HasMany
    {
        return $this->hasMany(PushDevice::class);
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

    public function hasFreshLocation(): bool
    {
        return $this->hasLocation()
            && $this->location_updated_at !== null
            && $this->location_updated_at->lessThanOrEqualTo(now())
            && $this->location_updated_at->greaterThanOrEqualTo(
                now()->subSeconds((int) config('job.stale_seconds', 90)),
            );
    }

    public function hasRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof UserRole ? $role : UserRole::tryFrom($role);
            if ($role === UserRole::Client) {
                return true;
            }
            if ($role !== null && $this->hasCompletedRoleProfile($role)) {
                return true;
            }
        }

        return false;
    }

    public function hasActiveRole(UserRole|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof UserRole ? $role : UserRole::tryFrom($role);
            if ($role === UserRole::Client) {
                if ($this->role === $role) {
                    return true;
                }
            } elseif ($role !== null
                && $this->role === $role
                && $this->roles()->where('role', $role->value)->exists()) {
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
        return $this->hasCompletedRoleProfile($this->role);
    }

    public function profileCompletionPercent(): int
    {
        if ($this->hasCompletedRoleProfile($this->role)) {
            return 100;
        }
        if ($this->profile_completed_at === null) {
            return 0;
        }

        $required = [
            filled($this->name) && $this->name !== 'Nouveau compte',
            filled($this->email),
            filled($this->phone),
            $this->role !== null,
        ];
        $profile = $this->profile;

        if ($this->role->requiresVehicle()) {
            $required[] = filled($profile?->vehicle_model);
            $required[] = filled($profile?->vehicle_plate);
        }

        if ($this->role->requiresShopName()) {
            $required[] = filled($profile?->shop_name);
        }

        if ($this->role->allowedSpecialties() !== null) {
            $required[] = $this->role === UserRole::Demarcheur
                ? (is_array($profile?->housing_zones) && $profile->housing_zones !== [])
                    || (is_array($profile?->specialties) && $profile->specialties !== [])
                : is_array($profile?->specialties) && $profile->specialties !== [];
        }

        return (int) round(count(array_filter($required)) / count($required) * 100);
    }

    public function ensureProfileComplete(): void
    {
        if (! $this->hasCompletedProfile()) {
            throw new ApiException(
                'Complétez toutes les informations obligatoires de votre profil avant de passer en ligne.',
                422,
                'profile_incomplete',
            );
        }
    }

    public function ensureVisible(): void
    {
        $this->ensureProfileComplete();

        if (! $this->is_visible) {
            throw new ApiException(
                'Votre profil n’est pas visible sur MakSpace.',
                403,
                'account_not_visible',
            );
        }
    }

    /** Envoie un code a 6 chiffres plutot qu'un lien (adapte au mobile). */
    public function sendEmailVerificationNotification(): void
    {
        $code = app(OtpService::class)->issue($this->email, OtpPurpose::EmailVerification);
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
