import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createTypingSender, joinNames, postTyping, TYPING_GAP_MS } from './typing';

describe('createTypingSender', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-01T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('avisa a la primera tecla y no repite durante 2 segundos, por muchas que se pulsen', () => {
        const send = vi.fn();
        const sender = createTypingSender(send);

        for (let index = 0; index < 50; index += 1) {
            sender();
            vi.advanceTimersByTime(20);
        }

        expect(send).toHaveBeenCalledTimes(1);
    });

    it('pasados 2 segundos vuelve a avisar', () => {
        const send = vi.fn();
        const sender = createTypingSender(send);

        sender();
        vi.advanceTimersByTime(TYPING_GAP_MS - 1);
        sender();
        expect(send).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(1);
        sender();
        expect(send).toHaveBeenCalledTimes(2);
    });

    it('cada limitador cuenta por separado', () => {
        const first = vi.fn();
        const second = vi.fn();

        createTypingSender(first)();
        createTypingSender(second)();

        expect(first).toHaveBeenCalledTimes(1);
        expect(second).toHaveBeenCalledTimes(1);
    });
});

describe('postTyping', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('pide al servidor que anuncie la señal, con la sesión y el token de CSRF, y sin contenido', () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';
        const fetchMock = vi.fn().mockResolvedValue({ ok: true });
        vi.stubGlobal('fetch', fetchMock);

        postTyping(42);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('/tickets/42/typing');
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers).toEqual({ 'X-XSRF-TOKEN': 'abc==', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' });
        expect(options.body).toBeUndefined();
    });

    it('si la petición falla no rompe nada ni se reintenta', async () => {
        const fetchMock = vi.fn().mockRejectedValue(new Error('sin red'));
        vi.stubGlobal('fetch', fetchMock);

        expect(() => {
            postTyping(1);
        }).not.toThrow();
        await Promise.resolve();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});

describe('joinNames', () => {
    it('forma una frase natural en español', () => {
        expect(joinNames([])).toBe('');
        expect(joinNames(['Ana'])).toBe('Ana');
        expect(joinNames(['Ana', 'Luis'])).toBe('Ana y Luis');
        expect(joinNames(['Ana', 'Luis', 'Pedro'])).toBe('Ana, Luis y Pedro');
    });
});
