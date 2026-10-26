<?php

namespace App\Services\Geocoding;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class PlaceSearchService
{
    /**
     * @return list<array{id: string, name: string, address: string, city: string, type: string, latitude: float, longitude: float}>
     */
    public function search(string $query, ?float $latitude = null, ?float $longitude = null, ?string $type = null): array
    {
        $query = trim($query);
        $cacheLatitude = $latitude === null ? '' : round($latitude, 2);
        $cacheLongitude = $longitude === null ? '' : round($longitude, 2);
        $cacheKey = 'geocoding:'.hash('sha256', mb_strtolower($query).'|'.$cacheLatitude.'|'.$cacheLongitude.'|'.$type);

        return Cache::remember($cacheKey, now()->addDay(), function () use ($query, $latitude, $longitude, $type): array {
            try {
                $response = Cache::lock('geocoding:nominatim:lock', 15)->block(12, function () use ($query, $latitude, $longitude) {
                    $lastRequest = (float) Cache::get('geocoding:nominatim:last-request', 0);
                    $waitMicroseconds = (int) max(0, 1_000_000 - ((microtime(true) - $lastRequest) * 1_000_000));
                    if ($waitMicroseconds > 0) {
                        usleep($waitMicroseconds);
                    }
                    Cache::put('geocoding:nominatim:last-request', microtime(true), now()->addMinute());

                    $parameters = [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'addressdetails' => 1,
                        'countrycodes' => 'ma',
                        'limit' => 8,
                        'accept-language' => 'fr,ar',
                    ];
                    if ($latitude !== null && $longitude !== null) {
                        $parameters['viewbox'] = implode(',', [
                            $longitude - 0.75,
                            $latitude + 0.75,
                            $longitude + 0.75,
                            $latitude - 0.75,
                        ]);
                    }

                    return Http::acceptJson()
                        ->withUserAgent(config('services.geocoding.user_agent'))
                        ->timeout(8)
                        ->get(config('services.geocoding.search_url'), $parameters)
                        ->throw()
                        ->json();
                });
            } catch (\Throwable $exception) {
                Log::warning('Address geocoding provider unavailable.', [
                    'exception' => $exception,
                    'query_hash' => hash('sha256', mb_strtolower($query)),
                ]);
                throw new ServiceUnavailableHttpException(
                    30,
                    'La recherche d’adresses est momentanément indisponible.',
                    $exception,
                );
            }

            if (! is_array($response)) {
                Log::warning('Address geocoding provider returned an invalid response.');
                throw new ServiceUnavailableHttpException(
                    30,
                    'La recherche d’adresses est momentanément indisponible.',
                );
            }

            $results = collect($response)
                ->filter(fn ($item) => is_array($item)
                    && isset($item['lat'], $item['lon'])
                    && is_numeric($item['lat'])
                    && is_numeric($item['lon']))
                ->map(fn (array $item) => $this->normalizeResult($item))
                ->values();

            if ($type !== null) {
                $results = $results->where('type', $type)->values();
            }

            return $results->all();
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string, address: string, city: string, type: string, latitude: float, longitude: float}
     */
    private function normalizeResult(array $item): array
    {
        $address = $item['address'] ?? [];
        $address = is_array($address) ? $address : [];
        $city = $this->firstString($address, [
            'city', 'town', 'village', 'municipality', 'city_district', 'county',
        ]) ?? 'Maroc';
        $neighborhood = $this->firstString($address, [
            'neighbourhood', 'suburb', 'quarter', 'city_district',
        ]);
        $road = $this->firstString($address, ['road', 'pedestrian', 'footway', 'path']);
        $houseNumber = $this->firstString($address, ['house_number']);
        $name = $this->firstString($item, ['name'])
            ?? $neighborhood
            ?? $road
            ?? $this->firstString($item, ['display_name'])
            ?? 'Adresse';
        $streetAddress = trim(implode(' ', array_filter([$houseNumber, $road])));
        $labelAddress = implode(', ', array_filter([
            $streetAddress,
            $neighborhood,
            $city,
        ]));
        $type = $this->placeType((string) ($item['class'] ?? ''), (string) ($item['type'] ?? ''));

        return [
            'id' => 'osm-'.($item['osm_type'] ?? 'place').'-'.($item['osm_id'] ?? hash('sha1', $name.'|'.$item['lat'].'|'.$item['lon'])),
            'name' => $name,
            'address' => $labelAddress !== '' ? $labelAddress : (string) ($item['display_name'] ?? $name),
            'city' => $city,
            'type' => $type,
            'latitude' => (float) $item['lat'],
            'longitude' => (float) $item['lon'],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function firstString(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $values[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function placeType(string $class, string $type): string
    {
        if ($class === 'place' && in_array($type, ['neighbourhood', 'suburb', 'quarter', 'city_district'], true)) {
            return 'neighborhood';
        }
        if ($class === 'highway' || in_array($type, ['road', 'residential', 'pedestrian', 'living_street'], true)) {
            return 'street';
        }
        if ($class === 'railway' || $class === 'public_transport') {
            return 'station';
        }
        if ($class === 'aeroway') {
            return 'airport';
        }
        if (in_array($class, ['amenity', 'shop', 'tourism'], true)) {
            return 'landmark';
        }

        return 'landmark';
    }
}
