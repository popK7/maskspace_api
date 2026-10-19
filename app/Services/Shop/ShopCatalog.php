<?php

namespace App\Services\Shop;

use App\Models\Shop;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Recherche publique des boutiques : texte, rayon, ouvert maintenant, distance.
 */
class ShopCatalog
{
    /**
     * @param  array{q?: string, category?: string, open_now?: bool, lat?: float, lng?: float, radius_km?: float, sort?: string, per_page?: int, page?: int}  $f
     */
    public function search(array $f): LengthAwarePaginator
    {
        $perPage = (int) ($f['per_page'] ?? 20);
        $page = (int) ($f['page'] ?? 1);
        $lat = $f['lat'] ?? null;
        $lng = $f['lng'] ?? null;
        $hasPoint = $lat !== null && $lng !== null;
        $radius = $f['radius_km'] ?? null;

        $query = Shop::query()->where('is_active', true)
            ->with('featuredDish.images')
            ->when($f['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->when($f['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('products', fn ($p) => $p->where('is_active', true)->where('name', 'like', $like)));
            })
            ->when($f['category'] ?? null, fn ($q, $cat) => $q->whereJsonContains('categories', $cat));

        // Pre-filtre rapide par rectangle englobant (utilisable avec les index lat/lng, portable MySQL/SQLite).
        if ($hasPoint && $radius !== null) {
            $dLat = $radius / 111.0;
            $dLng = $radius / (111.0 * max(cos(deg2rad($lat)), 0.01));
            $query->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])
                ->whereBetween('longitude', [$lng - $dLng, $lng + $dLng]);
        }

        $sort = $f['sort'] ?? ($hasPoint ? 'distance' : 'popular');

        // Distance : calculee en PHP (Haversine). Adapte a quelques centaines de boutiques par ville ;
        // au-dela, passer a ST_Distance_Sphere (MySQL) / PostGIS.
        if ($hasPoint || ($f['open_now'] ?? false) || $sort === 'distance') {
            $shops = $query->get();

            if ($f['open_now'] ?? false) {
                $shops = $shops->filter(fn (Shop $s) => $s->isOpenNow());
            }

            if ($hasPoint) {
                $shops->each(fn (Shop $s) => $s->setAttribute('distance_km', $s->distanceKmTo($lat, $lng)));
                if ($radius !== null) {
                    $shops = $shops->filter(fn (Shop $s) => $s->distance_km !== null && $s->distance_km <= $radius);
                }
            }

            $shops = match ($sort) {
                'distance' => $shops->sortBy(fn (Shop $s) => $s->distance_km ?? INF),
                'rating' => $shops->sortByDesc('rating_avg'),
                'newest' => $shops->sortByDesc('id'),
                default => $shops->sortByDesc('orders_count'),
            };

            $total = $shops->count();

            return new LengthAwarePaginator(
                $shops->forPage($page, $perPage)->values(), $total, $perPage, $page,
                ['path' => request()->url(), 'query' => request()->query()],
            );
        }

        match ($sort) {
            'rating' => $query->orderByDesc('rating_avg')->orderByDesc('rating_count'),
            'newest' => $query->orderByDesc('id'),
            default => $query->orderByDesc('orders_count')->orderBy('name'),
        };

        return $query->paginate($perPage)->withQueryString();
    }
}
