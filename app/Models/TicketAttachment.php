<?php

declare(strict_types=1);

namespace App\Models;

use App\Attachments\AttachmentStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Database\Factories\TicketAttachmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un archivo adjunto a un mensaje. Sin atributos rellenables: solo `AttachmentStore` lo crea, ya validado, y solo
 * `ScanAttachment` cambia su estado. Se entrega únicamente cuando el estado es «limpio».
 *
 * @property string $id
 * @property int $tenant_id
 * @property int $ticket_id
 * @property int $ticket_message_id
 * @property int|null $uploaded_by
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $sha256
 * @property string $disk
 * @property string|null $path
 * @property AttachmentStatus $status
 * @property string|null $scan_signature
 * @property Carbon|null $scanned_at
 */
class TicketAttachment extends Model
{
    /** @use HasFactory<TicketAttachmentFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    /** Solo se puede descargar un archivo revisado y limpio. */
    public function isDownloadable(): bool
    {
        return $this->status === AttachmentStatus::Clean && $this->path !== null;
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<TicketMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => AttachmentStatus::class, 'scanned_at' => 'datetime'];
    }
}
