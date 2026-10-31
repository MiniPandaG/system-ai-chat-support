import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmDialog } from './ConfirmDialog';

function renderDialog(overrides: Partial<Parameters<typeof ConfirmDialog>[0]> = {}) {
    const onConfirm = vi.fn();
    const onCancel = vi.fn();

    render(
        <ConfirmDialog
            open
            title="¿Cerrar todas tus sesiones?"
            detail="Se cierran todas, incluida esta."
            cancelLabel="Cancelar"
            confirmLabel="Cerrar todas"
            confirmingLabel="Cerrando todas…"
            onConfirm={onConfirm}
            onCancel={onCancel}
            {...overrides}
        />,
    );

    const dialog = document.querySelector('dialog');
    if (dialog === null) throw new Error('El diálogo no se montó.');

    return { onConfirm, onCancel, dialog };
}

describe('ConfirmDialog', () => {
    it('es una confirmación con título y descripción asociados', () => {
        const { dialog } = renderDialog();

        expect(dialog.getAttribute('role')).toBe('alertdialog');
        expect(dialog.getAttribute('aria-labelledby')).toBe(screen.getByRole('heading', { name: '¿Cerrar todas tus sesiones?', hidden: true }).id);
        expect(dialog.getAttribute('aria-describedby')).toBe(screen.getByText('Se cierran todas, incluida esta.').id);
    });

    it('solo se abre cuando se pide y se cierra al dejar de pedirlo', () => {
        const { rerender } = render(
            <ConfirmDialog open={false} title="t" detail="d" cancelLabel="No" confirmLabel="Sí" confirmingLabel="…" onConfirm={vi.fn()} onCancel={vi.fn()} />,
        );
        const dialog = document.querySelector('dialog');
        expect(dialog?.hasAttribute('open')).toBe(false);

        rerender(<ConfirmDialog open title="t" detail="d" cancelLabel="No" confirmLabel="Sí" confirmingLabel="…" onConfirm={vi.fn()} onCancel={vi.fn()} />);
        expect(dialog?.hasAttribute('open')).toBe(true);

        rerender(
            <ConfirmDialog open={false} title="t" detail="d" cancelLabel="No" confirmLabel="Sí" confirmingLabel="…" onConfirm={vi.fn()} onCancel={vi.fn()} />,
        );
        expect(dialog?.hasAttribute('open')).toBe(false);
    });

    it('confirma y cancela con sus botones', async () => {
        const user = userEvent.setup();
        const { onConfirm, onCancel } = renderDialog();

        await user.click(screen.getByRole('button', { name: 'Cancelar' }));
        await user.click(screen.getByRole('button', { name: 'Cerrar todas' }));

        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('Esc cancela, salvo mientras la acción está en curso', () => {
        const { dialog, onCancel } = renderDialog();
        fireEvent(dialog, new Event('cancel', { cancelable: true }));
        expect(onCancel).toHaveBeenCalledTimes(1);

        const busy = renderDialog({ loading: true });
        fireEvent(busy.dialog, new Event('cancel', { cancelable: true }));
        expect(busy.onCancel).not.toHaveBeenCalled();
    });

    it('al cargar bloquea los dos botones', () => {
        renderDialog({ loading: true });

        expect(screen.getByRole('button', { name: 'Cancelar' }).hasAttribute('disabled')).toBe(true);
        expect(screen.getAllByRole('button').every((button) => button.hasAttribute('disabled'))).toBe(true);
    });
});
