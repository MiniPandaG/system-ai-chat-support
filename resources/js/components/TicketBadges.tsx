import type { TicketPriority, TicketStatus } from '../ticketTypes';
import { Badge } from './Badge';

type BadgeVariant = Parameters<typeof Badge>[0]['variant'];

const statusVariants: Record<TicketStatus, BadgeVariant> = {
    open: 'neutral',
    waiting_customer: 'warning',
    resolved: 'success',
    closed: 'neutral',
};

const priorityVariants: Record<TicketPriority, BadgeVariant> = {
    low: 'neutral',
    normal: 'neutral',
    high: 'warning',
    urgent: 'danger',
};

/** Estado del ticket (design/componentes/insignia.md): siempre con su texto, nunca solo color. */
export function StatusBadge({ status, label }: { status: TicketStatus; label: string }) {
    return <Badge variant={statusVariants[status]}>{label}</Badge>;
}

export function PriorityBadge({ priority, label }: { priority: TicketPriority; label: string }) {
    return <Badge variant={priorityVariants[priority]}>{label}</Badge>;
}
