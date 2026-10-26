<?php

namespace Tests\Feature\Places;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlaceSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'services.geocoding.search_url' => 'https://nominatim.openstreetmap.org/search',
            'services.geocoding.user_agent' => 'MakSpace-test/1.0',
        ]);
    }

    public function test_search_returns_real_moroccan_places_and_caches_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([[
                'osm_type' => 'node',
                'osm_id' => 12345,
                'class' => 'place',
                'type' => 'neighbourhood',
                'name' => 'Aïn Sebaâ',
                'display_name' => 'Aïn Sebaâ, Casablanca, Maroc',
                'lat' => '33.606',
                'lon' => '-7.539',
                'address' => [
                    'neighbourhood' => 'Aïn Sebaâ',
                    'city' => 'Casablanca',
                    'country' => 'Maroc',
                ],
            ]]),
        ]);

        $this->getJson('/api/v1/places/search?q=ain%20sebaa&lat=31.6295&lng=-7.9811')
            ->assertOk()
            ->assertJsonPath('data.0.id', 'osm-node-12345')
            ->assertJsonPath('data.0.name', 'Aïn Sebaâ')
            ->assertJsonPath('data.0.city', 'Casablanca')
            ->assertJsonPath('data.0.type', 'neighborhood')
            ->assertJsonPath('data.0.latitude', 33.606);

        $this->getJson('/api/v1/places/search?q=ain%20sebaa&lat=31.6295&lng=-7.9811')
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->hasHeader('User-Agent', 'MakSpace-test/1.0')
            && $request['countrycodes'] === 'ma'
            && $request['accept-language'] === 'fr,ar'
            && $request['q'] === 'ain sebaa');
    }

    public function test_search_validates_query_and_coordinate_pairs(): void
    {
        $this->getJson('/api/v1/places/search')->assertUnprocessable();
        $this->getJson('/api/v1/places/search?q=a')->assertUnprocessable();
        $this->getJson('/api/v1/places/search?q=maarif&lat=33.5')->assertUnprocessable();
        $this->getJson('/api/v1/places/search?q=maarif&type=street')->assertUnprocessable();
    }

    public function test_neighborhood_search_excludes_non_neighborhood_results(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([
                [
                    'osm_type' => 'node',
                    'osm_id' => 1,
                    'class' => 'place',
                    'type' => 'neighbourhood',
                    'name' => 'Guéliz',
                    'lat' => '31.63',
                    'lon' => '-8.01',
                    'address' => ['city' => 'Marrakech'],
                ],
                [
                    'osm_type' => 'way',
                    'osm_id' => 2,
                    'class' => 'highway',
                    'type' => 'residential',
                    'name' => 'Rue test',
                    'lat' => '31.63',
                    'lon' => '-8.01',
                    'address' => ['city' => 'Marrakech'],
                ],
            ]),
        ]);

        $this->getJson('/api/v1/places/search?q=gueliz&type=neighborhood')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Guéliz')
            ->assertJsonPath('data.0.city', 'Marrakech');

        Http::assertSent(fn (Request $request) => $request['q'] === 'gueliz');
    }

    public function test_provider_failure_is_reported_as_unavailable(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([], 503),
        ]);

        $this->getJson('/api/v1/places/search?q=maarif')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'La recherche d’adresses est momentanément indisponible.');
    }
}
