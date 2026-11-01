import { formatBytes } from '../lib/attachments';
import type { AttachmentData, AttachmentsCopy } from '../ticketTypes';
import { FileIcon } from './icons';

interface AttachmentListProps {
    attachments: AttachmentData[];
    copy: AttachmentsCopy;
}

/**
 * Archivos de un mensaje (design/componentes/burbuja-mensaje.md): una fila con icono, nombre y peso. Solo un archivo
 * que el antivirus declaró limpio es un enlace de descarga; uno por revisar, bloqueado o sin poder revisarse se
 * muestra como texto, con su estado dicho con palabras. Los nombres son siempre texto.
 */
export function AttachmentList({ attachments, copy }: AttachmentListProps) {
    if (attachments.length === 0) return null;

    return (
        <ul aria-label={copy.list_label} aria-live="polite" className="flex flex-col gap-2">
            {attachments.map((attachment) => (
                <li key={attachment.id} className="flex items-center gap-3 rounded-md border border-border bg-surface px-3 py-2">
                    <FileIcon className="size-5 shrink-0 text-text-secondary" />
                    <span className="flex min-w-0 flex-1 flex-col">
                        {attachment.url !== null ? (
                            <a href={attachment.url} download={attachment.name} className="truncate font-medium text-text underline hover:text-primary" aria-label={`${copy.download.replace(':name', attachment.name)} (${formatBytes(attachment.size)})`}>
                                {attachment.name}
                            </a>
                        ) : (
                            <span className="truncate font-medium text-text-secondary">{attachment.name}</span>
                        )}
                        <span className={`text-xs ${attachment.status === 'infected' ? 'text-danger' : 'text-text-tertiary'}`}>{statusText(attachment, copy)}</span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

function statusText(attachment: AttachmentData, copy: AttachmentsCopy): string {
    if (attachment.status === 'clean') return formatBytes(attachment.size);

    return `${formatBytes(attachment.size)} · ${copy.status[attachment.status]}`;
}
