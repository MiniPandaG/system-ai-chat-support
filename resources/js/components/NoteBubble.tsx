import type { TicketNoteData, TicketsCopy } from '../ticketTypes';
import { LockIcon } from './icons';

interface NoteBubbleProps {
    note: TicketNoteData;
    copy: TicketsCopy['note'];
}

/**
 * Nota interna (design/componentes/burbuja-mensaje.md): ancho completo, fondo y borde propios y un candado con la
 * leyenda de que el cliente no la ve, para que nadie la confunda con una respuesta. El texto es siempre texto.
 */
export function NoteBubble({ note, copy }: NoteBubbleProps) {
    const name = note.author_name ?? '';

    return (
        <article aria-label={copy.aria.replace(':name', name).replace(':time', note.created_label)} className="flex flex-col gap-1">
            <p className="text-xs text-text-tertiary">
                <span className="font-medium text-text-secondary">{note.author_name}</span> ·{' '}
                <time dateTime={note.created_at}>{note.created_label}</time>
            </p>
            <div className="rounded-md border border-bubble-nota-interna-borde bg-bubble-nota-interna px-4 py-3">
                <p className="mb-2 flex items-center gap-2 text-xs font-medium text-text-secondary">
                    <LockIcon className="size-4 text-warning" />
                    {copy.label}
                </p>
                <div className="whitespace-pre-wrap break-words text-base text-text">{note.body}</div>
            </div>
        </article>
    );
}
