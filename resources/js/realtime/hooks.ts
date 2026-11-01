import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { connect, getConnectionState, listen, onConnectionState } from './client';
import type { ChannelEvents, ConnectionState, SubscriptionState } from './client';

/** El estado de la conexión en vivo; se actualiza solo cuando cambia. */
export function useConnectionState(): ConnectionState {
    return useSyncExternalStore(onConnectionState, getConnectionState, getConnectionState);
}

/**
 * Entra a un canal privado mientras el componente existe, abriendo la conexión en vivo si aún no está abierta. Con
 * nombre nulo no hace nada (por ejemplo, sin ticket abierto). Si la conexión se cae y vuelve, la biblioteca vuelve a
 * suscribir el canal y esto avisa de nuevo. Los eventos pedidos se entregan siempre a la versión más reciente de cada
 * función, sin volver a suscribir en cada pintado; los nombres de los eventos se toman al entrar al canal.
 */
export function useChannel(name: string | null, events?: ChannelEvents): SubscriptionState {
    const [subscription, setSubscription] = useState<{ name: string | null; state: SubscriptionState }>({ name: null, state: 'pending' });
    const eventsRef = useRef(events);

    useEffect(() => {
        eventsRef.current = events;
    });

    useEffect(() => {
        if (name === null) return;

        connect();

        const handlers: ChannelEvents = {};
        for (const event of Object.keys(eventsRef.current ?? {})) {
            handlers[event] = (data) => {
                eventsRef.current?.[event]?.(data);
            };
        }

        return listen(
            name,
            (next) => {
                setSubscription({ name, state: next });
            },
            handlers,
        );
    }, [name]);

    return subscription.name === name ? subscription.state : 'pending';
}
