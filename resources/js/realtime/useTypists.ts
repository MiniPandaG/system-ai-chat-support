import { useEffect, useRef, useState } from 'react';
import { TYPING_TTL_MS } from '../lib/typing';
import { useChannel } from './hooks';

function userOf(data: unknown): number | null {
    if (typeof data !== 'object' || data === null || !('user' in data)) return null;

    return typeof data.user === 'number' && Number.isInteger(data.user) ? data.user : null;
}

/**
 * Quién está escribiendo ahora en el ticket, por identificador y sin contar a la propia persona. Cada señal dura 5
 * segundos y una nueva la renueva. El contenido del aviso no se toma como confiable: lo que no tiene la forma esperada
 * se descarta.
 */
export function useTypists(tenantId: number | null, ticketId: number, selfId: number | null): number[] {
    const [typists, setTypists] = useState<number[]>([]);
    const timersRef = useRef(new Map<number, number>());

    useChannel(tenantId === null ? null : `tenant.${String(tenantId)}.tickets.${String(ticketId)}`, {
        'ticket.typing': (data) => {
            const user = userOf(data);
            if (user === null || user === selfId) return;

            window.clearTimeout(timersRef.current.get(user));
            timersRef.current.set(
                user,
                window.setTimeout(() => {
                    timersRef.current.delete(user);
                    setTypists((current) => current.filter((id) => id !== user));
                }, TYPING_TTL_MS),
            );
            setTypists((current) => (current.includes(user) ? current : [...current, user]));
        },
    });

    useEffect(() => {
        const pending = timersRef.current;

        return () => {
            pending.forEach((timer) => {
                window.clearTimeout(timer);
            });
            pending.clear();
        };
    }, []);

    return typists;
}
