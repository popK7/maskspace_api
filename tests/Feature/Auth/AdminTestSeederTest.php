<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\AdminTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTestSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_test_seeder_creates_a_login_ready_admin(): void
    {
        $this->seed(AdminTestSeeder::class);

        $this->api()
            ->postJson('/api/v1/auth/login', [
                'email' => 'admin@makspace.test',
                'password' => 'MakSpaceAdmin2026!',
            ])
            ->assertOk()
            ->assertJsonPath('user.role', UserRole::Admin->value);

        $admin = User::where('email', 'admin@makspace.test')->firstOrFail();
        $this->assertTrue($admin->hasActiveRole(UserRole::Admin));
    }
}
