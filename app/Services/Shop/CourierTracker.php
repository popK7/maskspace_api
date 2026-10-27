<?php

namespace App\Services\Shop;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Suivi en direct du livreur.
 *
 * La derniere position connue est ephemere : elle vit dans le cache (quelques minutes) et n'est
 * jamais historisee. Elle n'est exposee qu'au client de la commande en cours que le livreur a prise en charge.
 */
class CourierTracker
{
    /** Au-dela, la position est consideree comme perdue (livreur hors reseau, application fermee...). */
    public const STALE_AFTER_SECONDS = 90;

    /** Rayon (km) sous lequel le livreur est considere comme « sur place ». */
    private const ARRIVAL_KM = 0.12;

    /** Statuts pendant lesquels le livreur assigne est suivi. */
    private const TRACKED = [OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery];

    public function hasActiveDelivery(User $livreur): bool
    {
        return Order::where('livreur_id', $livreur->id)->whereIn('status', self::TRACKED)->exists();
    }

    /** Enregistre la position ; ignoree (false) si le livreur n'a aucune course en cours. */
    public function record(User $livreur, float $lat, float $lng, ?float $heading = null, ?float $speedKmh = null): bool
    {
        if (! $this->hasActiveDelivery($livreur)) {
            Cache::forget($this->key($livreur->id));

            return false;
        }

        Cache::put($this->key($livreur->id), [
            'latitude' => round($lat, 6),
            'longitude' => round($lng, 6),
            'heading' => $heading,
            'speed_kmh' => $speedKmh,
            'at' => now()->getTimestamp(),
        ], now()->addMinutes(10));

        return true;
    }

    /** @return array{latitude: float, longitude: float, heading: ?float, speed_kmh: ?float, at: int}|null */
    public function position(int $livreurId): ?array
    {
        return Cache::get($this->key($livreurId));
    }

    public function forget(int $livreurId): void
    {
        Cache::forget($this->key($livreurId));
    }

    /**
     * Etat du suivi vu par le client.
     *
     * phase : searching (pas encore de livreur) | to_shop | at_shop | to_customer | arrived | done | pickup (retrait)
     *
     * @return array<string, mixed>
     */
    public function snapshot(Order $order): array
    {
        $order->loadMissing(['shop', 'livreur.profile']);
        $shop = $order->shop;
        $livreur = $order->livreur;
        $status = $order->status;
        $now = now()->getTimestamp();

        $destination = $order->delivery_latitude !== null && $order->delivery_longitude !== null
            ? ['latitude' => (float) $order->delivery_latitude, 'longitude' => (float) $order->delivery_longitude]
            : null;
        $shopPoint = $shop->hasLocation()
            ? ['latitude' => (float) $shop->latitude, 'longitude' => (float) $shop->longitude]
            : null;

        $tracked = $livreur !== null && in_array($status, self::TRACKED, true);
        $raw = $tracked ? $this->position($livreur->id) : null;
        $courier = $raw ? [
            'latitude' => $raw['latitude'],
            'longitude' => $raw['longitude'],
            'heading' => $raw['heading'],
            'updated_at' => date(DATE_ATOM, $raw['at']),
            'age_seconds' => max(0, $now - $raw['at']),
            'stale' => ($now - $raw['at']) > self::STALE_AFTER_SECONDS,
        ] : null;

        $phase = $this->phase($order, $livreur, $status, $courier, $shopPoint, $destination);

        return [
            'order_id' => $order->id,
            'status' => $status->value,
            'phase' => $phase,
            'is_delivery' => $order->isDelivery(),
            'prep_minutes' => $order->prep_minutes,
            'livreur' => $livreur ? [
                'id' => $livreur->id,
                'name' => $livreur->name,
                'phone' => $livreur->phone,
                'vehicle' => $livreur->profileForRole(\App\Enums\UserRole::Livreur)->first()?->vehicle_model,
                'rating' => app(LivreurStats::class)->rating($livreur),
            ] : null,
            'courier' => $courier,
            'shop' => [
                'name' => $shop->name,
                'address' => $shop->address,
                ...($shopPoint ?? ['latitude' => null, 'longitude' => null]),
            ],
            'destination' => [
                'address' => $order->delivery_address,
                ...($destination ?? ['latitude' => null, 'longitude' => null]),
            ],
            'eta' => $this->eta($phase, $courier, $shopPoint, $destination),
            'server_time' => date(DATE_ATOM, $now),
        ];
    }

    private function phase(Order $order, ?User $livreur, OrderStatus $status, ?array $courier, ?array $shop, ?array $dest): string
    {
        if (! $order->isDelivery()) {
            return $status->isFinal() ? 'done' : 'pickup';
        }
        if ($status->isFinal()) {
            return 'done';
        }
        if ($livreur === null) {
            return 'searching';
        }

        if ($status === OrderStatus::OutForDelivery) {
            return $courier && $dest && ! $courier['stale'] && $this->km($courier, $dest) <= self::ARRIVAL_KM
                ? 'arrived' : 'to_customer';
        }

        return $courier && $shop && ! $courier['stale'] && $this->km($courier, $shop) <= self::ARRIVAL_KM
            ? 'at_shop' : 'to_shop';
    }

    /**
     * @return array{minutes: int, distance_km: float, to: string, total_minutes: ?int}|null
     */
    private function eta(string $phase, ?array $courier, ?array $shop, ?array $dest): ?array
    {
        if (! $courier || $courier['stale']) {
            return null;
        }

        if ($phase === 'to_customer' && $dest) {
            $km = $this->km($courier, $dest);

            return ['minutes' => $this->minutes($km), 'distance_km' => round($km, 2), 'to' => 'customer', 'total_minutes' => null];
        }

        if (in_array($phase, ['to_shop', 'at_shop'], true) && $shop) {
            $km = $this->km($courier, $shop);
            $second = $dest ? $this->minutes($this->km($shop, $dest)) : null;

            return [
                'minutes' => $this->minutes($km),
                'distance_km' => round($km, 2),
                'to' => 'shop',
                // Arrivee estimee chez le client : trajet jusqu'a la boutique puis boutique -> client.
                'total_minutes' => $second === null ? null : $this->minutes($km) + $second,
            ];
        }

        return null;
    }

    /** Distance routiere estimee (a vol d'oiseau x 1,35) convertie en minutes, 1 minute minimum. */
    private function minutes(float $km): int
    {
        $speed = max(5.0, (float) config('shop.courier_speed_kmh', 22));

        return max(1, (int) ceil(($km * 1.35 / $speed) * 60));
    }

    /** @param array{latitude: float, longitude: float} $a @param array{latitude: float, longitude: float} $b */
    private function km(array $a, array $b): float
    {
        return Shop::haversineKm($a['latitude'], $a['longitude'], $b['latitude'], $b['longitude']);
    }

    private function key(int $livreurId): string
    {
        return "courier:position:{$livreurId}";
    }
}
