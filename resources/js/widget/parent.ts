import type { FrameState } from './types';

/** Identifica los mensajes del marco, y los del script incrustado, entre todo lo que una página recibe por postMessage. */
export const FRAME_SOURCE = 'geckochat-widget';
export const LOADER_SOURCE = 'geckochat-loader';

/**
 * Le cuenta al script incrustado cómo está el widget (abierto o cerrado, mensajes sin leer, esquina) para que ajuste el
 * tamaño del iframe. El destino es el sitio que la empresa permitió y ningún otro: si la página que lo incrusta no es
 * ese origen, el navegador no entrega el mensaje. Fuera de un iframe no hace nada.
 */
export function notifyParent(parentOrigin: string, state: FrameState): void {
    if (window.parent === window) return;

    window.parent.postMessage({ source: FRAME_SOURCE, type: 'state', ...state }, parentOrigin);
}
