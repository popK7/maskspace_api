<?php

namespace App\Services\Notifications;

use App\Jobs\SendPushNotification;
use App\Models\PushDevice;
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
     * Met la notification en file d'attente : la requete HTTP ne patiente pas
     * pendant l'appel a Firebase. Envoyee apres validation de la transaction en cours.
     *
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

        $payload = [];
        foreach ($data as $key => $value) {
            $payload[$key] = (string) $value;
        }

        SendPushNotification::dispatch($user->id, $title, $body, $payload)->afterCommit();
    }

    /**
     * Envoi effectif (appele par le job) : un seul appel Firebase pour tous les
     * appareils de l'utilisateur, puis purge des jetons invalides ou desinstalles.
     *
     * @param  array<string, string>  $data
     */
    public function deliver(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = PushDevice::where('user_id', $userId)->pluck('token')->all();
        if ($tokens === []) {
            return;
        }

        $messaging = $this->messaging();
        if ($messaging === null) {
            return;
        }

        $message = CloudMessage::new()
            ->withNotification(FirebaseNotification::create($title, $body))
            ->withData($data);

        try {
            $report = $messaging->sendMulticast($message, $tokens);
        } catch (Throwable $exception) {
            Log::warning('Firebase push notification could not be sent.', [
                'user_id' => $userId,
                'exception' => $exception,
            ]);

            throw $exception; // le job sera retente
        }

        $stale = array_values(array_unique(array_merge($report->invalidTokens(), $report->unknownTokens())));
        if ($stale !== []) {
            PushDevice::where('user_id', $userId)->whereIn('token', $stale)->delete();
        }
    }

    private function messaging(): ?Messaging
    {
        if ($this->messagingResolved) {
            return $this->messaging;
        }
        $this->messagingResolved = true;

        // Permet d'injecter un client Firebase (tests, configuration alternative).
        if (app()->bound(Messaging::class)) {
            return $this->messaging = app(Messaging::class);
        }

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
