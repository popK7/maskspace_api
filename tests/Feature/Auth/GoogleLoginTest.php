<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_ids' => ['makspace-web']]);
    }

    public function test_google_account_creates_then_reuses_the_user(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response($this->googleProfile())]);

        $first = $this->api()->postJson('/api/v1/auth/google', [
            'id_token' => 'token-1',
            'device_name' => 'phpunit',
        ]);

        $first->assertOk()
            ->assertJsonPath('user.email', 'eric@gmail.com')
            ->assertJsonPath('user.name', 'Eric K')
            ->assertJsonPath('user.role', 'client')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.is_visible', false)
            ->assertJsonPath('user.email_verified', true)
            ->assertJsonStructure(['tokens' => ['access_token', 'refresh_token']]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'eric@gmail.com', 'google_id' => '109876543210']);

        Http::fake(['oauth2.googleapis.com/*' => Http::response($this->googleProfile())]);

        $this->api()->postJson('/api/v1/auth/google', ['id_token' => 'token-2'])
            ->assertOk()
            ->assertJsonPath('user.email', 'eric@gmail.com');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_email_is_linked_to_google(): void
    {
        $user = User::factory()->create([
            'email' => 'eric@gmail.com',
            'status' => UserStatus::Active,
        ]);

        Http::fake(['oauth2.googleapis.com/*' => Http::response($this->googleProfile())]);

        $this->api()->postJson('/api/v1/auth/google', ['id_token' => 'token'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->assertSame('109876543210', $user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_suspended_google_account_is_refused(): void
    {
        User::factory()->create([
            'email' => 'eric@gmail.com',
            'status' => UserStatus::Suspended,
        ]);

        Http::fake(['oauth2.googleapis.com/*' => Http::response($this->googleProfile())]);

        $this->api()->postJson('/api/v1/auth/google', ['id_token' => 'token'])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');
    }

    public function test_unconfigured_google_is_refused(): void
    {
        config(['services.google.client_ids' => []]);

        $this->api()->postJson('/api/v1/auth/google', ['id_token' => 'token'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'google_unconfigured');
    }

    public function test_invalid_google_token_is_refused(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_token'], 400)]);

        $this->api()->postJson('/api/v1/auth/google', ['id_token' => 'nope'])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'google_invalid');
    }

    /** @return array<string, string> */
    private function googleProfile(): array
    {
        return [
            'aud' => 'makspace-web',
            'iss' => 'https://accounts.google.com',
            'exp' => (string) (time() + 3600),
            'email' => 'eric@gmail.com',
            'email_verified' => 'true',
            'sub' => '109876543210',
            'name' => 'Eric K',
        ];
    }
}
