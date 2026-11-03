<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\Inbound\InboundMailProcessor;
use App\Mail\Inbound\MailboxReader;
use App\Mail\Inbound\NullMailboxReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lee el buzón de soporte y entrega cada respuesta a su ticket. Cada correo se marca como leído al terminar: uno que
 * falla se reintenta unas veces y, pasado el tope, se descarta con su motivo para que no bloquee al resto. Con `--loop`
 * se queda leyendo cada pocos segundos (es lo que hace el servicio `mailbox`); sin servidor configurado no hace nada.
 */
class FetchMailbox extends Command
{
    protected $signature = 'mail:fetch {--loop : Seguir leyendo cada pocos segundos}';

    protected $description = 'Lee el buzón de soporte y entrega las respuestas de los clientes a sus tickets';

    public function handle(MailboxReader $reader, InboundMailProcessor $processor): int
    {
        if ($reader instanceof NullMailboxReader) {
            $this->warn('No hay buzón configurado (MAILBOX_IMAP_HOST).');

            return self::SUCCESS;
        }

        do {
            try {
                $counts = $this->once($reader, $processor);
                $this->line(sprintf('Correos leídos: %d (aceptados %d, rechazados %d).', $counts['read'], $counts['accepted'], $counts['rejected']));
            } catch (Throwable) {
                // Solo el hecho: el texto de la excepción puede traer el servidor o el usuario del buzón.
                $this->error('No se pudo leer el buzón. Se reintentará.');
            }

            if ($this->option('loop')) {
                sleep(max(1, config()->integer('mailbox.fetch.interval_seconds')));
            }
        } while ($this->option('loop'));

        return self::SUCCESS;
    }

    /**
     * @return array{read: int, accepted: int, rejected: int}
     */
    private function once(MailboxReader $reader, InboundMailProcessor $processor): array
    {
        $counts = ['read' => 0, 'accepted' => 0, 'rejected' => 0];

        foreach ($reader->unread(config()->integer('mailbox.fetch.batch')) as $mail) {
            $counts['read']++;
            $attemptsKey = 'mail-attempts:'.hash('sha256', $mail->messageId);

            try {
                $outcome = $processor->process($mail);
            } catch (Throwable) {
                $previous = Cache::get($attemptsKey);
                $attempts = (is_int($previous) ? $previous : 0) + 1;
                Cache::put($attemptsKey, $attempts, now()->addDay());

                if ($attempts < config()->integer('mailbox.fetch.max_attempts')) {
                    // Se queda sin leer: la próxima vuelta lo intenta otra vez.
                    continue;
                }

                $outcome = 'error';
            }

            $reader->markSeen($mail);
            $outcome === InboundMailProcessor::ACCEPTED ? $counts['accepted']++ : $counts['rejected']++;
        }

        return $counts;
    }
}
