import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { COALESCE_MS, createRefresher, useLiveRefresh } from './useLiveRefresh';

const { reload, useChannel } = vi.hoisted(() => ({ reload: vi.fn(), useChannel: vi.fn() }));
let connection = 'connected';

vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('./hooks', () => ({ useChannel, useConnectionState: () => connection }));

interface ReloadOptions {
    only: string[];
    onFinish: () => void;
}

const INBOX = ['tickets', 'next_cursor', 'total', 'counts', 'views', 'filter_tags', 'auth'];
const TICKET = ['selected', 'tag_options'];

function lastReload(): ReloadOptions {
    return reload.mock.calls.at(-1)?.[0] as ReloadOptions;
}

beforeEach(() => {
    vi.useFakeTimers();
    reload.mockReset();
    useChannel.mockReset();
    connection = 'connected';
});

afterEach(() => {
    vi.useRealTimers();
});

describe('createRefresher', () => {
    it('un aviso de la bandeja pide solo lo que la bandeja muestra', () => {
        const refresher = createRefresher();

        refresher.mark('inbox');
        vi.advanceTimersByTime(COALESCE_MS);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual(INBOX);
    });

    it('un aviso del ticket pide solo el ticket abierto y sus etiquetas', () => {
        const refresher = createRefresher();

        refresher.mark('ticket');
        vi.advanceTimersByTime(COALESCE_MS);

        expect(lastReload().only).toEqual(TICKET);
    });

    it('espera la ventana antes de pedir: no responde a cada aviso por separado', () => {
        const refresher = createRefresher();

        refresher.mark('inbox');
        vi.advanceTimersByTime(COALESCE_MS - 1);
        expect(reload).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('muchos avisos seguidos se juntan en una sola recarga', () => {
        const refresher = createRefresher();

        for (let index = 0; index < 20; index += 1) {
            refresher.mark('inbox');
            refresher.mark('ticket');
            vi.advanceTimersByTime(5);
        }
        vi.advanceTimersByTime(COALESCE_MS);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual([...INBOX, ...TICKET]);
    });

    it('no hay dos recargas a la vez: lo que llega mientras una está en curso se pide al terminar', () => {
        const refresher = createRefresher();
        refresher.mark('inbox');
        vi.advanceTimersByTime(COALESCE_MS);
        const first = lastReload();

        refresher.mark('ticket');
        vi.advanceTimersByTime(COALESCE_MS * 3);
        expect(reload).toHaveBeenCalledTimes(1);

        first.onFinish();
        vi.advanceTimersByTime(COALESCE_MS);

        expect(reload).toHaveBeenCalledTimes(2);
        expect(lastReload().only).toEqual(TICKET);
    });

    it('si no llega nada nuevo mientras una recarga está en curso, no pide otra al terminar', () => {
        const refresher = createRefresher();
        refresher.mark('inbox');
        vi.advanceTimersByTime(COALESCE_MS);

        lastReload().onFinish();
        vi.advanceTimersByTime(COALESCE_MS * 5);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('«markAll» pide la bandeja y, si hay ticket abierto, también el ticket', () => {
        const withTicket = createRefresher();
        withTicket.markAll(true);
        vi.advanceTimersByTime(COALESCE_MS);
        expect(lastReload().only).toEqual([...INBOX, ...TICKET]);

        reload.mockReset();
        const withoutTicket = createRefresher();
        withoutTicket.markAll(false);
        vi.advanceTimersByTime(COALESCE_MS);
        expect(lastReload().only).toEqual(INBOX);
    });

    it('al descartarlo cancela lo pendiente', () => {
        const refresher = createRefresher();
        refresher.mark('inbox');

        refresher.dispose();
        vi.advanceTimersByTime(COALESCE_MS * 5);

        expect(reload).not.toHaveBeenCalled();
    });
});

describe('useLiveRefresh', () => {
    function channelEvents(name: string): Record<string, () => void> {
        const call = useChannel.mock.calls.filter((candidate) => candidate[0] === name).at(-1);

        return (call?.[1] ?? {}) as Record<string, () => void>;
    }

    function channelNames(): (string | null)[] {
        return (useChannel.mock.calls as [string | null][]).map((call) => call[0]);
    }

    it('escucha la bandeja de la empresa y el ticket abierto, y nada más', () => {
        renderHook(() => { useLiveRefresh(7, 42); });

        const names = channelNames();
        expect(names).toContain('tenant.7.inbox');
        expect(names).toContain('tenant.7.tickets.42');
        expect(names.filter((name) => typeof name === 'string' && !name.startsWith('tenant.7.'))).toHaveLength(0);
    });

    it('sin ticket abierto no entra al canal de ningún ticket, y sin empresa no entra a ninguno', () => {
        renderHook(() => { useLiveRefresh(7, null); });
        expect(channelNames()).toEqual(['tenant.7.inbox', null]);

        useChannel.mockReset();
        renderHook(() => { useLiveRefresh(null, 42); });
        expect(channelNames()).toEqual([null, null]);
    });

    it('un aviso de la bandeja y uno del ticket llegan juntos a una sola recarga', () => {
        renderHook(() => { useLiveRefresh(7, 42); });

        act(() => {
            channelEvents('tenant.7.inbox')['inbox.changed']?.();
            channelEvents('tenant.7.tickets.42')['ticket.changed']?.();
            vi.advanceTimersByTime(COALESCE_MS);
        });

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual([...INBOX, ...TICKET]);
    });

    it('al abrir la pantalla con la conexión ya hecha no recarga: los datos son recientes', () => {
        renderHook(() => { useLiveRefresh(7, 42); });

        act(() => {
            vi.advanceTimersByTime(COALESCE_MS * 5);
        });

        expect(reload).not.toHaveBeenCalled();
    });

    it('al perder y recuperar la conexión pide todo una vez, porque los avisos de la caída se perdieron', () => {
        const { rerender } = renderHook(() => { useLiveRefresh(7, 42); });

        connection = 'unavailable';
        rerender();
        act(() => {
            vi.advanceTimersByTime(COALESCE_MS * 5);
        });
        expect(reload).not.toHaveBeenCalled();

        connection = 'connected';
        rerender();
        act(() => {
            vi.advanceTimersByTime(COALESCE_MS);
        });

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual([...INBOX, ...TICKET]);
    });

    it('conectar por primera vez después de abrir la pantalla no cuenta como recuperar', () => {
        connection = 'connecting';
        const { rerender } = renderHook(() => { useLiveRefresh(7, null); });

        connection = 'connected';
        rerender();
        act(() => {
            vi.advanceTimersByTime(COALESCE_MS * 5);
        });

        expect(reload).not.toHaveBeenCalled();
    });

    it('al recuperar la conexión sin ticket abierto pide solo la bandeja', () => {
        const { rerender } = renderHook(() => { useLiveRefresh(7, null); });

        connection = 'disconnected';
        rerender();
        connection = 'connected';
        rerender();
        act(() => {
            vi.advanceTimersByTime(COALESCE_MS);
        });

        expect(lastReload().only).toEqual(INBOX);
    });

    it('al cerrar la pantalla no deja recargas pendientes', () => {
        const { unmount } = renderHook(() => { useLiveRefresh(7, 42); });
        act(() => {
            channelEvents('tenant.7.inbox')['inbox.changed']?.();
        });

        unmount();
        vi.advanceTimersByTime(COALESCE_MS * 5);

        expect(reload).not.toHaveBeenCalled();
    });
});

describe('con la pestaña oculta', () => {
    function setVisibility(state: 'visible' | 'hidden'): void {
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => state });
    }

    afterEach(() => {
        setVisibility('visible');
    });

    it('no recarga mientras nadie la está viendo: abrir el ticket cuenta como leerlo', () => {
        setVisibility('hidden');
        const refresher = createRefresher();

        refresher.mark('ticket');
        vi.advanceTimersByTime(COALESCE_MS * 10);

        expect(reload).not.toHaveBeenCalled();
    });

    it('al volver a verse pide una sola vez lo que llegó mientras estaba oculta', () => {
        const refresher = createRefresher();
        setVisibility('hidden');
        refresher.mark('inbox');
        refresher.mark('ticket');
        refresher.mark('inbox');
        vi.advanceTimersByTime(COALESCE_MS * 10);

        setVisibility('visible');
        refresher.resume();
        vi.advanceTimersByTime(COALESCE_MS);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual([...INBOX, ...TICKET]);
    });

    it('«resume» sin nada pendiente no pide nada', () => {
        const refresher = createRefresher();

        refresher.resume();
        vi.advanceTimersByTime(COALESCE_MS * 5);

        expect(reload).not.toHaveBeenCalled();
    });

    it('el hook reanuda al cambiar la visibilidad de la página', () => {
        renderHook(() => {
            useLiveRefresh(7, 42);
        });
        setVisibility('hidden');
        act(() => {
            const events = useChannel.mock.calls.filter((call) => call[0] === 'tenant.7.tickets.42').at(-1)?.[1] as Record<string, () => void>;
            events['ticket.changed']?.();
            vi.advanceTimersByTime(COALESCE_MS * 5);
        });
        expect(reload).not.toHaveBeenCalled();

        setVisibility('visible');
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
            vi.advanceTimersByTime(COALESCE_MS);
        });

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual(TICKET);
    });
});
