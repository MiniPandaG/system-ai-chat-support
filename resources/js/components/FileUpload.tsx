import { useId, useState } from 'react';
import type { DragEvent } from 'react';
import { Alert } from './Alert';
import { Button } from './Button';
import { UploadIcon } from './icons';

export interface FileUploadCopy {
    drop: string;
    select: string;
    help: string;
    uploading: string;
    progress: string;
    cancel: string;
    too_large: string;
    wrong_type: string;
}

interface FileUploadProps {
    copy: FileUploadCopy;
    /** Tipos MIME admitidos; la comprobación definitiva es la del servidor. */
    accept: string[];
    maxBytes: number;
    uploading: { name: string; percent: number } | null;
    /** Error que devolvió el servidor. */
    error?: string | undefined;
    onSelect: (file: File) => void;
    onCancel: () => void;
}

const megabytes = (bytes: number): string => (bytes / 1048576).toFixed(1).replace('.', ',');

/**
 * Carga de un archivo (design/componentes/carga-archivos.md). El input real cubre toda la zona y se enfoca con el
 * teclado; arrastrar y soltar es un atajo, nunca el único camino. Los límites se muestran antes de intentar subir.
 */
export function FileUpload({ copy, accept, maxBytes, uploading, error, onSelect, onCancel }: FileUploadProps) {
    const id = useId();
    const [dragging, setDragging] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    const choose = (file: File | undefined) => {
        if (file === undefined) return;

        if (!accept.includes(file.type)) {
            setLocalError(copy.wrong_type);

            return;
        }

        if (file.size > maxBytes) {
            setLocalError(copy.too_large.replace(':size', megabytes(file.size)).replace(':max', megabytes(maxBytes)));

            return;
        }

        setLocalError(null);
        onSelect(file);
    };

    const onDrop = (event: DragEvent<HTMLLabelElement>) => {
        event.preventDefault();
        setDragging(false);
        choose(event.dataTransfer.files[0]);
    };

    const message = localError ?? error;
    // El avance se anuncia cada 25 %, no en cada punto, para no saturar al lector de pantalla.
    const announced = uploading === null ? 0 : Math.floor(uploading.percent / 25) * 25;

    return (
        <div className="flex flex-col gap-3">
            {uploading === null ? (
                <label
                    htmlFor={id}
                    onDragOver={(event) => {
                        event.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => {
                        setDragging(false);
                    }}
                    onDrop={onDrop}
                    className={`flex min-h-40 cursor-pointer flex-col items-center justify-center gap-2 rounded-md p-6 text-center transition duration-fast ease-standard focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-focus-ring ${
                        dragging
                            ? 'border-[1.5px] border-solid border-primary bg-primary-surface'
                            : message === undefined
                              ? 'border-[1.5px] border-dashed border-border-strong bg-surface-sunken hover:bg-surface-hover'
                              : 'border-[1.5px] border-dashed border-danger-border bg-surface-sunken'
                    }`}
                >
                    <UploadIcon className="size-6 text-text-secondary" />
                    <span className="text-base">
                        {copy.drop} <span className="font-semibold underline">{copy.select}</span>
                    </span>
                    <input
                        id={id}
                        type="file"
                        accept={accept.join(',')}
                        className="sr-only"
                        onChange={(event) => {
                            choose(event.target.files?.[0]);
                            event.target.value = '';
                        }}
                    />
                </label>
            ) : (
                <div className="flex min-h-40 flex-col justify-center gap-3 rounded-md border-[1.5px] border-border-strong bg-surface-sunken p-6">
                    <p className="truncate font-medium">{copy.uploading.replace(':name', uploading.name)}</p>
                    <div
                        role="progressbar"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={uploading.percent}
                        aria-label={copy.progress.replace(':percent', String(uploading.percent))}
                        className="h-2 overflow-hidden rounded-full bg-border"
                    >
                        <div className="h-full bg-primary transition-[width] duration-fast ease-standard" style={{ width: `${String(uploading.percent)}%` }} />
                    </div>
                    <p className="text-sm text-text-secondary">{copy.progress.replace(':percent', String(uploading.percent))}</p>
                    <p role="status" className="sr-only">
                        {copy.progress.replace(':percent', String(announced))}
                    </p>
                    <Button variant="text" className="self-start" onClick={onCancel}>
                        {copy.cancel}
                    </Button>
                </div>
            )}

            <p className="text-sm text-text-secondary">{copy.help}</p>
            {message !== undefined && <Alert variant="danger">{message}</Alert>}
        </div>
    );
}
