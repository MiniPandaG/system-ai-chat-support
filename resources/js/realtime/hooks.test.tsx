import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useChannel, useConnectionState } from './hooks';

const { connect, listen, stop } = vi.hoisted(() => ({ connect: vi.fn(), listen: vi.fn(), stop: vi.fn() }));
let state = 'disconnected';
const listeners = new Set<() => void>();

vi.mock('./client', () => ({
    connect,
    listen,
    getConnectionState: () => state,
    onConnectionState: (listener: () => void) => {
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    },
}));

describe('useConnectionState', () => {
    beforeEach(() => {
        state = 'disconnected';
        listeners.clear();
    });

    it('devuelve el estado actual y se actualiza cuando cambia', () => {
        const { result } = renderHook(() => useConnectionState());
        expect(result.current).toBe('disconnected');

        act(() => {
            state = 'connected';
            listeners.forEach((listener) => {
                listener();
            });
        });

        expect(result.current).toBe('connected');
    });

    it('deja de escuchar al desmontarse', () => {
        const { unmount } = renderHook(() => useConnectionState());
        expect(listeners.size).toBe(1);

        unmount();

        expect(listeners.size).toBe(0);
    });
});

describe('useChannel', () => {
    beforeEach(() => {
        connect.mockReset();
        listen.mockReset();
        stop.mockReset();
        listen.mockReturnValue(stop);
    });

    it('abre la conexión y entra al canal pedido', () => {
        renderHook(() => useChannel('tenant.1.inbox'));

        expect(connect).toHaveBeenCalledTimes(1);
        expect(listen).toHaveBeenCalledWith('tenant.1.inbox', expect.any(Function), {});
    });

    it('entrega los eventos pedidos a la versión más reciente de cada función', () => {
        const first = vi.fn();
        const second = vi.fn();
        const { rerender } = renderHook(({ handler }: { handler: () => void }) => useChannel('tenant.1.inbox', { 'inbox.changed': handler }), { initialProps: { handler: first } });
        const handlers = listen.mock.calls[0]?.[2] as Record<string, () => void>;

        rerender({ handler: second });
        handlers['inbox.changed']?.();

        expect(Object.keys(handlers)).toEqual(['inbox.changed']);
        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledTimes(1);
        expect(listen).toHaveBeenCalledTimes(1);
    });

    it('con nombre nulo no abre ni suscribe nada', () => {
        const { result } = renderHook(() => useChannel(null));

        expect(connect).not.toHaveBeenCalled();
        expect(listen).not.toHaveBeenCalled();
        expect(result.current).toBe('pending');
    });

    it('devuelve el resultado de la autorización cuando llega', () => {
        const { result } = renderHook(() => useChannel('tenant.1.inbox'));
        expect(result.current).toBe('pending');

        act(() => {
            (listen.mock.calls[0]?.[1] as (state: string) => void)('subscribed');
        });
        expect(result.current).toBe('subscribed');

        act(() => {
            (listen.mock.calls[0]?.[1] as (state: string) => void)('denied');
        });
        expect(result.current).toBe('denied');
    });

    it('al desmontarse deja el canal', () => {
        const { unmount } = renderHook(() => useChannel('tenant.1.inbox'));

        unmount();

        expect(stop).toHaveBeenCalledTimes(1);
    });

    it('al cambiar de canal deja el anterior y entra al nuevo, sin arrastrar el estado viejo', () => {
        const { result, rerender } = renderHook(({ name }: { name: string }) => useChannel(name), { initialProps: { name: 'tenant.1.tickets.1' } });
        act(() => {
            (listen.mock.calls[0]?.[1] as (state: string) => void)('subscribed');
        });
        expect(result.current).toBe('subscribed');

        rerender({ name: 'tenant.1.tickets.2' });

        expect(stop).toHaveBeenCalledTimes(1);
        expect(listen).toHaveBeenLastCalledWith('tenant.1.tickets.2', expect.any(Function), {});
        expect(result.current).toBe('pending');
    });

    it('pasar a nulo (por ejemplo, cerrar el ticket) deja el canal', () => {
        const initialProps: { name: string | null } = { name: 'tenant.1.tickets.1' };
        const { rerender } = renderHook(({ name }: { name: string | null }) => useChannel(name), { initialProps });

        rerender({ name: null });

        expect(stop).toHaveBeenCalledTimes(1);
    });
});
