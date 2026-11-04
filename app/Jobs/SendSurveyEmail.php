<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\EmailTheme;
use App\Mail\SurveyEmail;
use App\Mail\Threads;
use App\Models\CsatResponse;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Envía el correo de la encuesta con sus cinco enlaces. Trabaja con la empresa que tenía el contexto al encolarlo y no hace
 * nada si la encuesta ya se contestó o caducó, si el cliente ya no tiene correo o si a esa dirección ya se le mandaron las
 * encuestas del día. El token viaja en la carga del trabajo (como el de recuperación de contraseña) porque en la base solo
 * queda su huella.
 */
class SendSurveyEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $csatId, public readonly string $token) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(Threads $threads): void
    {
        $row = CsatResponse::query()->find($this->csatId);

        if ($row === null || ! $row->isOpen()) {
            return;
        }

        $ticket = Ticket::query()->find($row->ticket_id);
        $customer = $ticket === null ? null : Customer::query()->find($ticket->customer_id);
        $tenant = Tenant::query()->find($row->tenant_id);

        if ($ticket === null || $customer === null || $customer->email === null || $customer->email === '' || $tenant === null) {
            return;
        }

        $key = 'mail-survey:'.Threads::hash(mb_strtolower($customer->email));

        if (RateLimiter::tooManyAttempts($key, config()->integer('csat.email.per_address_per_day'))) {
            return;
        }

        RateLimiter::hit($key, 86400);

        Mail::send(new SurveyEmail(
            EmailTheme::for($tenant),
            $customer->email,
            $customer->name,
            $ticket->number,
            $ticket->subject,
            $threads->replyAddress($threads->forTicket($ticket)),
            $this->token,
        ));
    }
}
