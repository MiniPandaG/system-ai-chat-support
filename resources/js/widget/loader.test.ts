import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { isWidgetKey, layoutFor, mount, parseState } from './loader';

const KEY = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
const BASE = 'https://app.geckochat.test';

function scriptTag(key: string | null = KEY): HTMLScriptElement {
    const script = document.createElement('script');
    script.src = `${BASE}/widget.js`;
    if (key !== null) script.dataset.key = key;

    return script;
}

function frameMessage(frame: HTMLIFrameElement, data: unknown, origin = BASE, source: MessageEventSource | null = frame.contentWindow): void {
    window.dispatchEvent(new MessageEvent('message', { data, origin, source }));
}

function mountFrame(): HTMLIFrameElement {
    const frame = mount(scriptTag(), window);
    if (frame === null) throw new Error('El iframe no se montó');

    return frame;
}

const state = { source: 'geckochat-widget', type: 'state', open: false, corner: 'right', unread: 0 };

describe('parseState', () => {
    it('acepta el mensaje con la forma exacta del marco', () => {
        expect(parseState(state)).toEqual({ open: false, corner: 'right' });
        expect(parseState({ ...state, open: true, corner: 'left' })).toEqual({ open: true, corner: 'left' });
    });

    it.each([
        ['nulo', null],
        ['texto', 'abierto'],
        ['otra fuente', { ...state, source: 'otro-script' }],
        ['otro tipo', { ...state, type: 'resize' }],
        ['abierto no booleano', { ...state, open: 'true' }],
        ['esquina inventada', { ...state, corner: 'top' }],
        ['sin esquina', { source: 'geckochat-widget', type: 'state', open: true }],
    ])('descarta %s', (_name, data) => {
        expect(parseState(data)).toBeNull();
    });
});

describe('layoutFor', () => {
    it('cerrado es solo el botón', () => {
        expect(layoutFor(false, { width: 1200, height: 800 })).toEqual({ width: '88px', height: '88px', fullscreen: false });
    });

    it('abierto en escritorio es la ventana de 360×560 más su margen', () => {
        expect(layoutFor(true, { width: 1200, height: 800 })).toEqual({ width: '392px', height: '592px', fullscreen: false });
    });

    it('abierto en una pantalla baja no se sale de ella', () => {
        expect(layoutFor(true, { width: 1200, height: 500 })).toEqual({ width: '392px', height: '500px', fullscreen: false });
    });

    it('por debajo de 480 px ocupa toda la pantalla', () => {
        expect(layoutFor(true, { width: 360, height: 640 })).toEqual({ width: '100%', height: '100%', fullscreen: true });
        expect(layoutFor(true, { width: 479, height: 640 }).fullscreen).toBe(true);
        expect(layoutFor(true, { width: 480, height: 640 }).fullscreen).toBe(false);
    });
});

describe('isWidgetKey', () => {
    it('solo acepta 32 letras y números', () => {
        expect(isWidgetKey(KEY)).toBe(true);
        expect(isWidgetKey(undefined)).toBe(false);
        expect(isWidgetKey(KEY.slice(1))).toBe(false);
        expect(isWidgetKey(`${KEY}x`)).toBe(false);
        expect(isWidgetKey(`${KEY.slice(1)}"`)).toBe(false);
        expect(isWidgetKey(`${KEY.slice(1)}&`)).toBe(false);
    });
});

describe('mount', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('crea un iframe aislado hacia el marco de GeckoChat con la llave y el sitio que lo incrusta', () => {
        const frame = mount(scriptTag(), window);

        expect(frame).not.toBeNull();
        expect(frame?.src).toBe(`${BASE}/widget/frame?key=${KEY}&parent=${encodeURIComponent(window.location.origin)}`);
        expect(frame?.getAttribute('sandbox')).toBe('allow-scripts allow-same-origin allow-forms');
        expect(frame?.getAttribute('sandbox')).not.toContain('allow-top-navigation');
        expect(frame?.getAttribute('sandbox')).not.toContain('allow-popups');
        expect(frame?.getAttribute('allow')).toBe('');
        expect(frame?.title).toBe('Chat de soporte');
        expect(document.body.contains(frame)).toBe(true);
    });

    it('permite poner el título en otro idioma desde el atributo data-title', () => {
        const script = scriptTag();
        script.dataset.title = 'Support chat';

        expect(mount(script, window)?.title).toBe('Support chat');
    });

    it('no monta nada con una llave ausente o mal formada', () => {
        expect(mount(scriptTag(null), window)).toBeNull();
        expect(mount(scriptTag('corta'), window)).toBeNull();
        expect(document.querySelector('iframe')).toBeNull();
    });

    it('no monta dos veces', () => {
        expect(mount(scriptTag(), window)).not.toBeNull();
        expect(mount(scriptTag(), window)).toBeNull();
        expect(document.querySelectorAll('iframe')).toHaveLength(1);
    });

    it('queda oculto hasta que el marco dice cómo está', () => {
        const frame = mountFrame();
        expect(frame.style.visibility).toBe('hidden');

        frameMessage(frame, state);

        expect(frame.style.visibility).toBe('visible');
        expect(frame.style.right).toBe('0px');
        expect(frame.style.width).toBe('88px');
    });

    it('al abrirse crece y se ancla a la esquina que dijo el marco', () => {
        const frame = mountFrame();
        Object.defineProperty(window, 'innerWidth', { value: 1200, configurable: true });
        Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true });

        frameMessage(frame, { ...state, open: true, corner: 'left' });

        expect(frame.style.width).toBe('392px');
        expect(frame.style.height).toBe('592px');
        expect(frame.style.left).toBe('0px');
        expect(frame.style.right).toBe('auto');
    });

    it('le dice al marco si va en pantalla completa, y solo a su origen', () => {
        const frame = mountFrame();
        const post = vi.spyOn((frame.contentWindow ?? window), 'postMessage');
        Object.defineProperty(window, 'innerWidth', { value: 360, configurable: true });

        frameMessage(frame, { ...state, open: true });

        expect(post).toHaveBeenCalledWith({ source: 'geckochat-loader', type: 'layout', fullscreen: true }, BASE);
    });

    it('ignora mensajes de otro origen, de otra ventana o con otra forma', () => {
        const frame = mountFrame();

        frameMessage(frame, state, 'https://malicioso.example');
        frameMessage(frame, state, BASE, window);
        frameMessage(frame, { ...state, open: 'si' });
        frameMessage(frame, state, BASE, null);

        expect(frame.style.visibility).toBe('hidden');
    });

    it('no toca nada de la página fuera de su iframe', () => {
        document.body.innerHTML = '<main id="sitio">contenido</main>';
        const before = document.body.innerHTML;

        mount(scriptTag(), window);

        expect(document.getElementById('sitio')?.outerHTML).toBe('<main id="sitio">contenido</main>');
        expect(document.body.innerHTML.startsWith(before)).toBe(true);
        expect(document.body.children).toHaveLength(2);
    });
});
