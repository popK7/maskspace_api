<?php

namespace App\Jobs;

use App\Services\Notifications\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envoi d'une notification push hors de la requete HTTP. */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes entre deux tentatives */
    public array $backoff = [10, 60];

    /** @param  array<string, string>  $data */
    public function __construct(
        public readonly int $userId,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {
        $this->onQueue('notifications');
    }

    public function handle(PushNotificationService $push): void
    {
        $push->deliver($this->userId, $this->title, $this->body, $this->data);
    }
}
