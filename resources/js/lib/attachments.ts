import type { AttachmentsCopy } from '../ticketTypes';

/** Lo que admite el servidor (config/attachments.php); aquí solo se comprueba antes para no subir en vano. */
export const MAX_FILES = 5;
export const MAX_BYTES = 10 * 1024 * 1024;
export const EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'pdf', 'txt', 'csv', 'docx', 'xlsx'] as const;
export const ACCEPT = EXTENSIONS.map((extension) => `.${extension}`).join(',');

const MEGABYTE = 1024 * 1024;

/** Peso legible: «340 KB», «1,2 MB». */
export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${String(bytes)} B`;
    if (bytes < MEGABYTE) return `${String(Math.round(bytes / 1024))} KB`;

    return `${(bytes / MEGABYTE).toLocaleString('es', { maximumFractionDigits: 1, minimumFractionDigits: 1 })} MB`;
}

function extensionOf(name: string): string {
    const dot = name.lastIndexOf('.');

    return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
}

export interface AddResult {
    files: File[];
    /** Un mensaje por cada archivo que no se añadió, con su motivo. */
    errors: string[];
}

/**
 * Añade archivos a los ya elegidos, descartando con un motivo claro los que el servidor rechazaría: tipo no admitido,
 * vacío, demasiado pesado o más de cinco en total. El servidor vuelve a comprobarlo todo con el contenido en la mano.
 */
export function addFiles(current: File[], incoming: File[], copy: AttachmentsCopy): AddResult {
    const files = [...current];
    const errors: string[] = [];

    for (const file of incoming) {
        if (files.some((kept) => kept.name === file.name && kept.size === file.size && kept.lastModified === file.lastModified)) continue;

        if (!(EXTENSIONS as readonly string[]).includes(extensionOf(file.name))) {
            errors.push(copy.bad_type.replace(':name', file.name));
        } else if (file.size === 0) {
            errors.push(copy.empty.replace(':name', file.name));
        } else if (file.size > MAX_BYTES) {
            errors.push(copy.too_large.replace(':name', file.name).replace(':size', formatMegabytes(file.size)).replace(':max', String(MAX_BYTES / MEGABYTE)));
        } else if (files.length >= MAX_FILES) {
            errors.push(copy.too_many.replace(':count', String(MAX_FILES)));
            break;
        } else {
            files.push(file);
        }
    }

    return { files, errors: [...new Set(errors)] };
}

function formatMegabytes(bytes: number): string {
    return (bytes / MEGABYTE).toLocaleString('es', { maximumFractionDigits: 1, minimumFractionDigits: 1 });
}
