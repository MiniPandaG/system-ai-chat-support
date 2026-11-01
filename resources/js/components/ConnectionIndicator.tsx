import { useConnectionState } from '../realtime/hooks';
import type { PanelCopy } from '../types';

interface ConnectionIndicatorProps {
    copy: Pick<PanelCopy, 'live_on' | 'live_connecting' | 'live_off'>;
}

/**
 * El estado de la conexión en vivo (design/patrones/conversacion.md): un punto discreto, nunca un aviso aparte. Verde
 * conectado, ámbar mientras conecta y gris sin conexión; siempre con texto accesible, no solo color.
 */
export function ConnectionIndicator({ copy }: ConnectionIndicatorProps) {
    const state = useConnectionState();
    const label = state === 'connected' ? copy.live_on : state === 'connecting' ? copy.live_connecting : copy.live_off;
    const dot = state === 'connected' ? 'bg-success' : state === 'connecting' ? 'bg-warning' : 'bg-text-tertiary';

    return (
        <span role="img" aria-label={label} title={label} className="inline-flex items-center gap-2 text-xs text-text-tertiary">
            <span aria-hidden="true" className={`size-2 rounded-full ${dot}`} />
            <span aria-hidden="true" className="hidden lg:inline">
                {label}
            </span>
        </span>
    );
}
