import type { Ref } from 'react';
import { ChatIcon } from '../components/icons';
import type { WidgetCopy } from './types';

interface LauncherProps {
    copy: WidgetCopy;
    unread: number;
    corner: 'right' | 'left';
    buttonRef: Ref<HTMLButtonElement>;
    onOpen: () => void;
}

/**
 * El botón flotante (design/pantallas/widget-chat.md): círculo de 56 px con el primario de la empresa y, si hay
 * respuestas sin leer, una insignia con el conteo. El conteo es parte del nombre accesible del botón; la insignia, que
 * es solo visual, pulsa una sola vez al aparecer y no en bucle.
 */
export function Launcher({ copy, unread, corner, buttonRef, onOpen }: LauncherProps) {
    const label = unread > 0 ? `${copy.launcher_open}. ${copy.launcher_unread.replace(':count', String(unread))}` : copy.launcher_open;

    return (
        <div className={`flex size-full items-end p-4 ${corner === 'left' ? 'justify-start' : 'justify-end'}`}>
            <button
                ref={buttonRef}
                type="button"
                aria-label={label}
                onClick={onOpen}
                className="relative flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md transition duration-fast ease-standard hover:scale-105 hover:bg-primary-hover active:scale-95"
            >
                <ChatIcon className="size-6" />
                {unread > 0 && (
                    <span
                        aria-hidden="true"
                        className="absolute -top-1 -right-1 flex h-5 min-w-5 animate-pulse items-center justify-center rounded-full bg-danger px-1 text-xs font-semibold text-danger-foreground [animation-iteration-count:1]"
                    >
                        {unread > 9 ? '9+' : unread}
                    </span>
                )}
            </button>
        </div>
    );
}
