import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ConnectionState } from './client';
import { useChannel, useConnectionState } from './hooks';

/** Ventana en la que varios avisos seguidos se juntan en una sola recarga. */
export const REFRESH_MS = 50;

/**
 * Junta los avisos en recargas parciales de la pantalla: nunca hay dos a la vez, una pestaña oculta no recarga hasta
 * volver a verse, y lo que llega mientras una recarga está en curso se pide al terminar. Una recarga es una petición
 * normal, así que lo que llega pasa por los mismos permisos y el mismo filtro de empresa de siempre.
 */
export function createReloader(initial: string[]) {
    let only = initial;
    let pending = false;
    let loading = false;
    let timer: number | null = null;

    const schedule = () => {
        if (timer !== null) return;

        timer = window.setTimeout(flush, REFRESH_MS);
    };

    function flush(): void {
        timer = null;

        if (!pending || loading || document.visibilityState === 'hidden') return;

        pending = false;
        loading = true;
        router.reload({
            only,
            onFinish: () => {
                loading = false;
                if (pending) schedule();
            },
        });
    }

    return {
        setOnly(next: string[]): void {
            only = next;
        },
        mark(): void {
            pending = true;
            schedule();
        },
        resume(): void {
            if (pending) schedule();
        },
        dispose(): void {
            if (timer !== null) window.clearTimeout(timer);
            timer = null;
        },
    };
}

/**
 * Mantiene al día los datos de la base de conocimiento que la pantalla muestra (el estado de un documento) sin que nadie
 * recargue. Los avisos del servidor solo dicen «algo cambió»; aquí se vuelve a pedir lo que se ve. Al recuperar la
 * conexión se pide todo una vez, porque los avisos de la caída se perdieron. Devuelve el estado de la conexión para que la
 * pantalla avise cuando no hay tiempo real.
 */
export function useKnowledgeRefresh(tenantId: number | null, only: string[]): ConnectionState {
    const state = useConnectionState();
    const [reloader] = useState(() => createReloader(only));
    const wasConnectedRef = useRef(false);
    const droppedRef = useRef(false);

    useEffect(() => {
        reloader.setOnly(only);
    });

    useChannel(tenantId === null ? null : `tenant.${String(tenantId)}.knowledge`, {
        'knowledge.changed': () => {
            reloader.mark();
        },
    });

    useEffect(() => {
        if (state === 'connected') {
            if (droppedRef.current) {
                droppedRef.current = false;
                reloader.mark();
            }
            wasConnectedRef.current = true;
        } else if (wasConnectedRef.current) {
            droppedRef.current = true;
        }
    }, [state, reloader]);

    useEffect(() => {
        const onVisibility = () => {
            if (document.visibilityState === 'visible') reloader.resume();
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            reloader.dispose();
        };
    }, [reloader]);

    return state;
}
