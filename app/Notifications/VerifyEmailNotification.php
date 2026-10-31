<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $name = is_object($notifiable) && isset($notifiable->name) && is_string($notifiable->name) ? $notifiable->name : '';

        return (new MailMessage)
            ->subject(__('notifications.verify.subject'))
            ->greeting(__('notifications.greeting', ['name' => $name]))
            ->line(__('notifications.verify.intro'))
            ->action(__('notifications.verify.action'), $this->verificationUrl($notifiable))
            ->line(__('notifications.verify.expire', ['minutes' => config()->integer('auth.verification.expire', 60)]))
            ->line(__('notifications.verify.ignore'))
            ->salutation(__('notifications.salutation'));
    }
}
