import type { ComposerCopy } from '../ticketTypes';
import { LockIcon } from './icons';

export interface Outgoing {
    kind: 'reply' | 'note';
    body: string;
    files: number;
}

interface OutgoingBubbleProps {
    outgoing: Outgoing;
    copy: Pick<ComposerCopy, 'outgoing_sending' | 'outgoing_files'>;
}

/**
 * Lo que se está enviando, a la vista de inmediato y atenuado hasta que el servidor lo confirma. Tiene la misma forma
 * que la burbuja real (respuesta a la derecha, nota con ancho completo) y se reemplaza por ella cuando llega. Si el
 * envío falla desaparece y el texto sigue en el compositor; el aviso con «Reintentar» está allí.
 */
export function OutgoingBubble({ outgoing, copy }: OutgoingBubbleProps) {
    const files = outgoing.files > 0 ? copy.outgoing_files.replace(':count', String(outgoing.files)) : null;

    if (outgoing.kind === 'note') {
        return (
            <article aria-busy="true" className="flex flex-col gap-1 opacity-70">
                <p className="text-xs text-text-tertiary">{copy.outgoing_sending}</p>
                <div className="rounded-md border border-bubble-nota-interna-borde bg-bubble-nota-interna px-4 py-3">
                    <p className="mb-2 flex items-center gap-2 text-xs font-medium text-text-secondary">
                        <LockIcon className="size-4 text-warning" />
                    </p>
                    <div className="whitespace-pre-wrap break-words text-base text-text">{outgoing.body}</div>
                </div>
            </article>
        );
    }

    return (
        <article aria-busy="true" className="flex flex-col items-end gap-1 opacity-70">
            <p className="text-xs text-text-tertiary">{copy.outgoing_sending}</p>
            {outgoing.body !== '' && <div className="max-w-[85%] whitespace-pre-wrap break-words rounded-lg rounded-tr-[4px] bg-bubble-agente px-4 py-3 text-base text-bubble-agente-texto">{outgoing.body}</div>}
            {files !== null && <p className="text-xs text-text-secondary">{files}</p>}
        </article>
    );
}
