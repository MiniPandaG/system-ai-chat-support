import { xsrfToken } from '../realtime/client';

/** Cada cuánto, como máximo, se avisa al servidor de que se está escribiendo. */
export const TYPING_GAP_MS = 2000;

/** Cuánto dura el «está escribiendo» de alguien sin recibir otra señal suya. */
export const TYPING_TTL_MS = 5000;

/**
 * Devuelve una función que avisa como máximo una vez cada 2 segundos, por muchas teclas que se pulsen. El servidor
 * aplica su propio límite; esto solo evita pedir lo que se va a rechazar.
 */
export function createTypingSender(send: () => void, gapMs: number = TYPING_GAP_MS): () => void {
    let last = Number.NEGATIVE_INFINITY;

    return () => {
        const now = Date.now();
        if (now - last < gapMs) return;

        last = now;
        send();
    };
}

/**
 * Pide al servidor que anuncie que esta persona está escribiendo en el ticket. No importa si falla (sin red, límite
 * alcanzado): es una señal pasajera y no hay nada que reintentar ni que mostrar.
 */
export function postTyping(ticketId: number): void {
    fetch(`/tickets/${String(ticketId)}/typing`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    }).catch(() => undefined);
}

/** «Ana», «Ana y Luis», «Ana, Luis y Pedro»: los nombres en una frase natural. */
export function joinNames(names: string[]): string {
    if (names.length <= 1) return names[0] ?? '';

    return `${names.slice(0, -1).join(', ')} y ${names[names.length - 1] ?? ''}`;
}
