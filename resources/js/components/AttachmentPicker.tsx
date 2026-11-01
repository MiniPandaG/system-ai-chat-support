import { useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { ACCEPT, MAX_BYTES, MAX_FILES, addFiles, formatBytes } from '../lib/attachments';
import type { AttachmentsCopy } from '../ticketTypes';
import { Button } from './Button';
import { FileIcon, PaperclipIcon, XIcon } from './icons';

interface AttachmentPickerProps {
    files: File[];
    copy: AttachmentsCopy;
    /** Mensaje del servidor sobre los archivos (por ejemplo, un tipo que no admite). */
    serverError?: string | undefined;
    /** Porcentaje de la subida en curso; nulo cuando no se está subiendo. */
    progress: number | null;
    disabled?: boolean;
    onChange: (files: File[]) => void;
}

const MEGABYTE = 1024 * 1024;

/**
 * Adjuntar archivos a una respuesta (design/componentes/carga-archivos.md): un botón que abre el selector, la lista
 * de lo elegido con su peso y una «x» para quitar cada uno, y los formatos y el peso máximo siempre a la vista. Lo que
 * el servidor rechazaría se descarta aquí con su motivo, sin subir nada.
 */
export function AttachmentPicker({ files, copy, serverError, progress, disabled = false, onChange }: AttachmentPickerProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [problems, setProblems] = useState<string[]>([]);
    const full = files.length >= MAX_FILES;

    const choose = (event: ChangeEvent<HTMLInputElement>) => {
        const result = addFiles(files, Array.from(event.target.files ?? []), copy);
        setProblems(result.errors);
        onChange(result.files);
        // Vaciar el campo permite volver a elegir el mismo archivo después de quitarlo.
        event.target.value = '';
    };

    const errors = serverError === undefined ? problems : [...problems, serverError];

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <input ref={inputRef} type="file" multiple accept={ACCEPT} hidden tabIndex={-1} onChange={choose} />
                <Button
                    variant="outline"
                    disabled={disabled || full}
                    onClick={() => {
                        inputRef.current?.click();
                    }}
                >
                    <span className="flex items-center gap-2">
                        <PaperclipIcon className="size-4" />
                        {copy.add}
                    </span>
                </Button>
                <p className="text-sm text-text-secondary">{copy.hint.replace(':count', String(MAX_FILES)).replace(':size', String(MAX_BYTES / MEGABYTE))}</p>
            </div>

            {files.length > 0 && (
                <ul aria-label={copy.selected} className="flex flex-col gap-2">
                    {files.map((file) => (
                        <li key={`${file.name}-${String(file.size)}-${String(file.lastModified)}`} className="flex items-center gap-3 rounded-md border border-border bg-surface px-3 py-2">
                            <FileIcon className="size-5 shrink-0 text-text-secondary" />
                            <span className="min-w-0 flex-1 truncate font-medium">{file.name}</span>
                            <span className="shrink-0 text-xs text-text-tertiary">{formatBytes(file.size)}</span>
                            <button
                                type="button"
                                aria-label={copy.remove.replace(':name', file.name)}
                                disabled={disabled}
                                onClick={() => {
                                    setProblems([]);
                                    onChange(files.filter((kept) => kept !== file));
                                }}
                                className="flex size-8 shrink-0 items-center justify-center rounded-sm text-text-secondary hover:bg-surface-hover hover:text-text disabled:cursor-not-allowed disabled:opacity-45"
                            >
                                <XIcon className="size-4" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {progress !== null && (
                <div className="flex flex-col gap-1">
                    <progress max={100} value={progress} aria-label={copy.uploading.replace(':percent', String(progress))} className="h-2 w-full overflow-hidden rounded-full [&::-moz-progress-bar]:bg-primary [&::-webkit-progress-bar]:bg-surface-raised [&::-webkit-progress-value]:bg-primary" />
                    <p role="status" className="text-xs text-text-secondary">
                        {copy.uploading.replace(':percent', String(Math.floor(progress / 25) * 25))}
                    </p>
                </div>
            )}

            {errors.length > 0 && (
                <ul role="alert" className="flex flex-col gap-1 text-sm text-danger">
                    {errors.map((message) => (
                        <li key={message}>{message}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
