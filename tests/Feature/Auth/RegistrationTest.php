<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    public static function selfRegistrableRoles(): array
    {
        return array_map(fn (UserRole $r) => [$r], UserRole::selfRegistrable());
    }

    #[DataProvider('selfRegistrableRoles')]
    public function test_each_role_can_register(UserRole $role): void
    {
        Notification::fake();

        $response = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => $role->value.'@example.com',
        ]));

        $response->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('user.role', 'client')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonPath('user.is_visible', false)
            ->assertJsonPath('user.profile_completed', false)
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'phone', 'role', 'profile'],
                'tokens' => ['access_token', 'token_type', 'expires_in', 'refresh_token']])
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', $role->value.'@example.com')->firstOrFail();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertFalse($user->is_visible);
        $this->assertTrue(password_verify('Password123', $user->password));
        Notification::assertNothingSent();

        $token = $response->json('tokens.access_token');
        $this->api($token)->postJson('/api/v1/auth/activation')
            ->assertStatus(422)
            ->assertJsonPath('code', 'profile_incomplete');

        $this->api($token)->postJson('/api/v1/me/profile', $this->registrationPayload($role))
            ->assertOk()
            ->assertJsonPath('data.role', $role->value)
            ->assertJsonPath('data.profile_completed', true)
            ->assertJsonPath('data.is_visible', false);

        $this->api($token)->postJson('/api/v1/auth/activation')
            ->assertStatus(202)
            ->assertJsonPath('user.activation_requested', true)
            ->assertJsonPath('user.is_visible', false);
        Notification::assertNothingSent();

        $payload = JWTAuth::setToken($token)->getPayload();
        $this->assertSame((string) $user->id, (string) $payload->get('sub'));
        $this->assertNotEmpty($payload->get('sid'));
    }

    public function test_role_specific_profile_is_persisted(): void
    {
        $driver = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => 'driver-profile@example.com',
        ]))->assertCreated()->json('tokens.access_token');
        $this->api($driver)->postJson('/api/v1/me/profile', $this->registrationPayload(UserRole::Driver, [
            'email' => 'driver-profile@example.com',
        ]))->assertOk()
            ->assertJsonPath('data.profile.vehicle_model', 'Yamaha NMAX')
            ->assertJsonPath('data.profile.vehicle_plate', 'A 12345');

        $restaurant = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => 'restaurant-profile@example.com',
        ]))->assertCreated()->json('tokens.access_token');
        $this->api($restaurant)->postJson('/api/v1/me/profile', $this->registrationPayload(UserRole::Restaurant, [
            'email' => 'restaurant-profile@example.com',
        ]))->assertOk()
            ->assertJsonPath('data.profile.shop_name', 'Chez Tantie')
            ->assertJsonPath('data.profile.specialties', ['ivoirienne']);
    }

    public function test_admin_role_cannot_be_self_assigned(): void
    {
        $token = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => 'not-admin@example.com',
        ]))->assertCreated()->json('tokens.access_token');

        $this->api($token)->postJson('/api/v1/me/profile', $this->registrationPayload('client', ['role' => 'admin']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertSame(UserRole::Client, User::where('email', 'not-admin@example.com')->firstOrFail()->role);
    }

    public function test_status_and_verification_cannot_be_mass_assigned(): void
    {
        $this->api()->postJson('/api/v1/auth/register', $this->registrationPayload('client', [
            'status' => 'suspended',
            'email_verified_at' => now()->toDateTimeString(),
            'is_admin' => true,
        ]))->assertCreated();

        $user = User::firstOrFail();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertFalse($user->is_visible);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->profile_completed_at);
        $this->assertSame(UserRole::Client, $user->role);
    }

    public static function missingRoleFields(): array
    {
        return [
            'driver sans vehicule' => [UserRole::Driver, ['vehicle_model', 'vehicle_plate']],
            'livreur sans immatriculation' => [UserRole::Livreur, ['vehicle_plate']],
            'artisan sans specialite' => [UserRole::Artisan, ['specialties']],
            'changeur sans corridor' => [UserRole::Changeur, ['specialties']],
            'hote sans type' => [UserRole::Hote, ['specialties']],
            'demarcheur sans zone' => [UserRole::Demarcheur, ['specialties']],
            'medecin sans specialite' => [UserRole::Medecin, ['specialties']],
            'beaute sans specialite' => [UserRole::Beaute, ['specialties']],
            'boutique sans nom' => [UserRole::Boutique, ['shop_name']],
            'boutique sans rayon' => [UserRole::Boutique, ['specialties']],
            'restaurant sans nom' => [UserRole::Restaurant, ['shop_name']],
            'restaurant sans cuisine' => [UserRole::Restaurant, ['specialties']],
        ];
    }

    #[DataProvider('missingRoleFields')]
    public function test_role_specific_fields_are_required(UserRole $role, array $missing): void
    {
        $payload = $this->registrationPayload($role);
        foreach ($missing as $field) {
            unset($payload[$field]);
        }

        $token = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => $role->value.'-'.implode('-', $missing).'@example.com',
        ]))->assertCreated()->json('tokens.access_token');

        $this->api($token)->postJson('/api/v1/me/profile', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($missing);
    }

    public function test_specialties_must_belong_to_the_role_catalog(): void
    {
        // "ivoirienne" est une cuisine, pas un metier d'artisan.
        $token = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => 'bad-specialty@example.com',
        ]))->assertCreated()->json('tokens.access_token');

        $this->api($token)->postJson('/api/v1/me/profile', $this->registrationPayload(UserRole::Artisan, [
            'specialties' => ['ivoirienne'],
        ]))->assertUnprocessable()->assertJsonValidationErrors('specialties.0');
    }

    public function test_client_does_not_need_role_specific_fields(): void
    {
        $token = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => 'plain-client@example.com',
        ]))->assertCreated()->json('tokens.access_token');

        $this->api($token)->postJson('/api/v1/me/profile', $this->registrationPayload(UserRole::Client))
            ->assertOk()
            ->assertJsonPath('data.profile.vehicle_model', null);
    }

    public function test_email_must_be_unique_case_insensitively(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $this->api()->postJson('/api/v1/auth/register', $this->registrationPayload('client', ['email' => 'DUPE@Example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public static function weakPasswords(): array
    {
        return [['short1'], ['alllettersnodigit'], ['1234567890']];
    }

    #[DataProvider('weakPasswords')]
    public function test_weak_passwords_are_rejected(string $password): void
    {
        $this->api()->postJson('/api/v1/auth/register', $this->registrationPayload('client', [
            'password' => $password,
            'password_confirmation' => $password,
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->api()->postJson('/api/v1/auth/register', $this->registrationPayload('client', [
            'password_confirmation' => 'Different123',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->api()->postJson('/api/v1/auth/register', [])->assertUnprocessable();
        }

        $this->api()->postJson('/api/v1/auth/register', [])
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'too_many_requests')
            ->assertHeader('Retry-After');
    }
}
