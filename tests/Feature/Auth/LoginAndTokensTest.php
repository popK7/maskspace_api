<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class LoginAndTokensTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    public static function allRoles(): array
    {
        return array_map(fn (UserRole $r) => [$r], UserRole::cases());
    }

    #[DataProvider('allRoles')]
    public function test_every_role_can_login_and_read_its_profile(UserRole $role): void
    {
        $user = User::factory()->role($role)->withProfile()->create();

        $data = $this->loginAs($user);

        $this->assertSame($role->value, $data['user']['role']);
        $this->assertSame(900, $data['tokens']['expires_in']);

        $this->api($data['tokens']['access_token'])->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', $role->value);
    }

    public function test_wrong_password_and_unknown_email_return_the_same_error(): void
    {
        $user = User::factory()->create();

        $wrongPassword = $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Nope12345'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        $unknownEmail = $this->api()->postJson('/api/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'Nope12345'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');

        $this->assertSame($wrongPassword->json(), $unknownEmail->json());
    }

    public function test_email_is_case_insensitive_on_login(): void
    {
        $user = User::factory()->create(['email' => 'mixed@example.com']);

        $this->api()->postJson('/api/v1/auth/login', ['email' => ' MIXED@Example.com ', 'password' => 'Password123'])
            ->assertOk();
    }

    public function test_suspended_account_cannot_login(): void
    {
        $user = User::factory()->suspended()->create();

        $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_suspended');
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bad'])->assertUnauthorized();
        }

        // Meme avec le bon mot de passe : bloque.
        $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');
    }

    public function test_protected_routes_require_a_valid_token(): void
    {
        $this->api()->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $this->api('not.a.jwt')->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_refresh_rotates_tokens(): void
    {
        $user = User::factory()->create();
        $first = $this->loginAs($user)['tokens'];

        $second = $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertOk()
            ->assertJsonStructure(['user', 'tokens' => ['access_token', 'refresh_token']])
            ->json('tokens');

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertNotSame($first['access_token'], $second['access_token']);

        // Le nouvel access token fonctionne.
        $this->api($second['access_token'])->getJson('/api/v1/me')->assertOk();
    }

    public function test_reusing_a_rotated_refresh_token_revokes_the_whole_session(): void
    {
        $user = User::factory()->create();
        $first = $this->loginAs($user)['tokens'];
        $second = $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->json('tokens');

        // Rejeu de l'ancien token (vol suppose) => refuse...
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
            ->assertUnauthorized()
            ->assertJsonPath('code', 'invalid_refresh_token');

        // ... et le token legitime de la meme famille est lui aussi revoque.
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])
            ->assertUnauthorized();
    }

    public function test_unknown_or_expired_refresh_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $tokens = $this->loginAs($user)['tokens'];

        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => str_repeat('a', 64)])
            ->assertUnauthorized();

        RefreshToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized();
    }

    public function test_refresh_tokens_are_stored_hashed(): void
    {
        $user = User::factory()->create();
        $tokens = $this->loginAs($user)['tokens'];

        $this->assertDatabaseMissing('refresh_tokens', ['token_hash' => $tokens['refresh_token']]);
        $this->assertDatabaseHas('refresh_tokens', ['token_hash' => hash('sha256', $tokens['refresh_token'])]);
    }

    public function test_logout_revokes_session_and_blacklists_access_token(): void
    {
        $user = User::factory()->create();
        $tokens = $this->loginAs($user)['tokens'];

        $this->api($tokens['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->api($tokens['access_token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized();
    }

    public function test_logout_only_affects_the_current_session(): void
    {
        $user = User::factory()->create();
        $phone = $this->loginAs($user)['tokens'];
        $tablet = $this->loginAs($user)['tokens'];

        $this->api($phone['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

        $this->api($tablet['access_token'])->getJson('/api/v1/me')->assertOk();
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tablet['refresh_token']])->assertOk();
    }

    public function test_logout_all_revokes_every_session(): void
    {
        $user = User::factory()->create();
        $phone = $this->loginAs($user)['tokens'];
        $tablet = $this->loginAs($user)['tokens'];

        $this->api($phone['access_token'])->postJson('/api/v1/auth/logout-all')->assertOk();

        foreach ([$phone, $tablet] as $session) {
            $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
        }
    }

    public function test_suspending_a_user_blocks_existing_tokens(): void
    {
        $user = User::factory()->create();
        $tokens = $this->loginAs($user)['tokens'];

        $user->forceFill(['status' => 'suspended'])->save();

        $this->api($tokens['access_token'])->getJson('/api/v1/me')
            ->assertForbidden()->assertJsonPath('code', 'account_suspended');
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertUnauthorized();
    }
}
