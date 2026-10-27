<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\VerifyEmailCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class RolesAndAccountTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    public static function nonAdminRoles(): array
    {
        return array_map(fn (UserRole $r) => [$r], UserRole::selfRegistrable());
    }

    private function tokenFor(User $user): string
    {
        return $this->loginAs($user)['tokens']['access_token'];
    }

    public function test_multiple_roles_migration_can_be_retried_when_profile_role_already_exists(): void
    {
        $migration = require database_path('migrations/2026_10_27_110000_enable_multiple_user_roles.php');

        $migration->up();

        $this->assertTrue(Schema::hasColumn('user_profiles', 'role'));
        $this->assertTrue(Schema::hasTable('user_roles'));
        $this->assertTrue(Schema::hasIndex('user_profiles', ['user_id', 'role'], 'unique'));
        $this->assertFalse(Schema::hasIndex('user_profiles', ['user_id'], 'unique'));
    }

    // ---- Controle d'acces par role ----

    public function test_admin_routes_require_authentication(): void
    {
        $this->api()->getJson('/api/v1/admin/users')->assertUnauthorized();
    }

    #[DataProvider('nonAdminRoles')]
    public function test_non_admin_roles_are_forbidden_on_admin_routes(UserRole $role): void
    {
        $user = User::factory()->role($role)->create();

        $this->api($this->tokenFor($user))->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden_role');
    }

    public function test_admin_can_list_and_filter_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->role(UserRole::Driver)->withProfile()->create(['name' => 'Jean Chauffeur']);
        User::factory()->role(UserRole::Artisan)->withProfile()->create();

        $token = $this->tokenFor($admin);

        $this->api($token)->getJson('/api/v1/admin/users')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonStructure(['data', 'links', 'meta']);

        $this->api($token)->getJson('/api/v1/admin/users?role=driver')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.role', 'driver');

        $this->api($token)->getJson('/api/v1/admin/users?search=Chauffeur')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->api($token)->getJson('/api/v1/admin/users?role=hacker')->assertUnprocessable();
    }

    public function test_admin_can_suspend_and_reactivate_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $driver = User::factory()->role(UserRole::Driver)->create();
        $session = $this->loginAs($driver)['tokens'];

        $this->api($this->tokenFor($admin))->patchJson("/api/v1/admin/users/{$driver->id}/status", ['status' => 'suspended'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertDatabaseHas('users', ['id' => $driver->id, 'status' => 'suspended']);

        $this->api($session['access_token'])->getJson('/api/v1/me')->assertForbidden();
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $session['refresh_token']])->assertUnauthorized();

        $this->api($this->tokenFor($admin))->patchJson("/api/v1/admin/users/{$driver->id}/status", ['status' => 'active'])
            ->assertOk();
        $this->api()->postJson('/api/v1/auth/login', ['email' => $driver->email, 'password' => 'Password123'])->assertOk();
    }

    public function test_admin_cannot_suspend_self_or_another_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $token = $this->tokenFor($admin);

        $this->api($token)->patchJson("/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->api($token)->patchJson("/api/v1/admin/users/{$other->id}/status", ['status' => 'suspended'])->assertForbidden();
    }

    public function test_a_client_cannot_suspend_users(): void
    {
        $client = User::factory()->create();
        $victim = User::factory()->role(UserRole::Driver)->create();

        $this->api($this->tokenFor($client))->patchJson("/api/v1/admin/users/{$victim->id}/status", ['status' => 'suspended'])
            ->assertForbidden();

        $this->assertTrue($victim->fresh()->isActive());
    }

    public function test_unknown_route_returns_json_404(): void
    {
        $this->api()->getJson('/api/v1/nope')->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    // ---- Profil (/me) ----

    public function test_update_me_edits_common_and_role_specific_fields(): void
    {
        $user = User::factory()->role(UserRole::Restaurant)->withProfile()->create();

        $this->api($this->tokenFor($user))->patchJson('/api/v1/me', [
            'name' => 'Nouveau Nom',
            'shop_name' => 'Chez Maman',
            'specialties' => ['senegalaise', 'camerounaise'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Nouveau Nom')
            ->assertJsonPath('data.profile.shop_name', 'Chez Maman')
            ->assertJsonPath('data.profile.specialties', ['senegalaise', 'camerounaise']);
    }

    public function test_account_can_add_roles_and_switch_between_isolated_profiles(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->api($token)->postJson('/api/v1/me/profile', [
            'role' => 'client',
            'name' => 'Client MakSpace',
        ])->assertOk()->assertJsonPath('data.role', 'client');

        $this->api($token)->postJson('/api/v1/me/profile', [
            'role' => 'driver',
            'name' => 'Client MakSpace',
            'vehicle_model' => 'Toyota Corolla',
            'vehicle_plate' => 'A 12345',
        ])->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.roles.0', 'client')
            ->assertJsonPath('data.roles.1', 'driver');

        $this->api($token)->patchJson('/api/v1/me/role', ['role' => 'driver'])
            ->assertOk()
            ->assertJsonPath('data.role', 'driver')
            ->assertJsonPath('data.profile.vehicle_model', 'Toyota Corolla');

        $this->api($token)->patchJson('/api/v1/me/role', ['role' => 'client'])
            ->assertOk()
            ->assertJsonPath('data.role', 'client')
            ->assertJsonPath('data.profile.vehicle_model', null);
    }

    public function test_account_cannot_switch_to_a_role_without_a_completed_profile(): void
    {
        $user = User::factory()->create();

        $this->api($this->tokenFor($user))->patchJson('/api/v1/me/role', ['role' => 'driver'])
            ->assertForbidden()
            ->assertJsonPath('code', 'role_not_enabled');
    }

    public function test_business_roles_keep_separate_profiles_and_shops(): void
    {
        $user = User::factory()->create();
        $token = $this->tokenFor($user);

        $this->api($token)->postJson('/api/v1/me/profile', [
            'role' => 'client',
            'name' => 'Compte MakSpace',
        ])->assertOk();
        $this->api($token)->postJson('/api/v1/me/profile', [
            'role' => 'boutique',
            'name' => 'Compte MakSpace',
            'shop_name' => 'Boutique Awa',
            'specialties' => ['epicerie'],
        ])->assertOk();
        $this->api($token)->postJson('/api/v1/me/profile', [
            'role' => 'restaurant',
            'name' => 'Compte MakSpace',
            'shop_name' => 'Chez Awa',
            'specialties' => ['ivoirienne'],
        ])->assertOk();

        $this->assertDatabaseHas('shops', [
            'owner_id' => $user->id,
            'kind' => 'boutique',
            'name' => 'Boutique Awa',
        ]);
        $this->assertDatabaseHas('shops', [
            'owner_id' => $user->id,
            'kind' => 'restaurant',
            'name' => 'Chez Awa',
        ]);

        $this->api($token)->patchJson('/api/v1/me/role', ['role' => 'boutique'])
            ->assertOk()
            ->assertJsonPath('data.profile.shop_name', 'Boutique Awa');
        $this->api($token)->patchJson('/api/v1/me/role', ['role' => 'restaurant'])
            ->assertOk()
            ->assertJsonPath('data.profile.shop_name', 'Chez Awa');
    }

    public function test_demarcheur_can_save_and_reload_real_housing_zones(): void
    {
        $user = User::factory()->role(UserRole::Demarcheur)->withProfile()->create();
        $zones = [[
            'id' => 'osm-relation-456',
            'name' => 'Guéliz',
            'city' => 'Marrakech',
            'latitude' => 31.63,
            'longitude' => -8.01,
        ]];

        $this->api($this->tokenFor($user))->patchJson('/api/v1/me', [
            'housing_zones' => $zones,
        ])->assertOk()
            ->assertJsonPath('data.profile.housing_zones.0.id', 'osm-relation-456')
            ->assertJsonPath('data.profile.housing_zones.0.name', 'Guéliz')
            ->assertJsonPath('data.profile.housing_zones.0.city', 'Marrakech')
            ->assertJsonPath('data.profile_completion', 100);

        $this->assertSame($zones, $user->fresh()->profile->housing_zones);
    }

    public function test_update_me_cannot_change_role_status_email_or_password(): void
    {
        $user = User::factory()->create();

        $this->api($this->tokenFor($user))->patchJson('/api/v1/me', [
            'name' => 'Ok Name',
            'role' => 'admin',
            'status' => 'active',
            'email' => 'hacker@example.com',
            'password' => 'Hacked12345',
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame(UserRole::Client, $fresh->role);
        $this->assertSame($user->email, $fresh->email);
        $this->assertTrue(password_verify('Password123', $fresh->password));
    }

    public function test_update_me_validates_fields_according_to_role(): void
    {
        $artisan = User::factory()->role(UserRole::Artisan)->withProfile()->create();

        $this->api($this->tokenFor($artisan))->patchJson('/api/v1/me', ['specialties' => ['ivoirienne']])
            ->assertUnprocessable()->assertJsonValidationErrors('specialties.0');
    }

    public function test_visible_legacy_account_can_complete_profile_without_changing_role_or_availability(): void
    {
        $artisan = User::factory()->role(UserRole::Artisan)->create([
            'is_visible' => true,
            'is_available' => true,
            'profile_completed_at' => null,
        ]);
        $artisan->profile()->create(['specialties' => []]);

        $this->api($this->tokenFor($artisan))->patchJson('/api/v1/me', [
            'name' => 'Artisan Mis à jour',
            'specialties' => ['plomberie', 'electricite'],
        ])->assertOk()
            ->assertJsonPath('data.role', 'artisan')
            ->assertJsonPath('data.is_visible', true)
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.profile_completed', true)
            ->assertJsonPath('data.profile.specialties', ['plomberie', 'electricite']);

        $this->assertNotNull($artisan->fresh()->profile_completed_at);
    }

    // ---- Changement de mot de passe ----

    public function test_change_password_keeps_current_session_and_closes_others(): void
    {
        $user = User::factory()->create();
        $phone = $this->loginAs($user)['tokens'];
        $tablet = $this->loginAs($user)['tokens'];

        $this->api($phone['access_token'])->putJson('/api/v1/me/password', [
            'current_password' => 'Password123',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertOk();

        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']])->assertOk();
        $this->api()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tablet['refresh_token']])->assertUnauthorized();
        $this->api()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewPassword456'])->assertOk();
    }

    public function test_change_password_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->api($this->tokenFor($user))->putJson('/api/v1/me/password', [
            'current_password' => 'WrongPassword1',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_new_password_must_differ_from_current(): void
    {
        $user = User::factory()->create();

        $this->api($this->tokenFor($user))->putJson('/api/v1/me/password', [
            'current_password' => 'Password123',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    // ---- Verification e-mail ----

    public function test_email_can_be_verified_with_the_code(): void
    {
        Notification::fake();
        $token = $this->api()->postJson('/api/v1/auth/register', $this->signupPayload([
            'email' => 'verify-me@example.com',
        ]))->assertCreated()->json('tokens.access_token');
        $this->api($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        $user = User::where('email', 'verify-me@example.com')->firstOrFail();
        $code = $this->sentCode($user, VerifyEmailCodeNotification::class);

        $this->api($token)->postJson('/api/v1/auth/email/verify', ['code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->api($token)->postJson('/api/v1/auth/email/verify', ['code' => $code])
            ->assertOk()->assertJsonPath('user.email_verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_code_can_be_resent_once_per_minute(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $token = $this->tokenFor($user);

        $this->api($token)->postJson('/api/v1/auth/email/resend')->assertStatus(202);
        Notification::assertSentTo($user, VerifyEmailCodeNotification::class);

        $this->api($token)->postJson('/api/v1/auth/email/resend')->assertTooManyRequests();
    }

    public function test_verification_endpoints_require_authentication(): void
    {
        $this->api()->postJson('/api/v1/auth/email/verify', ['code' => '123456'])->assertUnauthorized();
        $this->api()->postJson('/api/v1/auth/email/resend')->assertUnauthorized();
    }
}
