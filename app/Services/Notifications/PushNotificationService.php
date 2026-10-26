<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Throwable;

class PushNotificationService
{
    private ?Messaging $messaging = null;

    private bool $messagingResolved = false;

    /**
     * @param  array<string, int|string|bool>  $data
     */
    public function sendToUser(
        ?User $user,
        string $title,
        string $body,
        array $data = [],
    ): void {
        if ($user === null) {
            return;
        }

        $tokens = $user->pushDevices()->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        $messaging = $this->messaging();
        if ($messaging === null) {
            return;
        }

        $messageData = [];
        foreach ($data as $key => $value) {
            $messageData[$key] = (string) $value;
        }

        foreach ($tokens as $token) {
            try {
                $message = CloudMessage::new()
                    ->withToken($token)
                    ->withNotification(FirebaseNotification::create($title, $body))
                    ->withData($messageData);
                $messaging->send($message);
            } catch (Throwable $exception) {
                Log::warning('Firebase push notification could not be sent.', [
                    'user_id' => $user->id,
                    'exception' => $exception,
                ]);
            }
        }
    }

    private function messaging(): ?Messaging
    {
        if ($this->messagingResolved) {
            return $this->messaging;
        }
        $this->messagingResolved = true;

        $credentials = config('services.firebase.credentials');
        if (! is_string($credentials) || $credentials === '' || ! is_file($credentials)) {
            Log::warning('Firebase push notifications are not configured; set FIREBASE_CREDENTIALS.');

            return null;
        }

        try {
            $this->messaging = (new Factory)
                ->withServiceAccount($credentials)
                ->createMessaging();
        } catch (Throwable $exception) {
            Log::error('Firebase Admin SDK could not be initialized.', [
                'exception' => $exception,
            ]);
        }

        return $this->messaging;
    }
}
