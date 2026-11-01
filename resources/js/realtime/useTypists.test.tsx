import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TYPING_TTL_MS } from '../lib/typing';
import { useTypists } from './useTypists';

const { useChannel } = vi.hoisted(() => ({ useChannel: vi.fn() }));

vi.mock('./hooks', () => ({ useChannel }));

function signal(data: unknown): void {
    const events = useChannel.mock.calls.at(-1)?.[1] as Record<string, (payload: unknown) => void>;
    events['ticket.typing']?.(data);
}

beforeEach(() => {
    vi.useFakeTimers();
    useChannel.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('useTypists', () => {
    it('escucha solo el canal de ese ticket de esa empresa', () => {
        renderHook(() => useTypists(7, 42, 1));

        expect(useChannel.mock.calls[0]?.[0]).toBe('tenant.7.tickets.42');
    });

    it('sin empresa no escucha nada', () => {
        renderHook(() => useTypists(null, 42, 1));

        expect(useChannel.mock.calls[0]?.[0]).toBeNull();
    });

    it('muestra a quien escribe y lo quita a los 5 segundos sin nuevas señales', () => {
        const { result } = renderHook(() => useTypists(7, 42, 1));

        act(() => {
            signal({ user: 5 });
        });
        expect(result.current).toEqual([5]);

        act(() => {
            vi.advanceTimersByTime(TYPING_TTL_MS - 1);
        });
        expect(result.current).toEqual([5]);

        act(() => {
            vi.advanceTimersByTime(1);
        });
        expect(result.current).toEqual([]);
    });

    it('una señal nueva renueva el plazo en vez de sumar otro', () => {
        const { result } = renderHook(() => useTypists(7, 42, 1));

        act(() => {
            signal({ user: 5 });
            vi.advanceTimersByTime(4000);
            signal({ user: 5 });
            vi.advanceTimersByTime(4000);
        });
        expect(result.current).toEqual([5]);

        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(result.current).toEqual([]);
    });

    it('varias personas a la vez, cada una con su plazo', () => {
        const { result } = renderHook(() => useTypists(7, 42, 1));

        act(() => {
            signal({ user: 5 });
            vi.advanceTimersByTime(3000);
            signal({ user: 6 });
        });
        expect(result.current).toEqual([5, 6]);

        act(() => {
            vi.advanceTimersByTime(2000);
        });
        expect(result.current).toEqual([6]);
    });

    it('ignora las señales de la propia persona', () => {
        const { result } = renderHook(() => useTypists(7, 42, 5));

        act(() => {
            signal({ user: 5 });
        });

        expect(result.current).toEqual([]);
    });

    it('descarta lo que no tiene la forma esperada: el contenido del aviso no es de fiar', () => {
        const { result } = renderHook(() => useTypists(7, 42, 1));

        act(() => {
            signal(null);
            signal('texto');
            signal({});
            signal({ user: '5' });
            signal({ user: 1.5 });
            signal({ user: { id: 5 } });
            signal([5]);
        });

        expect(result.current).toEqual([]);
    });

    it('al cerrar el ticket no deja temporizadores pendientes', () => {
        const { unmount } = renderHook(() => useTypists(7, 42, 1));
        act(() => {
            signal({ user: 5 });
        });

        unmount();

        expect(vi.getTimerCount()).toBe(0);
    });
});
