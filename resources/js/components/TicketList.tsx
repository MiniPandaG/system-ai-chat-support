import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import type { TicketRowData, TicketsCopy } from '../ticketTypes';
import { Button } from './Button';
import { TicketRow } from './TicketRow';

interface TicketListProps {
    /** Primer tramo, el que llega con la pantalla. */
    tickets: TicketRowData[];
    /** Cursor del tramo siguiente; nulo si ya no hay más. */
    nextCursor: string | null;
    copy: TicketsCopy;
    selectedId: number | null;
    /** Cadena de consulta de los filtros actuales (con «?»): sirve para los enlaces y para pedir más tramos. */
    query: string;
}

interface PageResponse {
    data: TicketRowData[];
    next_cursor: string | null;
}

/** Filas de relleno mientras llegan datos (design/patrones/bandeja.md): mismo molde que una fila real. */
export function TicketListSkeleton({ rows = 6 }: { rows?: number }) {
    return (
        <ul aria-hidden="true" className="animate-pulse">
            {Array.from({ length: rows }, (_, index) => (
                <li key={index} className="flex gap-3 border-b border-border py-3 pl-4 pr-3">
                    <span className="size-9 shrink-0 rounded-full bg-surface-raised" />
                    <span className="flex flex-1 flex-col gap-2">
                        <span className="h-4 w-2/5 rounded-sm bg-surface-raised" />
                        <span className="h-4 w-4/5 rounded-sm bg-surface-raised" />
                        <span className="h-4 w-1/4 rounded-sm bg-surface-raised" />
                    </span>
                </li>
            ))}
        </ul>
    );
}

/**
 * Lista de la bandeja con carga progresiva. Las flechas arriba y abajo mueven el foco entre filas; Enter abre la
 * enfocada (es un enlace). Cuando la fila final se ve, o con el botón «Cargar más», se pide el tramo siguiente con el
 * mismo filtro. Se vuelve a montar cuando cambian los filtros, así nunca mezcla tramos de listas distintas.
 */
export function TicketList({ tickets, nextCursor, copy, selectedId, query }: TicketListProps) {
    const [more, setMore] = useState<{ rows: TicketRowData[]; next: string | null } | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const sentinelRef = useRef<HTMLDivElement>(null);
    const cursor = more === null ? nextCursor : more.next;

    // El primer tramo se refresca con la pantalla (por ejemplo al cambiar el estado de un ticket); los siguientes se
    // conservan, y un ticket que ya está arriba no se repite abajo.
    const seen = new Set<number>();
    const rows = [...tickets, ...(more?.rows ?? [])].filter((ticket) => {
        if (seen.has(ticket.id)) return false;
        seen.add(ticket.id);

        return true;
    });

    const loadMore = () => {
        if (cursor === null || loading) return;

        setLoading(true);
        setFailed(false);
        const separator = query === '' ? '?' : '&';

        fetch(`/tickets/page${query}${separator}cursor=${encodeURIComponent(cursor)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((response) => {
                if (!response.ok) throw new Error(String(response.status));

                return response.json() as Promise<PageResponse>;
            })
            .then((body) => {
                setMore((current) => ({ rows: [...(current?.rows ?? []), ...body.data], next: body.next_cursor }));
            })
            .catch(() => {
                setFailed(true);
            })
            .finally(() => {
                setLoading(false);
            });
    };

    // Cuando el final de la lista entra en pantalla se pide el siguiente tramo, salvo que el anterior haya fallado.
    useEffect(() => {
        const element = sentinelRef.current;
        if (element === null || typeof IntersectionObserver === 'undefined' || cursor === null || loading || failed) return;

        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) loadMore();
        });
        observer.observe(element);

        return () => {
            observer.disconnect();
        };
    });

    const move = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

        const links = Array.from(event.currentTarget.querySelectorAll<HTMLAnchorElement>('a'));
        const index = links.findIndex((link) => link === document.activeElement);
        const next = links[event.key === 'ArrowDown' ? index + 1 : index - 1];

        if (next !== undefined) {
            event.preventDefault();
            next.focus();
        }
    };

    return (
        // El contenedor solo recoge las flechas de los enlaces que ya son enfocables; no es un control en sí.
        <div role="presentation" onKeyDown={move}>
            <ul aria-label={copy.list_label}>
                {rows.map((ticket) => (
                    <TicketRow key={ticket.id} ticket={ticket} copy={copy} selected={ticket.id === selectedId} query={query} />
                ))}
            </ul>
            {loading && <TicketListSkeleton rows={3} />}
            <div role="status" className="sr-only">
                {loading ? copy.inbox.loading_more : ''}
            </div>
            {failed && (
                <p role="alert" className="px-4 py-3 text-sm text-danger">
                    {copy.inbox.load_error}
                </p>
            )}
            {cursor !== null && !loading && (
                <div ref={sentinelRef} className="flex justify-center p-4">
                    <Button variant="outline" onClick={loadMore}>
                        {copy.inbox.load_more}
                    </Button>
                </div>
            )}
        </div>
    );
}
