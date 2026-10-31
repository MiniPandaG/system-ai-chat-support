import { afterEach, describe, expect, it, vi } from 'vitest';
import { checkPalette, isHex, overrides } from './palette';
import type { Palette } from './palette';

const defaults: Palette = {
    primary: '#F65F1E',
    secondary: '#2E5EAA',
    accent: '#2BAFBF',
    background: '#15110D',
    surface: '#1D1813',
    text: '#F6F1EA',
    success: '#35A373',
    warning: '#E0A526',
    danger: '#C83B3F',
    info: '#2E6FB0',
};

describe('palette', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('isHex solo acepta #RRGGBB exacto', () => {
        expect(isHex('#F65F1E')).toBe(true);
        expect(isHex('#f65f1e')).toBe(true);
        expect(['#FFF', 'F65F1E', '#F65F1E0', 'red', '#F65F1E\\n', ''].some(isHex)).toBe(false);
    });

    it('overrides conserva solo lo que difiere de fábrica, en mayúsculas', () => {
        expect(overrides({ ...defaults, primary: '#2164da', accent: '#2bafbf' }, defaults)).toEqual({ primary: '#2164DA' });
        expect(overrides(defaults, defaults)).toEqual({});
    });

    it('checkPalette envía la paleta con el token CSRF y devuelve el informe', async () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('abc=/def')}`;
        const report = { ok: true, colors: defaults, foregrounds: {}, issues: [] };
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve(report) });
        vi.stubGlobal('fetch', fetchMock);

        const result = await checkPalette({ primary: '#2164DA' });

        expect(result).toEqual(report);

        const [url, options] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Record<string, string> }];

        expect(url).toBe('/settings/branding/palette/check');
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(options.headers['X-XSRF-TOKEN']).toBe('abc=/def');
        expect(JSON.parse(options.body as string)).toEqual({ colors: { primary: '#2164DA' } });
    });

    it('devuelve nulo si el servidor responde con error o falla la red', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, json: () => Promise.resolve({}) }));
        expect(await checkPalette({})).toBeNull();

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('sin red')));
        expect(await checkPalette({})).toBeNull();
    });
});
