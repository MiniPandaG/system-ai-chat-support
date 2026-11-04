// El script que la empresa pega en su sitio. Es lo único del widget que corre en la página del cliente, así que es pequeño,
// no usa bibliotecas y no lee ni escribe nada de la página: crea un iframe con restricciones y le da tamaño. Todo lo demás
// (la interfaz, la conversación, el token del visitante) vive dentro del iframe, en el origen de GeckoChat.

const FRAME_SOURCE = 'geckochat-widget';
const LOADER_SOURCE = 'geckochat-loader';
const FRAME_ID = 'geckochat-widget';

const CLOSED_SIZE = 88;
const px = (value: number): string => String(value) + 'px';
const WINDOW_WIDTH = 392;
const WINDOW_HEIGHT = 592;
const FULLSCREEN_BELOW = 480;

export interface FrameState {
    open: boolean;
    corner: 'right' | 'left';
}

/** Lo que el iframe cuenta de sí mismo; todo lo que no tenga exactamente esta forma se descarta. */
export function parseState(data: unknown): FrameState | null {
    if (typeof data !== 'object' || data === null) return null;
    const { source, type, open, corner } = data as Record<string, unknown>;

    if (source !== FRAME_SOURCE || type !== 'state' || typeof open !== 'boolean' || (corner !== 'right' && corner !== 'left')) return null;

    return { open, corner };
}

export interface Layout {
    width: string;
    height: string;
    fullscreen: boolean;
}

/** El tamaño del iframe: el botón solo, la ventana en la esquina, o toda la pantalla en un móvil. */
export function layoutFor(open: boolean, viewport: { width: number; height: number }): Layout {
    if (!open) return { width: px(CLOSED_SIZE), height: px(CLOSED_SIZE), fullscreen: false };
    if (viewport.width < FULLSCREEN_BELOW) return { width: '100%', height: '100%', fullscreen: true };

    return {
        width: px(Math.min(WINDOW_WIDTH, viewport.width)),
        height: px(Math.min(WINDOW_HEIGHT, viewport.height)),
        fullscreen: false,
    };
}

/** La llave pública de la empresa: 32 letras y números. Cualquier otra cosa no monta nada. */
export function isWidgetKey(value: string | undefined): value is string {
    return value !== undefined && /^[A-Za-z0-9]{32}$/.test(value);
}

export function mount(script: HTMLScriptElement, win: Window): HTMLIFrameElement | null {
    const doc = win.document;
    const key = script.dataset.key;

    if (!isWidgetKey(key) || doc.getElementById(FRAME_ID) !== null) return null;

    const base = new URL(script.src, win.location.href).origin;
    const frame = doc.createElement('iframe');
    frame.id = FRAME_ID;
    frame.title = script.dataset.title ?? 'Chat de soporte';
    frame.src = `${base}/widget/frame?key=${key}&parent=${encodeURIComponent(win.location.origin)}`;
    // Sin permisos de navegación, ventanas emergentes ni cámara o micrófono: el iframe no puede tocar la página.
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms');
    frame.setAttribute('allow', '');
    frame.setAttribute('referrerpolicy', 'origin');

    const style = frame.style;
    style.setProperty('position', 'fixed');
    style.setProperty('bottom', '0');
    style.setProperty('border', '0');
    style.setProperty('background', 'transparent');
    style.setProperty('z-index', '2147483000');
    // Hasta que el iframe diga en qué esquina va y cómo está, no se muestra.
    style.setProperty('visibility', 'hidden');
    style.setProperty('width', px(CLOSED_SIZE));
    style.setProperty('height', px(CLOSED_SIZE));

    let last: FrameState | null = null;

    const apply = () => {
        if (last === null) return;

        const layout = layoutFor(last.open, { width: win.innerWidth, height: win.innerHeight });
        style.setProperty('width', layout.width);
        style.setProperty('height', layout.height);
        style.setProperty('right', last.corner === 'right' ? '0' : 'auto');
        style.setProperty('left', last.corner === 'left' ? '0' : 'auto');
        style.setProperty('visibility', 'visible');
        frame.contentWindow?.postMessage({ source: LOADER_SOURCE, type: 'layout', fullscreen: layout.fullscreen }, base);
    };

    win.addEventListener('message', (event: MessageEvent) => {
        // Solo el iframe que este script creó, y solo desde el origen de GeckoChat.
        if (event.origin !== base || event.source !== frame.contentWindow) return;

        const state = parseState(event.data);
        if (state === null) return;

        last = state;
        apply();
    });
    win.addEventListener('resize', apply);

    doc.body.appendChild(frame);

    return frame;
}

if (typeof document !== 'undefined' && document.currentScript instanceof HTMLScriptElement) {
    const script = document.currentScript;

    // Si el cuerpo de la página aún no existe, se espera a que cargue.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            mount(script, window);
        });
    } else {
        mount(script, window);
    }
}
