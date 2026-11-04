<?php

declare(strict_types=1);

namespace App\Csat;

use App\Jobs\SendSurveyEmail;
use App\Knowledge\TextCleaner;
use App\Models\CsatResponse;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\Ticket;
use App\Models\WidgetSession;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * La encuesta de satisfacción de un ticket (RF-36). Se ofrece una sola vez por ticket, la primera vez que se resuelve o se
 * cierra, y solo si hay por dónde contestarla: el correo del cliente o el chat web. Responder consume la encuesta: la fila
 * se bloquea y solo una petición gana. Un enlace falso, caducado o ya usado es indistinguible desde fuera.
 */
class Surveys
{
    public function __construct(private readonly TenantContext $context) {}

    /** Ofrece la encuesta de este ticket. Debe haber una empresa en contexto. */
    public function offer(Ticket $ticket): void
    {
        if (CsatResponse::query()->where('ticket_id', $ticket->id)->exists()) {
            return;
        }

        $email = Customer::query()->find($ticket->customer_id)?->email;
        $email = $email === null || $email === '' ? null : $email;

        if ($email === null && $ticket->channel !== Channel::Widget) {
            return;
        }

        $issued = SurveyToken::issue($ticket->tenant_id);
        $row = new CsatResponse;
        $row->forceFill([
            'ticket_id' => $ticket->id,
            'token_hash' => $issued['hash'],
            'sent_at' => now(),
            'expires_at' => now()->addDays(config()->integer('csat.expires_days')),
        ]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($email !== null) {
            SendSurveyEmail::dispatch($row->id, $issued['token'])->afterCommit();
        }
    }

    /**
     * La encuesta de un enlace y el nombre de la empresa, solo si todavía se puede responder.
     *
     * @return array{company: string, theme: array<string, string>}|null
     */
    public function open(string $token): ?array
    {
        $found = $this->locate($token);

        if ($found === null) {
            return null;
        }

        [$tenant, $row] = $found;

        return $row->isOpen() ? ['company' => $tenant->name, 'theme' => $this->theme($tenant)] : null;
    }

    /** Registra la respuesta de un enlace; falso si no existe, caducó o ya se usó (sin decir cuál). */
    public function answerByToken(string $token, int $score, ?string $comment): bool
    {
        $parsed = SurveyToken::parse($token);
        $tenant = $parsed === null ? null : Tenant::query()->find($parsed['tenant_id']);

        if ($parsed === null || $tenant === null) {
            return false;
        }

        return $this->context->run($tenant, fn (): bool => $this->consume(CsatResponse::query()->where('token_hash', $parsed['hash']), $score, $comment, 'email'));
    }

    /** ¿El chat web de esta sesión tiene una encuesta por contestar? */
    public function pendingFor(WidgetSession $session): bool
    {
        if ($session->ticket_id === null) {
            return false;
        }

        $tenant = Tenant::query()->find($session->tenant_id);

        return $tenant !== null && $this->context->run($tenant, fn (): bool => CsatResponse::query()->where('ticket_id', $session->ticket_id)->whereNull('answered_at')->where('expires_at', '>', now())->exists());
    }

    /** Registra la respuesta desde el widget: la sesión del visitante identifica su ticket, sin enlace. */
    public function answerFromWidget(WidgetSession $session, int $score, ?string $comment): bool
    {
        $tenant = $session->ticket_id === null ? null : Tenant::query()->find($session->tenant_id);

        if ($tenant === null) {
            return false;
        }

        return $this->context->run($tenant, fn (): bool => $this->consume(CsatResponse::query()->where('ticket_id', $session->ticket_id), $score, $comment, 'widget'));
    }

    /**
     * @param  Builder<CsatResponse>  $query
     */
    private function consume($query, int $score, ?string $comment, string $channel): bool
    {
        return DB::transaction(function () use ($query, $score, $comment, $channel): bool {
            $row = $query->lockForUpdate()->first();

            if ($row === null || ! $row->isOpen()) {
                return false;
            }

            $clean = $comment === null ? '' : trim(TextCleaner::clean($comment));
            $row->forceFill(['answered_at' => now(), 'channel' => $channel, 'score' => $score, 'comment' => $clean === '' ? null : $clean])->save();

            return true;
        });
    }

    /**
     * @return array{0: Tenant, 1: CsatResponse}|null
     */
    private function locate(string $token): ?array
    {
        $parsed = SurveyToken::parse($token);
        $tenant = $parsed === null ? null : Tenant::query()->find($parsed['tenant_id']);

        if ($parsed === null || $tenant === null) {
            return null;
        }

        $row = $this->context->run($tenant, fn (): ?CsatResponse => CsatResponse::query()->where('token_hash', $parsed['hash'])->first());

        return $row === null ? null : [$tenant, $row];
    }

    /**
     * Solo el color primario de la empresa y su texto, ya validados al guardarse y filtrados otra vez al emitirse.
     *
     * @return array<string, string>
     */
    private function theme(Tenant $tenant): array
    {
        return $this->context->run($tenant, fn (): array => TenantBranding::query()->first()?->panelVariables() ?? []);
    }
}
