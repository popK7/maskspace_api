<?php

namespace Tests\Feature\Releases;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class AppDownloadTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('releases');
    }

    public function test_download_is_public_and_missing_until_an_admin_publishes_it(): void
    {
        $this->api()->getJson('/api/v1/downloads')
            ->assertOk()
            ->assertJsonPath('data.android.available', false)
            ->assertJsonPath('data.ios.available', false);

        $this->api()->getJson('/api/v1/downloads/android')
            ->assertNotFound()
            ->assertJsonPath('code', 'release_missing');

        $admin = User::factory()->admin()->create();
        $token = $this->loginAs($admin)['tokens']['access_token'];

        $this->api($token)->post('/api/v1/admin/downloads/android', [
            'file' => UploadedFile::fake()->create('MakSpace.apk', 20, 'application/vnd.android.package-archive'),
        ])->assertOk()->assertJsonPath('data.available', true);

        $this->api($token)->post('/api/v1/admin/downloads/ios', [
            'file' => UploadedFile::fake()->create('MakSpace.ipa', 20, 'application/octet-stream'),
        ])->assertOk()->assertJsonPath('data.platform', 'ios');

        $this->api()->get('/api/v1/downloads/android')->assertOk()->assertDownload('MakSpace.apk');
        $this->api()->get('/api/v1/downloads/ios')->assertOk()->assertDownload('MakSpace.ipa');
        Storage::disk('releases')->assertExists('makspace.apk');
        Storage::disk('releases')->assertExists('makspace.ipa');
    }

    public function test_a_client_cannot_publish_a_build(): void
    {
        $client = User::factory()->create();
        $token = $this->loginAs($client)['tokens']['access_token'];

        $this->api($token)->post('/api/v1/admin/downloads/android', [
            'file' => UploadedFile::fake()->create('MakSpace.apk', 10),
        ])->assertForbidden();
    }

    public function test_android_upload_rejects_another_extension(): void
    {
        $admin = User::factory()->admin()->create();
        $token = $this->loginAs($admin)['tokens']['access_token'];

        $this->api($token)->post('/api/v1/admin/downloads/android', [
            'file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain'),
        ])->assertUnprocessable();
    }
}
