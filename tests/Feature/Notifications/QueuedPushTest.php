<?php

namespace Tests\Feature\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\PushDevice;
use App\Models\User;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Mockery;
use Tests\TestCase;

class QueuedPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_user_queues_a_job_on_the_notifications_queue(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        app(PushNotificationService::class)->sendToUser($user, 'Titre', 'Corps', ['ride_id' => 12]);

        Queue::assertPushedOn('notifications', SendPushNotification::class, fn (SendPushNotification $job) => $job->userId === $user->id
            && $job->title === 'Titre'
            && $job->data === ['ride_id' => '12']);
    }

    public function test_delivery_uses_one_multicast_call_and_prunes_unknown_tokens(): void
    {
        $user = User::factory()->create();
        PushDevice::create(['user_id' => $user->id, 'token' => 'good-token', 'platform' => 'android']);
        PushDevice::create(['user_id' => $user->id, 'token' => 'dead-token', 'platform' => 'android']);

        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->withArgs(fn ($message, $tokens) => $tokens === ['good-token', 'dead-token'])
            ->andReturn(MulticastSendReport::withItems([
                SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 'good-token'), []),
                SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 'dead-token'), new NotFound('Requested entity was not found.')),
            ]));
        $this->app->instance(Messaging::class, $messaging);

        (new SendPushNotification($user->id, 'Titre', 'Corps'))->handle(app(PushNotificationService::class));

        $this->assertSame(['good-token'], PushDevice::where('user_id', $user->id)->pluck('token')->all());
    }
}
