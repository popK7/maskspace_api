<?php

namespace App\Support;

use App\Models\User;

/** Distances et tarifs d'intervention / de course. */
class Geo
{
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function roadKm(float $km): float
    {
        return $km * (float) config('job.road_factor', 1.35);
    }

    public static function inServiceArea(User $provider, float $latitude, float $longitude): bool
    {
        return $provider->hasFreshLocation()
            && self::km($provider->latitude, $provider->longitude, $latitude, $longitude)
                <= (float) config('job.radius_km', 15);
    }

    public static function etaMinutes(float $km, ?float $speedKmh = null): int
    {
        $speed = max(5.0, $speedKmh ?? (float) config('job.speed_kmh', 25));

        return max(1, (int) ceil((self::roadKm($km) / $speed) * 60));
    }

    public static function ridePriceCents(float $km): int
    {
        $base = (int) config('job.ride_base_cents', 1000);
        $perKm = (int) config('job.ride_per_km_cents', 400);
        $min = (int) config('job.ride_min_cents', 1500);

        return max($min, $base + (int) round(self::roadKm($km) * $perKm));
    }
}
