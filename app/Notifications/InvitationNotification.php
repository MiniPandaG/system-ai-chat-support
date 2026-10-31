<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Role;
use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitación a unirse a una empresa. Lleva el token en claro, que no se guarda en ningún otro sitio.
 */
class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $company,
        private readonly Role $role,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.invitation.subject', ['company' => $this->company]))
            ->greeting(__('notifications.invitation.greeting'))
            ->line(__('notifications.invitation.intro', ['company' => $this->company, 'role' => __('team.roles.'.$this->role->value)]))
            ->action(__('notifications.invitation.action'), url('/invitations/'.$this->token))
            ->line(__('notifications.invitation.expire', ['hours' => Invitation::TTL_HOURS]))
            ->line(__('notifications.invitation.ignore'))
            ->salutation(__('notifications.salutation'));
    }
}
