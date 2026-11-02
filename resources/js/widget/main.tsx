import { createRoot } from 'react-dom/client';
import { configureChannelAuth } from '../realtime/client';
import { readStored } from './storage';
import { tokenKey } from './useChat';
import type { WidgetConfig } from './types';
import { Widget } from './Widget';

/** Lee la configuración que el servidor dejó en la página; si no tiene la forma esperada, el widget no arranca. */
function readConfig(root: HTMLElement): WidgetConfig | null {
    try {
        const parsed: unknown = JSON.parse(root.dataset.config ?? '');

        if (typeof parsed !== 'object' || parsed === null) return null;
        const config = parsed as Partial<WidgetConfig>;

        return typeof config.key === 'string' && typeof config.parent === 'string' && typeof config.company === 'string' && typeof config.copy === 'object' ? (config as WidgetConfig) : null;
    } catch {
        return null;
    }
}

const root = document.getElementById('widget-root');
const config = root === null ? null : readConfig(root);

if (root !== null && config !== null) {
    // El visitante no tiene cookies ni sesión del panel: el canal en vivo se autoriza con el token de su conversación.
    configureChannelAuth({
        endpoint: '/widget/api/broadcasting/auth',
        headers: () => ({ Accept: 'application/json', Authorization: `Bearer ${readStored(tokenKey(config.key)) ?? ''}` }),
    });

    createRoot(root).render(<Widget config={config} />);
}
