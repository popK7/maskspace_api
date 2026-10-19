<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OneTimeCode;
use App\Models\User;
use App\Notifications\ResetPasswordCodeNotification;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    public function test_forgot_password_sends_a_code_to_existing_accounts(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(202);

        Notification::assertSentTo($user, ResetPasswordCodeNotification::class);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->sentCode($user, ResetPasswordCodeNotification::class));
    }

    public function test_forgot_password_does_not_reveal_whether_the_email_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
        $unknown = $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertCount(1);
    }

    public function test_suspended_accounts_do_not_receive_codes(): void
    {
        Notification::fake();
        $user = User::factory()->suspended()->create();

        $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_has_a_per_email_cooldown(): void
    {
        $user = User::factory()->create();

        $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
        $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_full_reset_flow_changes_password_and_closes_all_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $session = $this->loginAs($user)['tokens'];

        $this->api()->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(202);
        $code = $this->sentCode($user, ResetPasswordCodeNotification::class);

        $this->api()->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'code' => $code,
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        // Ancien mot de passe refuse, nouveau accepte.
        $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])->assertUnauthorized();
        $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewPassword456'])->assertOk();

        // L'ancienne session ne peut plus etre rafraichie.
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();
    }

    public function test_code_is_single_use(): void
    {
        $user = User::factory()->create();
        $code = app(OtpService::class)->issue($user->email, OtpPurpose::PasswordReset);
        $payload = ['email' => $user->email, 'code' => $code, 'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456'];

        $this->api()->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->api()->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_wrong_code_is_rejected(): void
    {
        $user = User::factory()->create();
        app(OtpService::class)->issue($user->email, OtpPurpose::PasswordReset);

        $this->api()->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'code' => '000000',
            'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertTrue(password_verify('Password123', $user->fresh()->password));
    }

    public function test_code_is_invalidated_after_too_many_wrong_attempts(): void
    {
        $user = User::factory()->create();
        $code = app(OtpService::class)->issue($user->email, OtpPurpose::PasswordReset);
        $otp = app(OtpService::class);

        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($otp->verify($user->email, OtpPurpose::PasswordReset, '000000'));
        }

        // Meme le bon code est desormais refuse.
        $this->assertFalse($otp->verify($user->email, OtpPurpose::PasswordReset, $code));
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $code = app(OtpService::class)->issue($user->email, OtpPurpose::PasswordReset);
        OneTimeCode::query()->update(['expires_at' => now()->subMinute()]);

        $this->api()->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'code' => $code,
            'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_new_code_invalidates_the_previous_one(): void
    {
        $user = User::factory()->create();
        $otp = app(OtpService::class);
        $old = $otp->issue($user->email, OtpPurpose::PasswordReset);
        $new = $otp->issue($user->email, OtpPurpose::PasswordReset);

        if ($old !== $new) {
            $this->assertFalse($otp->verify($user->email, OtpPurpose::PasswordReset, $old));
        }
        $this->assertSame(1, OneTimeCode::where('email', $user->email)->count());
    }

    public function test_codes_are_not_interchangeable_between_purposes(): void
    {
        $user = User::factory()->unverified()->create();
        $code = app(OtpService::class)->issue($user->email, OtpPurpose::EmailVerification);

        $this->api()->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'code' => $code,
            'password' => 'NewPassword456', 'password_confirmation' => 'NewPassword456',
        ])->assertUnprocessable();
    }

    public function test_codes_are_stored_hashed(): void
    {
        $user = User::factory()->create();
        $code = app(OtpService::class)->issue($user->email, OtpPurpose::PasswordReset);

        $this->assertDatabaseMissing('one_time_codes', ['code_hash' => $code]);
    }

    public function test_new_password_must_respect_the_policy(): void
    {
        $user = User::factory()->create();
        $code = app(OtpService::class)->issue($user->email, OtpPurpose::PasswordReset);

        $this->api()->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email, 'code' => $code,
            'password' => 'weak', 'password_confirmation' => 'weak',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
