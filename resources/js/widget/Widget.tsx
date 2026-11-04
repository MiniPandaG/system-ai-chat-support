import { useCallback, useEffect, useRef, useState } from 'react';
import { ChatWindow } from './ChatWindow';
import { Launcher } from './Launcher';
import { LOADER_SOURCE, notifyParent } from './parent';
import { readStored, writeStored } from './storage';
import type { WidgetConfig } from './types';
import { useChat } from './useChat';

interface WidgetProps {
    config: WidgetConfig;
}

const seenKey = (key: string): string => `geckochat:${key}:seen`;

/** El mensaje con el que el script incrustado dice cómo está dispuesto el iframe en la página. */
function layoutOf(data: unknown): { fullscreen: boolean } | null {
    if (typeof data !== 'object' || data === null) return null;
    const { source, type, fullscreen } = data as Record<string, unknown>;

    return source === LOADER_SOURCE && type === 'layout' && typeof fullscreen === 'boolean' ? { fullscreen } : null;
}

/**
 * El widget: el botón flotante o la ventana. Mantiene el estado de abierto, los mensajes sin leer y la comunicación con
 * la página que lo incrusta, que solo se acepta de esa página y de su ventana.
 */
export function Widget({ config }: WidgetProps) {
    const chat = useChat(config.key);
    const [open, setOpen] = useState(false);
    const [began, setBegan] = useState(false);
    const [fullscreen, setFullscreen] = useState(false);
    const [seen, setSeen] = useState(() => Number(readStored(seenKey(config.key)) ?? 0) || 0);
    const launcherRef = useRef<HTMLButtonElement>(null);
    const wasOpenRef = useRef(false);

    const agentIds = (chat.conversation?.messages ?? []).filter((message) => message.author === 'agent').map((message) => message.id);
    const latestAgent = agentIds.length === 0 ? 0 : Math.max(...agentIds);
    const unread = open ? 0 : agentIds.filter((id) => id > seen).length;
    const hasConversation = chat.started;

    // El tema: claro por defecto (casi todos los sitios son claros), oscuro, o el que prefiera el visitante.
    useEffect(() => {
        const root = document.documentElement;
        if (config.mode !== 'sitio') {
            root.dataset.theme = config.mode;

            return;
        }

        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const apply = () => {
            root.dataset.theme = query.matches ? 'oscuro' : 'claro';
        };
        apply();
        query.addEventListener('change', apply);

        return () => {
            query.removeEventListener('change', apply);
        };
    }, [config.mode]);

    useEffect(() => {
        notifyParent(config.parent, { open, unread, corner: config.corner });
    }, [config.parent, config.corner, open, unread]);

    // Solo se atiende a la página que incrusta el widget, y solo a su ventana: cualquier otra cosa se ignora.
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            if (event.source !== window.parent || event.origin !== config.parent) return;

            const layout = layoutOf(event.data);
            if (layout !== null) setFullscreen(layout.fullscreen);
        };
        window.addEventListener('message', onMessage);

        return () => {
            window.removeEventListener('message', onMessage);
        };
    }, [config.parent]);

    // Al cerrar, todo lo que la ventana mostró cuenta como leído.
    const close = useCallback(() => {
        writeStored(seenKey(config.key), String(latestAgent));
        setSeen(latestAgent);
        setOpen(false);
    }, [config.key, latestAgent]);

    // Esc cierra la ventana.
    useEffect(() => {
        if (!open) return;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') close();
        };
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('keydown', onKey);
        };
    }, [open, close]);

    // Al cerrar, el foco vuelve al botón.
    useEffect(() => {
        if (wasOpenRef.current && !open) launcherRef.current?.focus();
        wasOpenRef.current = open;
    }, [open]);

    if (!open) {
        return (
            <Launcher
                copy={config.copy}
                unread={unread}
                corner={config.corner}
                buttonRef={launcherRef}
                onOpen={() => {
                    setOpen(true);
                }}
            />
        );
    }

    return (
        <ChatWindow
            config={config}
            chat={chat}
            began={began || hasConversation}
            fullscreen={fullscreen}
            onBegin={() => {
                setBegan(true);
            }}
            onClose={close}
        />
    );
}
