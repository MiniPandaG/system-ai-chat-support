import { Link } from '@inertiajs/react';
import { relativeTime } from '../lib/relativeTime';
import type { TicketPriority, TicketRowData, TicketsCopy } from '../ticketTypes';
import { StatusBadge } from './TicketBadges';

interface TicketRowProps {
    ticket: TicketRowData;
    copy: TicketsCopy;
    selected: boolean;
    /** Cadena de consulta de los filtros actuales (con «?»), para que al abrir el ticket la lista siga igual. */
    query?: string;
}

// La barra de la izquierda dice la prioridad con color; el texto accesible la dice para quien no lo ve.
const priorityBar: Record<TicketPriority, string> = {
    urgent: 'bg-danger',
    high: 'bg-warning',
    normal: 'bg-text-tertiary',
    low: 'bg-text-tertiary',
};

function initial(name: string | null): string {
    return (name ?? '?').trim().charAt(0).toUpperCase() || '?';
}

/** Una fila de la bandeja (design/patrones/bandeja.md): prioridad, cliente, asunto, estado, agente y hora. */
export function TicketRow({ ticket, copy, selected, query = '' }: TicketRowProps) {
    const customer = ticket.customer_name ?? copy.no_customer;
    const relative = relativeTime(ticket.last_message_at);

    return (
        <li>
            <Link
                href={`/tickets/${String(ticket.id)}${query}`}
                aria-current={selected ? 'true' : undefined}
                className={`relative flex min-h-14 gap-3 border-b border-border py-3 pl-4 pr-3 transition duration-fast ease-standard ${
                    selected ? 'border-l-2 border-l-primary bg-primary-surface' : 'hover:bg-surface-hover'
                }`}
            >
                <span aria-hidden="true" className={`absolute inset-y-0 left-0 w-[3px] ${priorityBar[ticket.priority]}`} />
                <span
                    aria-hidden="true"
                    className="flex size-9 shrink-0 items-center justify-center rounded-full bg-surface-raised font-display text-sm font-semibold text-text-secondary"
                >
                    {initial(ticket.customer_name)}
                </span>
                <span className="flex min-w-0 flex-1 flex-col gap-1">
                    <span className="flex items-baseline justify-between gap-2">
                        <span className={`flex min-w-0 items-center gap-2 ${ticket.unread ? 'font-semibold text-text' : 'font-medium'}`}>
                            {ticket.unread && <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-primary" />}
                            <span className="truncate">{customer}</span>
                            {ticket.unread && <span className="sr-only">{copy.inbox.unread}</span>}
                        </span>
                        <time dateTime={ticket.last_message_at} title={ticket.last_message_label} className="shrink-0 text-xs text-text-tertiary">
                            {relative ?? ticket.last_message_label}
                        </time>
                    </span>
                    <span className={`truncate ${ticket.unread ? 'text-text' : 'text-text-secondary'}`}>
                        <span className="font-mono text-xs text-text-tertiary">#{ticket.number}</span> {ticket.subject}
                    </span>
                    {ticket.match !== null && <span className="truncate text-xs text-text-tertiary">{copy.inbox.match[ticket.match]}</span>}
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusBadge status={ticket.status} label={copy.status[ticket.status]} />
                        <span className={`truncate text-xs ${ticket.assignee_name === null ? 'text-text-tertiary' : 'text-text-secondary'}`}>
                            {ticket.assignee_name ?? copy.unassigned}
                        </span>
                        <span className="sr-only">
                            {copy.priority_label}: {copy.priority[ticket.priority]}
                        </span>
                    </span>
                </span>
            </Link>
        </li>
    );
}
