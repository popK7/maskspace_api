<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth_tokens.otp_ttl_minutes');

        return (new MailMessage)
            ->subject(__('auth.mail.reset_subject', ['app' => config('app.name')]))
            ->greeting(__('auth.mail.greeting', ['name' => $notifiable->name]))
            ->line(__('auth.mail.reset_intro'))
            ->line("**{$this->code}**")
            ->line(__('auth.mail.expires', ['minutes' => $minutes]))
            ->line(__('auth.mail.ignore_reset'));
    }
}
