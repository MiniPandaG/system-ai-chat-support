<?php

declare(strict_types=1);

namespace App\Widget;

use App\Broadcasting\Channels;
use App\Broadcasting\LiveUpdates;
use App\Mail\Outbox;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\WidgetSession;
use App\Plans\Limit;
use App\Plans\LimitReached;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\MessageAuthor;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Todo lo que un visitante del widget puede hacer con su conversación. La empresa, el cliente, el ticket y el autor los
 * fija este código a partir de la sesión: del visitante solo llegan el texto, un identificador de mensaje y, si quiere,
 * su nombre y su correo. Los mensajes que se le devuelven salen de la tabla de mensajes y nunca tocan las notas internas.
 */
class Conversations
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly TenantContext $context,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Envía un mensaje. Sin sesión (o con una cuya conversación terminó) abre un ticket nuevo; con una conversación
     * abierta, lo añade a ella. Repetir un mismo `client_id` devuelve el mensaje que ya existe y no crea otro.
     *
     * @throws WidgetRejected
     */
    public function send(Tenant $tenant, ?WidgetSession $session, string $ip, string $clientId, string $body, ?string $name, ?string $email): Sent
    {
        return $this->context->run($tenant, function () use ($tenant, $session, $ip, $clientId, $body, $name, $email): Sent {
            if ($session !== null) {
                $existing = $this->sentBefore($session, $clientId);

                if ($existing !== null) {
                    return new Sent($session, $existing, null);
                }

                $appended = $this->append($session, $clientId, $body);

                if ($appended !== null) {
                    return new Sent($session, $appended, null);
                }
            }

            return $this->open($tenant, $session, $ip, $clientId, $body, $name, $email);
        });
    }

    /**
     * Guarda el nombre y el correo que el visitante quiere dejar. Un correo ya registrado por otro cliente de la empresa
     * no se vincula: alguien podría escribir el correo de otra persona para colarse en su historial. La respuesta es la
     * misma y el agente verá al visitante sin correo.
     */
    public function saveContact(WidgetSession $session, ?string $name, ?string $email): void
    {
        $this->context->run($this->tenantOf($session), function () use ($session, $name, $email): void {
            $customer = Customer::query()->findOrFail($session->customer_id);
            $this->applyContact($customer, $name, $email);
            $customer->save();

            // Dejar el correo después de escribir también pide la confirmación (una sola por ticket).
            $ticket = $session->ticket_id === null ? null : Ticket::query()->find($session->ticket_id);
            if ($ticket !== null && $customer->email !== null) {
                $this->outbox->received($ticket);
            }
        });
    }

    /**
     * La conversación tal como la ve el visitante: el estado del ticket y los mensajes recientes en orden.
     *
     * El canal es el nombre del canal privado de esta sesión: solo su dueño puede entrar, con su token.
     *
     * @return array{channel: string, ticket: array{status: string, ended: bool}|null, messages: list<array<string, mixed>>, contact: array{has_email: bool}}
     */
    public function present(WidgetSession $session): array
    {
        return $this->context->run($this->tenantOf($session), function () use ($session): array {
            $customer = Customer::query()->findOrFail($session->customer_id);
            $ticket = $session->ticket_id === null ? null : Ticket::query()->find($session->ticket_id);

            $messages = $ticket === null ? [] : TicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->where('body', '!=', '')
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(config()->integer('widget.conversation_limit'))
                ->get()
                ->reverse()
                ->map(fn (TicketMessage $message): array => $this->messageFor($message))
                ->values()
                ->all();

            return [
                'channel' => Channels::widget($session->tenant_id, $session->id),
                'ticket' => $ticket === null ? null : [
                    'status' => $ticket->status->value,
                    'ended' => in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true),
                ],
                'messages' => array_values($messages),
                'contact' => ['has_email' => $customer->email !== null],
            ];
        });
    }

    /**
     * Un mensaje como lo ve el visitante: quién habla (él o el equipo), el texto y la hora. De quien responde solo se
     * muestra el primer nombre.
     *
     * @return array{id: int, client_id: string|null, author: string, body: string, sent_at: string, agent: string|null}
     */
    public function messageFor(TicketMessage $message): array
    {
        $isAgent = $message->author === MessageAuthor::Agent;

        return [
            'id' => $message->id,
            'client_id' => $message->client_id,
            'author' => $isAgent ? 'agent' : 'customer',
            'body' => $message->body,
            'sent_at' => $message->created_at->toIso8601String(),
            'agent' => $isAgent && $message->user !== null ? Str::before(trim($message->user->name), ' ') : null,
        ];
    }

    private function sentBefore(WidgetSession $session, string $clientId): ?TicketMessage
    {
        return TicketMessage::query()->where('widget_session_id', $session->id)->where('client_id', $clientId)->first();
    }

    /** Añade el mensaje a la conversación abierta de la sesión; nulo si no hay una (no existe, se resolvió o se cerró). */
    private function append(WidgetSession $session, string $clientId, string $body): ?TicketMessage
    {
        if ($session->ticket_id === null) {
            return null;
        }

        $message = DB::transaction(function () use ($session, $clientId, $body): ?TicketMessage {
            $ticket = Ticket::query()->whereKey($session->ticket_id)->lockForUpdate()->first();

            if ($ticket === null || in_array($ticket->status, [TicketStatus::Resolved, TicketStatus::Closed], true)) {
                return null;
            }

            $message = $this->record($session, $ticket, $clientId, $body);

            // Si el equipo esperaba al cliente, que escriba lo devuelve a abierto: hay algo nuevo que atender.
            if ($ticket->status === TicketStatus::WaitingCustomer) {
                $ticket->forceFill(['status' => TicketStatus::Open]);
            }

            $ticket->forceFill(['last_message_at' => $message->created_at])->save();
            $this->touch($session);

            return $message;
        });

        if ($message !== null) {
            $this->announce($message);
        }

        return $message;
    }

    /** Abre un ticket nuevo con este mensaje, y la sesión si todavía no existe. */
    private function open(Tenant $tenant, ?WidgetSession $session, string $ip, string $clientId, string $body, ?string $name, ?string $email): Sent
    {
        $this->guardOpenings($session, $ip);

        try {
            $sent = $this->limits->exclusively($tenant, function (Tenant $locked) use ($session, $clientId, $body, $name, $email): Sent {
                $this->limits->ensure($locked, Limit::TicketsPerMonth);

                $token = null;

                if ($session === null) {
                    $customer = new Customer(['name' => $name ?? 'Visitante']);
                    $this->applyContact($customer, $name, $email);
                    $customer->save();

                    $issued = SessionToken::issue($locked->id);
                    $token = $issued['token'];
                    $session = new WidgetSession;
                    $session->forceFill([
                        'customer_id' => $customer->id,
                        'token_hash' => $issued['hash'],
                        'last_seen_at' => now(),
                        'expires_at' => now()->addHours(config()->integer('widget.session.idle_hours')),
                    ])->save();

                    if ($name === null) {
                        $customer->forceFill(['name' => 'Visitante '.Str::upper(substr($session->id, -4))])->save();
                    }
                }

                $locked->forceFill(['last_ticket_number' => $locked->last_ticket_number + 1])->save();

                $ticket = new Ticket(['subject' => $this->subjectFrom($body)]);
                $ticket->forceFill([
                    'number' => $locked->last_ticket_number,
                    'customer_id' => $session->customer_id,
                    'status' => TicketStatus::Open,
                    'priority' => TicketPriority::Normal,
                    'channel' => Channel::Widget,
                    'last_message_at' => now(),
                ])->save();

                $message = $this->record($session, $ticket, $clientId, $body);
                $ticket->forceFill(['last_message_at' => $message->created_at])->save();
                $session->forceFill(['ticket_id' => $ticket->id])->save();
                $this->touch($session);

                return new Sent($session, $message, $token);
            });
        } catch (LimitReached) {
            throw WidgetRejected::unavailable();
        }

        // La conversación con la que nace una sesión también cuenta para el tope diario de esa sesión.
        if ($session === null) {
            RateLimiter::hit('widget-ticket-session:'.$sent->session->id, 86400);
        }

        $this->announce($sent->message);
        $this->confirmReception($sent);

        return $sent;
    }

    /** Un visitante (o una IP) no abre conversaciones sin fin: los tickets nuevos tienen tope diario. */
    private function guardOpenings(?WidgetSession $session, string $ip): void
    {
        $keys = [
            ['widget-ticket-ip:'.$ip, config()->integer('widget.limits.tickets_per_ip_per_day')],
            ...($session === null ? [] : [['widget-ticket-session:'.$session->id, config()->integer('widget.limits.tickets_per_session_per_day')]]),
        ];

        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw WidgetRejected::tooManyConversations();
            }
        }

        foreach ($keys as [$key]) {
            RateLimiter::hit($key, 86400);
        }
    }

    /** Guarda el mensaje del visitante; si el mismo identificador ya existe (reenvío), devuelve el que había. */
    private function record(WidgetSession $session, Ticket $ticket, string $clientId, string $body): TicketMessage
    {
        $message = new TicketMessage(['body' => $body]);
        $message->forceFill([
            'ticket_id' => $ticket->id,
            'author' => MessageAuthor::Customer,
            'user_id' => null,
            'widget_session_id' => $session->id,
            'client_id' => $clientId,
        ]);

        try {
            $message->save();
        } catch (UniqueConstraintViolationException) {
            return $this->sentBefore($session, $clientId) ?? throw WidgetRejected::unavailable();
        }

        return $message;
    }

    private function applyContact(Customer $customer, ?string $name, ?string $email): void
    {
        if ($name !== null) {
            $customer->name = $name;
        }

        if ($email !== null && $customer->email === null && ! Customer::query()->where('email', $email)->exists()) {
            $customer->email = $email;
        }
    }

    /** El asunto: la primera línea del mensaje, en una sola línea y sin pasarse de 80 caracteres. */
    private function subjectFrom(string $body): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $body));

        return Str::limit($line, 80, '…');
    }

    private function touch(WidgetSession $session): void
    {
        $idle = now()->addHours(config()->integer('widget.session.idle_hours'));
        $cap = $session->created_at->copy()->addDays(config()->integer('widget.session.max_days'));

        $session->forceFill(['last_seen_at' => now(), 'expires_at' => $idle->lessThan($cap) ? $idle : $cap])->save();
    }

    /** Si quien escribe dejó su correo, se le confirma por correo que su mensaje llegó. */
    private function confirmReception(Sent $sent): void
    {
        $ticket = Ticket::query()->find($sent->message->ticket_id);

        if ($ticket !== null) {
            $this->outbox->received($ticket);
        }
    }

    /** Avisa al equipo (bandeja y ticket) y a las otras pestañas del mismo visitante. */
    private function announce(TicketMessage $message): void
    {
        $ticket = Ticket::query()->findOrFail($message->ticket_id);

        LiveUpdates::both($ticket);
        LiveUpdates::visitor($ticket);
    }

    private function tenantOf(WidgetSession $session): Tenant
    {
        return Tenant::query()->findOrFail($session->tenant_id);
    }
}
