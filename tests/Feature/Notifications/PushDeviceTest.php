<?php

namespace Tests\Feature\Notifications;

use App\Enums\UserRole;
use App\Models\PushDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class PushDeviceTest extends TestCase
{
    use InteractsWithAuth, RefreshDatabase;

    public function test_authenticated_user_can_register_and_remove_their_push_device(): void
    {
        $token = $this->registerActivated(UserRole::Client, [
            'email' => 'push-device@example.com',
        ]);

        $this->api($token)->postJson('/api/v1/me/push-devices', [
            'token' => 'firebase-registration-token',
            'platform' => 'android',
        ])->assertOk();

        $device = PushDevice::where('token', 'firebase-registration-token')->firstOrFail();
        $this->assertSame('android', $device->platform);

        $this->api($token)->deleteJson('/api/v1/me/push-devices', [
            'token' => 'firebase-registration-token',
        ])->assertOk();

        $this->assertDatabaseMissing('push_devices', [
            'token' => 'firebase-registration-token',
        ]);
    }

    public function test_push_device_token_cannot_be_removed_by_another_user(): void
    {
        $ownerToken = $this->registerActivated(UserRole::Client, [
            'email' => 'push-owner@example.com',
        ]);
        $otherToken = $this->registerActivated(UserRole::Client, [
            'email' => 'push-other@example.com',
        ]);

        $this->api($ownerToken)->postJson('/api/v1/me/push-devices', [
            'token' => 'owner-firebase-token',
            'platform' => 'android',
        ])->assertOk();

        $this->api($otherToken)->deleteJson('/api/v1/me/push-devices', [
            'token' => 'owner-firebase-token',
        ])->assertOk();

        $this->assertDatabaseHas('push_devices', [
            'token' => 'owner-firebase-token',
        ]);
    }
}
