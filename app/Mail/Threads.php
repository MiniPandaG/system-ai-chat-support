<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\MailThread;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Lo que une un correo que llega con su ticket. Cada ticket tiene un hilo con un token aleatorio de 128 bits que viaja en
 * la dirección de respuesta (`soporte+t-{token}@dominio`) y en el identificador de cada correo que sale. El token lleva la
 * empresa delante, para poder fijar el contexto antes de buscar el hilo (la búsqueda ya sale con el filtro de empresa).
 * Saber el token no basta para escribir en un ticket: además el remitente debe ser el cliente del ticket.
 */
class Threads
{
    private const TOKEN = '(\d{1,18})-([0-9a-f]{32})';

    public function __construct(private readonly TenantContext $context) {}

    /** El hilo del ticket, creándolo la primera vez que hace falta. Debe haber una empresa en contexto. */
    public function forTicket(Ticket $ticket): MailThread
    {
        $existing = MailThread::query()->where('ticket_id', $ticket->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $token = $ticket->tenant_id.'-'.bin2hex(random_bytes(16));
        $thread = new MailThread;
        $thread->forceFill(['ticket_id' => $ticket->id, 'token' => $token, 'token_hash' => self::hash($token), 'created_at' => now()]);

        try {
            $thread->save();
        } catch (UniqueConstraintViolationException) {
            // Dos envíos a la vez crearon el hilo: se queda con el que ganó.
            return MailThread::query()->where('ticket_id', $ticket->id)->firstOrFail();
        }

        return $thread;
    }

    /**
     * Busca el hilo de un token que llegó en un correo. Fija la empresa del token y busca dentro de ella: un token de una
     * empresa que no existe, o que no corresponde a ningún hilo, no devuelve nada. Deja la empresa en contexto si lo halla.
     *
     * @param  array{tenant_id: int, token: string}  $found
     */
    public function find(array $found): ?MailThread
    {
        $tenant = Tenant::query()->find($found['tenant_id']);

        if ($tenant === null) {
            return null;
        }

        $this->context->set($tenant);

        $thread = MailThread::query()->where('token_hash', self::hash($found['tenant_id'].'-'.$found['token']))->first();

        if ($thread === null) {
            $this->context->set(null);
        }

        return $thread;
    }

    /**
     * Los tokens que lleva un texto (los destinatarios, o las referencias de un correo): en la parte local de una dirección
     * con «+t-» o dentro de un identificador de correo «<t-…».
     *
     * @return list<array{tenant_id: int, token: string}>
     */
    public static function tokensIn(string $text): array
    {
        preg_match_all('/(?:\+|<)t-'.self::TOKEN.'(?=[@.>])/i', strtolower($text), $matches, PREG_SET_ORDER);

        $found = [];

        foreach ($matches as $match) {
            $found[$match[1].'-'.$match[2]] = ['tenant_id' => (int) $match[1], 'token' => $match[2]];
        }

        return array_values($found);
    }

    /** La dirección a la que debe responder el cliente. */
    public function replyAddress(MailThread $thread): string
    {
        [$local, $domain] = self::fromParts();

        return $local.'+t-'.$thread->token.'@'.$domain;
    }

    /** Identificador (sin los < >) de un correo que sale: lleva el token, así una respuesta que lo cite llega al hilo aunque la dirección pierda el «+». */
    public function messageId(MailThread $thread): string
    {
        return 't-'.$thread->token.'.'.Str::lower(Str::random(16)).'@'.self::fromParts()[1];
    }

    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function fromParts(): array
    {
        $address = config()->string('mail.from.address');
        $at = strrpos($address, '@');

        return $at === false ? [$address, 'localhost'] : [substr($address, 0, $at), substr($address, $at + 1)];
    }
}
