<?php

namespace App\Models;

use App\Enums\ShopKind;
use App\Support\Media;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'kind', 'name', 'description', 'categories', 'phone', 'address', 'neighborhood', 'city', 'latitude', 'longitude',
    'is_active', 'is_open', 'opening_hours', 'supports_delivery', 'supports_pickup',
    'delivery_fee_cents', 'free_delivery_threshold_cents', 'min_order_cents', 'delivery_radius_km', 'prep_minutes',
])]
class Shop extends Model
{
    use HasFactory, SoftDeletes;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected function casts(): array
    {
        return [
            'kind' => ShopKind::class,
            'categories' => 'array',
            'opening_hours' => 'array',
            'force_open_until' => 'datetime',
            'rush_until' => 'datetime',
            'rush_extra_minutes' => 'integer',
            'is_active' => 'boolean',
            'is_open' => 'boolean',
            'supports_delivery' => 'boolean',
            'supports_pickup' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'rating_avg' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Shop $shop) {
            $shop->slug ??= static::uniqueSlug($shop->name);
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'boutique';
        $slug = $base;
        $i = 2;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Le plat du jour (ou coup de coeur) mis en avant sur la fiche du restaurant. */
    public function featuredDish(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Product::class)->where('is_featured', true)->where('is_active', true)->orderBy('id');
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function logoUrl(): ?string
    {
        return Media::url($this->logo_path);
    }

    public function coverUrl(): ?string
    {
        return Media::url($this->cover_path);
    }

    public function logoThumbUrl(): ?string
    {
        return Media::thumbUrl($this->logo_path);
    }

    public function coverThumbUrl(): ?string
    {
        return Media::thumbUrl($this->cover_path);
    }

    public function isRestaurant(): bool
    {
        return $this->kind === ShopKind::Restaurant;
    }

    /** Minutes ajoutees par le mode rush, 0 s'il est coupe ou expire. */
    public function activeRushMinutes(?\DateTimeInterface $at = null): int
    {
        if ($this->rush_extra_minutes <= 0 || $this->rush_until === null) {
            return 0;
        }

        $now = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $this->rush_until->isAfter($now) ? $this->rush_extra_minutes : 0;
    }

    /** Delai de preparation annonce : delai habituel (ou plat le plus long) + rush eventuel. */
    public function effectivePrepMinutes(?int $slowestDish = null): int
    {
        return max($this->prep_minutes, $slowestDish ?? 0) + $this->activeRushMinutes();
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Ouvert maintenant ? Boutique active + interrupteur manuel + creneau du jour (fuseau de l'application).
     * Sans horaires definis, seule la bascule manuelle compte.
     */
    public function isOpenNow(?\DateTimeInterface $at = null): bool
    {
        if (! $this->is_active || ! $this->is_open) {
            return false;
        }

        $now = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        // Ouverture exceptionnelle demandee par le boutiquier hors de ses horaires.
        if ($this->force_open_until !== null && $this->force_open_until->isAfter($now)) {
            return true;
        }

        return $this->isWithinHours($now);
    }

    /** Dans un creneau d'ouverture (ou aucun horaire defini = toujours). */
    public function isWithinHours(\Illuminate\Support\Carbon $now): bool
    {
        $hours = $this->opening_hours;
        if (empty($hours)) {
            return true;
        }

        $slots = $hours[self::DAYS[$now->dayOfWeekIso - 1]] ?? [];
        $time = $now->format('H:i');

        foreach ($slots as $slot) {
            if ($time >= $slot['open'] && $time <= $slot['close']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Libelle de la prochaine ouverture planifiee ("Ouvre à 09:00", "Ouvre demain à 09:00", "Ouvre lundi à 09:00").
     * Null si la boutique est desactivee/fermee manuellement, deja ouverte, ou sans horaires exploitables.
     */
    public function nextOpeningLabel(?\DateTimeInterface $at = null): ?string
    {
        if (! $this->is_active || ! $this->is_open || empty($this->opening_hours) || $this->isOpenNow($at)) {
            return null;
        }

        $now = $at ? \Illuminate\Support\Carbon::instance($at) : now();
        $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

        for ($offset = 0; $offset <= 7; $offset++) {
            $day = $now->copy()->addDays($offset);
            $slots = $this->opening_hours[self::DAYS[$day->dayOfWeekIso - 1]] ?? [];
            usort($slots, fn ($a, $b) => strcmp($a['open'], $b['open']));

            foreach ($slots as $slot) {
                if ($offset === 0 && $slot['open'] <= $now->format('H:i')) {
                    continue;
                }

                return match (true) {
                    $offset === 0 => 'Ouvre à '.$slot['open'],
                    $offset === 1 => 'Ouvre demain à '.$slot['open'],
                    default => 'Ouvre '.$days[$day->dayOfWeekIso - 1].' à '.$slot['open'],
                };
            }
        }

        return null;
    }

    /** Distance orthodromique (km, formule de Haversine). */
    public function distanceKmTo(float $lat, float $lng): ?float
    {
        if (! $this->hasLocation()) {
            return null;
        }

        return self::haversineKm($lat, $lng, $this->latitude, $this->longitude);
    }

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
