<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Attachments\AttachmentStatus;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Adjuntos de ejemplo. No escribe nada en el disco: las pruebas que necesiten el archivo lo guardan aparte.
 *
 * @extends Factory<TicketAttachment>
 */
class TicketAttachmentFactory extends Factory
{
    protected $model = TicketAttachment::class;

    public function forMessage(TicketMessage $message): static
    {
        return $this->state(fn (): array => [
            'ticket_message_id' => $message->getKey(),
            'ticket_id' => $message->ticket_id,
            'tenant_id' => $message->tenant_id,
        ]);
    }

    public function status(AttachmentStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = strtolower((string) fake()->unique()->regexify('[0-9a-hjkmnp-tv-z]{26}'));

        return [
            'original_name' => 'informe.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'sha256' => hash('sha256', $id),
            'disk' => config()->string('files.disk'),
            'path' => "tenants/0/attachments/{$id}",
            'status' => AttachmentStatus::Pending,
        ];
    }
}
