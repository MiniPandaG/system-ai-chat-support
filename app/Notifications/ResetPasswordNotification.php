<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Va por cola para que la respuesta tarde lo mismo exista o no la cuenta.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function toMail($notifiable): MailMessage
    {
        $name = is_object($notifiable) && isset($notifiable->name) && is_string($notifiable->name) ? $notifiable->name : '';

        return (new MailMessage)
            ->subject(__('notifications.reset.subject'))
            ->greeting(__('notifications.greeting', ['name' => $name]))
            ->line(__('notifications.reset.intro'))
            ->action(__('notifications.reset.action'), $this->resetUrl($notifiable))
            ->line(__('notifications.reset.expire', ['minutes' => config()->integer('auth.passwords.users.expire')]))
            ->line(__('notifications.reset.ignore'))
            ->salutation(__('notifications.salutation'));
    }
}
