<?php

namespace App\Notifications;

use App\Support\EmailCode;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The 6-digit code email. Sent right away (not queued) — see EmailCode::send. */
class EmailCodeNotification extends Notification
{
    public function __construct(public string $code, public string $purpose) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reset = $this->purpose === EmailCode::RESET;

        return (new MailMessage)
            ->subject(($reset ? 'Password reset code: ' : 'Verification code: ').$this->code)
            ->greeting('Hi '.$notifiable->name.',')
            ->line($reset ? 'Use this code to reset your password:' : 'Use this code to verify your email address:')
            ->line('# '.$this->code)
            ->line('It expires in '.EmailCode::MINUTES.' minutes and can only be used once.')
            ->line('If you did not ask for this, you can ignore this email — your account is safe. Never share this code with anyone, including our staff.');
    }
}
