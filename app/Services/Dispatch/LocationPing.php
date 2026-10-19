<?php

namespace App\Services\Dispatch;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Derniere position connue d'un artisan ou d'un transporteur.
 *
 * La coordonnee est persistee (matching de proximite, point de prise en charge)
 * et recopiee en cache pour le suivi live (fraicheur de quelques minutes).
 */
class LocationPing
{
    public function record(User $user, float $lat, float $lng, ?float $heading = null, ?float $speedKmh = null): void
    {
        $user->forceFill([
            'latitude' => round($lat, 6),
            'longitude' => round($lng, 6),
            'location_updated_at' => now(),
        ])->save();

        Cache::put($this->key($user->id), [
            'latitude' => round($lat, 6),
            'longitude' => round($lng, 6),
            'heading' => $heading,
            'speed_kmh' => $speedKmh,
            'at' => now()->getTimestamp(),
        ], now()->addMinutes(10));
    }

    /** @return array{latitude: float, longitude: float, heading: ?float, speed_kmh: ?float, at: int, age_seconds: int, stale: bool}|null */
    public function live(int $userId): ?array
    {
        $raw = Cache::get($this->key($userId));
        if (! is_array($raw)) {
            $user = User::find($userId);
            if (! $user?->hasLocation()) {
                return null;
            }
            $at = $user->location_updated_at?->getTimestamp() ?? 0;
            $age = $at > 0 ? max(0, now()->getTimestamp() - $at) : 9999;

            return [
                'latitude' => (float) $user->latitude,
                'longitude' => (float) $user->longitude,
                'heading' => null,
                'speed_kmh' => null,
                'at' => $at,
                'updated_at' => $at > 0 ? date(DATE_ATOM, $at) : null,
                'age_seconds' => $age,
                'stale' => true,
            ];
        }

        $now = now()->getTimestamp();
        $age = max(0, $now - (int) $raw['at']);

        return [
            'latitude' => (float) $raw['latitude'],
            'longitude' => (float) $raw['longitude'],
            'heading' => $raw['heading'] ?? null,
            'speed_kmh' => $raw['speed_kmh'] ?? null,
            'at' => (int) $raw['at'],
            'updated_at' => date(DATE_ATOM, (int) $raw['at']),
            'age_seconds' => $age,
            'stale' => $age > (int) config('job.stale_seconds', 90),
        ];
    }

    public function forget(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "presence:{$userId}";
    }
}
