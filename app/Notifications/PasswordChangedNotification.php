<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa a la cuenta de que su contraseña cambió, para que quien no lo hizo pueda reaccionar a tiempo.
 */
class PasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = isset($notifiable->name) && is_string($notifiable->name) ? $notifiable->name : '';

        return (new MailMessage)
            ->subject(__('notifications.password_changed.subject'))
            ->greeting(__('notifications.greeting', ['name' => $name]))
            ->line(__('notifications.password_changed.intro'))
            ->line(__('notifications.password_changed.warning'))
            ->action(__('notifications.password_changed.action'), route('password.request'))
            ->salutation(__('notifications.salutation'));
    }
}
