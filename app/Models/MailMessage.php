<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\MailDirection;
use App\Mail\MailKind;
use App\Mail\MailStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de un correo que salió o entró, sin su contenido. Lo fijan las clases de `App\Mail`; nada es rellenable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property int $mail_thread_id
 * @property MailDirection $direction
 * @property MailKind $kind
 * @property string $message_id
 * @property int|null $ticket_message_id
 * @property MailStatus $status
 * @property string|null $reason
 * @property int $attempts
 */
class MailMessage extends Model
{
    use BelongsToTenant;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['direction' => MailDirection::class, 'kind' => MailKind::class, 'status' => MailStatus::class];
    }
}
