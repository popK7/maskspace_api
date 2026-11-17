<?php

namespace Tests\Feature\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Auth\PhoneIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class PhoneAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Faux Firebase : le jeton "sms:+2126..." prouve le numero qui suit.
        $identity = Mockery::mock(PhoneIdentity::class);
        $identity->shouldReceive('verifiedPhone')->andReturnUsing(function (string $token) {
            if (! str_starts_with($token, 'sms:')) {
                throw new ApiException('La vérification du numéro a expiré. Recommencez.', 401, 'phone_token_invalid');
            }

            return substr($token, 4);
        });
        $this->app->instance(PhoneIdentity::class, $identity);
    }

    public function test_register_with_verified_phone_and_no_email_then_login_with_phone(): void
    {
        $this->api()->postJson('/api/v1/auth/register', [
            'firebase_token' => 'sms:+212612345678',
            'phone' => '+33 6 00 00 00 00', // ignore : seul le numero prouve compte
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated()
            ->assertJsonPath('user.phone', '+212612345678')
            ->assertJsonPath('user.phone_verified', true)
            ->assertJsonPath('user.email', null)
            ->assertJsonPath('user.email_verified', false);

        $this->api()->postJson('/api/v1/auth/login', [
            'phone' => '+212 6 12-34-56-78',
            'password' => 'Password123',
        ])->assertOk()->assertJsonPath('user.phone', '+212612345678');

        $this->api()->postJson('/api/v1/auth/login', ['phone' => '00212612345678', 'password' => 'Password123'])
            ->assertOk();
        $this->api()->postJson('/api/v1/auth/login', ['phone' => '+212612345678', 'password' => 'Wrong12345'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_a_verified_number_cannot_be_registered_twice(): void
    {
        $payload = [
            'firebase_token' => 'sms:+212612345678',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ];
        $this->api()->postJson('/api/v1/auth/register', $payload)->assertCreated();
        $this->api()->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('phone');

        $this->api()->postJson('/api/v1/auth/register', [...$payload, 'firebase_token' => 'forged'])
            ->assertUnauthorized()->assertJsonPath('code', 'phone_token_invalid');
    }

    public function test_password_can_be_reset_by_sms_and_old_sessions_are_closed(): void
    {
        $token = $this->api()->postJson('/api/v1/auth/register', [
            'firebase_token' => 'sms:+221771234567',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->json('tokens.refresh_token');

        $this->api()->postJson('/api/v1/auth/reset-password/phone', [
            'firebase_token' => 'sms:+221700000000',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertNotFound()->assertJsonPath('code', 'phone_not_found');

        $this->api()->postJson('/api/v1/auth/reset-password/phone', [
            'firebase_token' => 'sms:+221771234567',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $token])->assertUnauthorized();
        $this->api()->postJson('/api/v1/auth/login', ['phone' => '+221771234567', 'password' => 'NewPassword456'])
            ->assertOk();
    }

    public function test_existing_email_account_can_attach_a_phone_and_keeps_email_login(): void
    {
        $user = User::factory()->create(['email' => 'ancien@example.com']);
        $other = User::factory()->create();
        $other->forceFill(['phone_number' => '+212699999999', 'phone_verified_at' => now()])->save();
        $access = JWTAuth::fromUser($user);

        $this->api($access)->postJson('/api/v1/me/phone', ['firebase_token' => 'sms:+212699999999'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->api($access)->postJson('/api/v1/me/phone', ['firebase_token' => 'sms:+212611111111'])
            ->assertOk()->assertJsonPath('data.phone_verified', true);

        $this->api()->postJson('/api/v1/auth/login', ['phone' => '+212611111111', 'password' => 'Password123'])->assertOk();
        $this->api()->postJson('/api/v1/auth/login', ['email' => 'ancien@example.com', 'password' => 'Password123'])->assertOk();
    }

    public function test_verified_number_changes_only_by_sms(): void
    {
        $user = User::factory()->create(['email' => 'moi@example.com']);
        $user->forceFill(['phone' => '+212611111111', 'phone_number' => '+212611111111', 'phone_verified_at' => now()])->save();
        $access = JWTAuth::fromUser($user);

        $this->api($access)->patchJson('/api/v1/me', ['phone' => '+212 6 11 11 11 11', 'name' => 'Awa'])->assertOk();
        $this->api($access)->patchJson('/api/v1/me', ['phone' => '+212622222222'])
            ->assertStatus(422)->assertJsonPath('code', 'phone_change_requires_sms');
    }
}
