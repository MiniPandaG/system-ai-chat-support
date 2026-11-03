import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { REFRESH_MS, createReloader, useKnowledgeRefresh } from './useKnowledgeRefresh';

const { reload, useChannel } = vi.hoisted(() => ({ reload: vi.fn(), useChannel: vi.fn() }));
let connection = 'connected';

vi.mock('@inertiajs/react', () => ({ router: { reload } }));
vi.mock('./hooks', () => ({ useChannel, useConnectionState: () => connection }));

interface ReloadOptions {
    only: string[];
    onFinish: () => void;
}

function lastReload(): ReloadOptions {
    return reload.mock.calls.at(-1)?.[0] as ReloadOptions;
}

function visibility(state: 'visible' | 'hidden') {
    Object.defineProperty(document, 'visibilityState', { value: state, configurable: true });
}

beforeEach(() => {
    vi.useFakeTimers();
    reload.mockReset();
    useChannel.mockReset();
    connection = 'connected';
    visibility('visible');
});

afterEach(() => {
    vi.useRealTimers();
    visibility('visible');
});

describe('createReloader', () => {
    it('un aviso pide solo lo indicado, tras la ventana corta', () => {
        const reloader = createReloader(['documents', 'total']);

        reloader.mark();
        expect(reload).not.toHaveBeenCalled();
        vi.advanceTimersByTime(REFRESH_MS);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(lastReload().only).toEqual(['documents', 'total']);
    });

    it('varios avisos seguidos se juntan en una sola recarga', () => {
        const reloader = createReloader(['documents']);

        reloader.mark();
        reloader.mark();
        reloader.mark();
        vi.advanceTimersByTime(REFRESH_MS);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('nunca hay dos recargas a la vez: lo que llega durante una se pide al terminar', () => {
        const reloader = createReloader(['documents']);
        reloader.mark();
        vi.advanceTimersByTime(REFRESH_MS);

        reloader.mark();
        vi.advanceTimersByTime(REFRESH_MS * 3);
        expect(reload).toHaveBeenCalledTimes(1);

        lastReload().onFinish();
        vi.advanceTimersByTime(REFRESH_MS);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it('una pestaña oculta no recarga y lo pendiente se pide al volver a verse', () => {
        visibility('hidden');
        const reloader = createReloader(['documents']);

        reloader.mark();
        vi.advanceTimersByTime(REFRESH_MS * 2);
        expect(reload).not.toHaveBeenCalled();

        visibility('visible');
        reloader.resume();
        vi.advanceTimersByTime(REFRESH_MS);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('cambiar lo que se pide aplica a la siguiente recarga', () => {
        const reloader = createReloader(['documents']);
        reloader.setOnly(['document']);

        reloader.mark();
        vi.advanceTimersByTime(REFRESH_MS);

        expect(lastReload().only).toEqual(['document']);
    });

    it('al desechar el recargador no queda ninguna recarga pendiente', () => {
        const reloader = createReloader(['documents']);
        reloader.mark();

        reloader.dispose();
        vi.advanceTimersByTime(REFRESH_MS * 3);

        expect(reload).not.toHaveBeenCalled();
    });
});

describe('useKnowledgeRefresh', () => {
    it('entra al canal privado de la empresa y recarga con el aviso del servidor', () => {
        renderHook(() => useKnowledgeRefresh(7, ['documents']));

        const handlers = useChannel.mock.calls.at(-1)?.[1] as Record<string, () => void>;
        expect(useChannel.mock.calls.at(-1)?.[0]).toBe('tenant.7.knowledge');
        expect(Object.keys(handlers)).toEqual(['knowledge.changed']);
        act(() => {
            handlers['knowledge.changed']?.();
            vi.advanceTimersByTime(REFRESH_MS);
        });

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('sin empresa no entra a ningún canal', () => {
        renderHook(() => useKnowledgeRefresh(null, ['documents']));

        expect(useChannel).toHaveBeenCalledWith(null, expect.anything());
    });

    it('devuelve el estado de la conexión', () => {
        connection = 'disconnected';

        const { result } = renderHook(() => useKnowledgeRefresh(7, ['documents']));

        expect(result.current).toBe('disconnected');
    });

    it('al recuperar la conexión tras una caída pide todo una vez', () => {
        const { rerender } = renderHook(() => useKnowledgeRefresh(7, ['documents']));
        expect(reload).not.toHaveBeenCalled();

        connection = 'unavailable';
        rerender();
        connection = 'connected';
        rerender();
        act(() => {
            vi.advanceTimersByTime(REFRESH_MS);
        });

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('la primera conexión no recarga', () => {
        connection = 'connecting';
        const { rerender } = renderHook(() => useKnowledgeRefresh(7, ['documents']));

        connection = 'connected';
        rerender();
        act(() => {
            vi.advanceTimersByTime(REFRESH_MS * 3);
        });

        expect(reload).not.toHaveBeenCalled();
    });
});
