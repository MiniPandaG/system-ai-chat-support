<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * El correo de un ticket al cliente: la respuesta del equipo o la confirmación de que se recibió su mensaje. Todo texto
 * que viene de una persona (nombre, asunto, mensaje) se escapa en la plantilla y se limpia de saltos de línea y
 * caracteres de control antes de llegar a una cabecera. No se encola por sí mismo: lo envía `SendTicketEmail`.
 */
class TicketEmail extends Mailable
{
    public function __construct(
        public readonly MailKind $kind,
        public readonly EmailTheme $emailTheme,
        public readonly string $toAddress,
        public readonly string $customerName,
        public readonly int $number,
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly string $replyAddress,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config()->string('mail.from.address'), self::clean($this->emailTheme->company, 80)),
            to: [new Address($this->toAddress)],
            replyTo: [new Address($this->replyAddress)],
            subject: __('mail.subject', ['number' => $this->number, 'subject' => self::clean($this->subjectLine, 120)]),
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->inReplyTo === null ? [] : [$this->inReplyTo],
            text: [
                // Evita que un buzón de vacaciones o una lista conteste a una conversación humana en bucle.
                'X-Auto-Response-Suppress' => 'OOF, AutoReply',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ticket', text: 'mail.ticket-text', with: ['greeting' => $this->greetingName(), 'theme' => $this->emailTheme]);
    }

    /** El nombre con el que se saluda; vacío si es el genérico del widget («Visitante XXXX»). */
    public function greetingName(): string
    {
        $name = self::clean($this->customerName, 60);

        return str_starts_with($name, 'Visitante ') ? '' : $name;
    }

    /** Una línea, sin caracteres de control, de comillas que rompan una cabecera ni más de `$max` caracteres. */
    public static function clean(string $value, int $max): string
    {
        $line = (string) preg_replace('/[\x00-\x1F\x7F"<>\\\\]+/u', ' ', $value);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $line)), 0, $max);
    }
}
