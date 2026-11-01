import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useChannel, useConnectionState } from './hooks';

/**
 * Ventana en la que varios avisos seguidos se juntan en una sola recarga. El servidor emite el aviso del ticket y el de
 * la bandeja uno tras otro, así que una ventana corta basta para juntarlos sin retrasar lo que se ve (RNF-03).
 */
export const COALESCE_MS = 50;

/** Lo que la bandeja muestra y cuenta; el ticket abierto y sus etiquetas se piden aparte. */
const INBOX_PROPS = ['tickets', 'next_cursor', 'total', 'counts', 'views', 'filter_tags', 'auth'];
const TICKET_PROPS = ['selected', 'tag_options'];

type Kind = 'inbox' | 'ticket';

/**
 * Junta los avisos en recargas: los que llegan seguidos se piden juntos cada 150 ms y nunca hay dos recargas a la
 * vez; si llega algo mientras una está en curso, se pide otra al terminar. Una recarga es una petición normal a la
 * pantalla, así que lo que llega pasa por los mismos permisos y el mismo filtro de empresa de siempre.
 */
export function createRefresher() {
    let pending = { inbox: false, ticket: false };
    let timer: number | null = null;
    let loading = false;

    const schedule = () => {
        if (timer !== null) return;

        timer = window.setTimeout(flush, COALESCE_MS);
    };

    function flush(): void {
        timer = null;
        // Una pestaña oculta no recarga: abrir o recargar el ticket cuenta como leerlo, y nadie lo está viendo. Lo pendiente
        // se pide cuando la pestaña vuelve a verse.
        if (document.visibilityState === 'hidden' || loading) return;

        const only = [...(pending.inbox ? INBOX_PROPS : []), ...(pending.ticket ? TICKET_PROPS : [])];
        if (only.length === 0) return;

        pending = { inbox: false, ticket: false };
        loading = true;
        router.reload({
            only,
            onFinish: () => {
                loading = false;
                if (pending.inbox || pending.ticket) schedule();
            },
        });
    }

    return {
        mark(kind: Kind): void {
            pending[kind] = true;
            schedule();
        },
        markAll(withTicket: boolean): void {
            pending = { inbox: true, ticket: withTicket };
            schedule();
        },
        /** La pestaña volvió a verse: se piden los cambios que llegaron mientras estaba oculta. */
        resume(): void {
            if (pending.inbox || pending.ticket) schedule();
        },
        dispose(): void {
            if (timer !== null) window.clearTimeout(timer);
            timer = null;
        },
    };
}

/**
 * Mantiene la bandeja y el ticket abierto al día sin que nadie recargue. Los avisos del servidor solo dicen «algo
 * cambió»; aquí se vuelve a pedir lo que se ve. Al recuperar la conexión se pide todo una vez, porque los avisos de la
 * caída se perdieron.
 */
export function useLiveRefresh(tenantId: number | null, ticketId: number | null): void {
    const [refresher] = useState(createRefresher);
    const state = useConnectionState();
    const wasConnectedRef = useRef(false);
    const droppedRef = useRef(false);

    useChannel(tenantId === null ? null : `tenant.${String(tenantId)}.inbox`, {
        'inbox.changed': () => {
            refresher.mark('inbox');
        },
    });
    useChannel(tenantId === null || ticketId === null ? null : `tenant.${String(tenantId)}.tickets.${String(ticketId)}`, {
        'ticket.changed': () => {
            refresher.mark('ticket');
        },
    });

    useEffect(() => {
        if (state === 'connected') {
            if (droppedRef.current) {
                droppedRef.current = false;
                refresher.markAll(ticketId !== null);
            }
            wasConnectedRef.current = true;
        } else if (wasConnectedRef.current) {
            droppedRef.current = true;
        }
    }, [state, ticketId, refresher]);

    useEffect(() => {
        const onVisibility = () => {
            if (document.visibilityState === 'visible') refresher.resume();
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            refresher.dispose();
        };
    }, [refresher]);
}
