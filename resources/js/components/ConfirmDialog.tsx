import { useEffect, useId, useRef } from 'react';
import { Button } from './Button';

type ConfirmVariant = 'primary' | 'danger';

interface ConfirmDialogProps {
    open: boolean;
    title: string;
    detail: string;
    cancelLabel: string;
    confirmLabel: string;
    confirmingLabel: string;
    loading?: boolean;
    /** Danger para acciones destructivas; primary para una confirmación que otorga permisos. */
    confirmVariant?: ConfirmVariant;
    onConfirm: () => void;
    onCancel: () => void;
}

/**
 * Confirmación de una acción destructiva (design/componentes/modal.md). Usa el elemento dialog del navegador, que
 * ya atrapa el foco, lo devuelve a quien lo abrió y cierra con Esc. Un clic fuera nunca lo cierra.
 */
export function ConfirmDialog({ open, title, detail, cancelLabel, confirmLabel, confirmingLabel, loading = false, confirmVariant = 'danger', onConfirm, onCancel }: ConfirmDialogProps) {
    const ref = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const detailId = useId();

    useEffect(() => {
        const dialog = ref.current;
        if (dialog === null) return;

        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    return (
        <dialog
            ref={ref}
            role="alertdialog"
            aria-labelledby={titleId}
            aria-describedby={detailId}
            className="m-auto w-[calc(100%-2rem)] max-w-[30rem] rounded-lg bg-surface-raised p-6 text-text shadow-lg backdrop:bg-[var(--color-overlay-backdrop)]"
            onCancel={(event) => {
                // Esc no cierra mientras la acción está en curso.
                event.preventDefault();
                if (!loading) onCancel();
            }}
        >
            <div className="flex flex-col gap-4">
                <h2 id={titleId} className="font-display text-lg font-semibold">
                    {title}
                </h2>
                <p id={detailId} className="text-text-secondary">
                    {detail}
                </p>
                <div className="flex justify-end gap-3">
                    <Button variant="text" disabled={loading} onClick={onCancel}>
                        {cancelLabel}
                    </Button>
                    <Button variant={confirmVariant} loading={loading} loadingLabel={confirmingLabel} onClick={onConfirm}>
                        {confirmLabel}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}
