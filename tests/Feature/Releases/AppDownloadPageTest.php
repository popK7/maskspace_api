<?php

namespace Tests\Feature\Releases;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppDownloadPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('releases');
        config(['app_downloads.ios_testflight_url' => null]);
    }

    public function test_download_page_lists_both_platforms_and_shows_unavailable_builds(): void
    {
        $this->get(route('downloads.page'))
            ->assertOk()
            ->assertSee('Télécharger MakSpace')
            ->assertSee('Android')
            ->assertSee('iPhone et iPad')
            ->assertSee('Bientôt disponible');
    }

    public function test_download_page_links_to_published_android_and_ios_builds(): void
    {
        Storage::disk('releases')->put('makspace.apk', 'apk');
        Storage::disk('releases')->put('makspace.ipa', 'ipa');

        $this->get(route('downloads.page'))
            ->assertOk()
            ->assertSee(url('/api/v1/downloads/android'), false)
            ->assertSee(route('downloads.ios'), false);

        $this->get('/api/v1/downloads/android')->assertOk()->assertDownload('MakSpace.apk');
        $this->get(route('downloads.ios'))->assertOk()->assertDownload('MakSpace.ipa');
    }

    public function test_ios_route_redirects_to_testflight_when_configured(): void
    {
        config(['app_downloads.ios_testflight_url' => 'https://testflight.apple.com/join/example']);

        $this->get(route('downloads.page'))
            ->assertOk()
            ->assertSee('Ouvrir TestFlight');

        $this->get(route('downloads.ios'))
            ->assertRedirect('https://testflight.apple.com/join/example');
    }

    public function test_download_routes_return_not_found_when_no_build_or_testflight_link_is_available(): void
    {
        $this->get('/api/v1/downloads/android')->assertNotFound();
        $this->get(route('downloads.ios'))->assertNotFound();
    }
}
