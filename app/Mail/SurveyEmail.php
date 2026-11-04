<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * El correo con la encuesta de satisfacción de un ticket resuelto. Cada número es un enlace que abre el formulario con ese
 * puntaje marcado: abrirlo no registra nada (los escáneres de correo abren todos los enlaces), solo enviar el formulario lo
 * hace. Todo texto que viene de una persona se escapa en la plantilla y se limpia antes de llegar a una cabecera.
 */
class SurveyEmail extends Mailable
{
    public function __construct(
        public readonly EmailTheme $emailTheme,
        public readonly string $toAddress,
        public readonly string $customerName,
        public readonly int $number,
        public readonly string $subjectLine,
        public readonly string $replyAddress,
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config()->string('mail.from.address'), TicketEmail::clean($this->emailTheme->company, 80)),
            to: [new Address($this->toAddress)],
            replyTo: [new Address($this->replyAddress)],
            subject: __('mail.survey_subject', ['number' => $this->number]),
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Auto-Response-Suppress' => 'OOF, AutoReply']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.survey', text: 'mail.survey-text', with: ['greeting' => $this->greetingName(), 'theme' => $this->emailTheme, 'links' => $this->links()]);
    }

    /** El nombre con el que se saluda; vacío si es el genérico del widget («Visitante XXXX»). */
    public function greetingName(): string
    {
        $name = TicketEmail::clean($this->customerName, 60);

        return str_starts_with($name, 'Visitante ') ? '' : $name;
    }

    /**
     * @return array<int, string> Puntaje (1 a 5) y la dirección que abre el formulario con él marcado.
     */
    public function links(): array
    {
        $links = [];

        foreach (range(1, 5) as $score) {
            $links[$score] = url('/survey/'.$this->token).'?score='.$score;
        }

        return $links;
    }
}
