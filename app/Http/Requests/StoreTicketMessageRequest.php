<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TicketMessage;

/**
 * Responder al cliente: texto, archivos adjuntos o ambos. El peso y la cantidad se acotan aquí; el tipo real de cada
 * archivo, su estructura y el límite por persona los comprueba `AttachmentStore` con el contenido en la mano.
 */
class StoreTicketMessageRequest extends TicketBodyRequest
{
    protected function ability(): string
    {
        return 'reply';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'body' => ['required_without:files', 'nullable', 'string', 'max:'.TicketMessage::MAX_LENGTH, 'not_regex:/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/'],
            'files' => ['nullable', 'array', 'max:'.config()->integer('attachments.max_per_message')],
            'files.*' => ['file', 'max:'.intdiv(config()->integer('attachments.max_bytes'), 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMegabytes = config()->integer('attachments.max_bytes') / 1048576;

        return [
            ...parent::messages(),
            'body.required_without' => __('tickets.errors.body_or_files_required'),
            'files.array' => __('files.rejected.upload_failed'),
            'files.max' => __('files.rejected.attachments_too_many', ['max' => config()->integer('attachments.max_per_message')]),
            'files.*.file' => __('files.rejected.upload_failed'),
            'files.*.uploaded' => __('files.rejected.upload_failed'),
            'files.*.max' => __('files.rejected.attachment_too_large', ['max' => number_format($maxMegabytes, 0, ',', '.')]),
        ];
    }
}
