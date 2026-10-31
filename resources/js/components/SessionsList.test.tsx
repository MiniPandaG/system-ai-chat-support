import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SessionsList } from './SessionsList';
import type { SessionItem, SessionsCopy } from './SessionsList';

const remove = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        delete: (url: string, options?: { onFinish?: () => void }) => {
            remove(url);
            options?.onFinish?.();
        },
    },
}));

const copy: SessionsCopy = {
    title: 'Sesiones abiertas',
    detail: 'Estos son los dispositivos con tu cuenta abierta.',
    current: 'Esta sesión',
    ip: 'IP :ip',
    last_activity: 'Última actividad :when',
    close: 'Cerrar sesión de :device',
    closing: 'Cerrando sesión…',
    close_others: 'Cerrar las demás sesiones',
    closing_others: 'Cerrando sesiones…',
    close_all: 'Cerrar todas las sesiones',
    closing_all: 'Cerrando todas…',
    only_this: 'No hay otras sesiones abiertas.',
    confirm_title: '¿Cerrar todas tus sesiones?',
    confirm_detail: 'Se cierran todas, incluida esta.',
    confirm_cancel: 'Cancelar',
    confirm_accept: 'Cerrar todas',
};

const handleA = 'a'.repeat(64);
const handleB = 'b'.repeat(64);

const current: SessionItem = {
    handle: handleA,
    device: 'Chrome en Windows',
    ip: '203.0.113.9',
    last_activity: '2026-10-30T20:00:00-05:00',
    last_activity_label: 'hace 1 minuto',
    current: true,
};
const phone: SessionItem = {
    handle: handleB,
    device: 'Safari en iOS',
    ip: null,
    last_activity: '2026-10-29T20:00:00-05:00',
    last_activity_label: 'hace 1 día',
    current: false,
};

function itemAt(items: HTMLElement[], index: number): HTMLElement {
    const found = items[index];
    if (found === undefined) throw new Error(`No hay un elemento en la posición ${String(index)}.`);

    return found;
}

describe('SessionsList', () => {
    beforeEach(() => {
        remove.mockClear();
    });

    it('marca la sesión actual y no ofrece cerrarla desde la lista', () => {
        render(<SessionsList copy={copy} sessions={[current, phone]} />);

        const items = screen.getAllByRole('listitem');
        expect(items).toHaveLength(2);
        expect(itemAt(items, 0).getAttribute('aria-current')).toBe('true');
        expect(within(itemAt(items, 0)).getByText('Esta sesión')).toBeTruthy();
        expect(within(itemAt(items, 0)).queryByRole('button')).toBeNull();
        expect(within(itemAt(items, 1)).getByRole('button', { name: 'Cerrar sesión de Safari en iOS' })).toBeTruthy();
    });

    it('muestra la IP solo cuando existe y la actividad como hora legible', () => {
        render(<SessionsList copy={copy} sessions={[current, phone]} />);

        expect(screen.getByText(/IP 203\.0\.113\.9/)).toBeTruthy();
        expect(screen.getByText('Última actividad hace 1 día').tagName).toBe('TIME');
        expect(screen.queryByText(/IP null/)).toBeNull();
    });

    it('cierra una sesión concreta con su código y no con el identificador', async () => {
        const user = userEvent.setup();
        render(<SessionsList copy={copy} sessions={[current, phone]} />);

        await user.click(screen.getByRole('button', { name: 'Cerrar sesión de Safari en iOS' }));

        expect(remove).toHaveBeenCalledWith(`/settings/sessions/${handleB}`);
    });

    it('cierra las demás sin pedir confirmación', async () => {
        const user = userEvent.setup();
        render(<SessionsList copy={copy} sessions={[current, phone]} />);

        await user.click(screen.getByRole('button', { name: 'Cerrar las demás sesiones' }));

        expect(remove).toHaveBeenCalledWith('/settings/sessions');
    });

    it('cerrar todas pide confirmación y solo actúa al confirmar', async () => {
        const user = userEvent.setup();
        render(<SessionsList copy={copy} sessions={[current, phone]} />);

        await user.click(screen.getByRole('button', { name: 'Cerrar todas las sesiones' }));
        expect(remove).not.toHaveBeenCalled();
        expect(document.querySelector('dialog')?.hasAttribute('open')).toBe(true);

        await user.click(within(document.querySelector('dialog') as HTMLElement).getByRole('button', { name: 'Cancelar', hidden: true }));
        expect(remove).not.toHaveBeenCalled();

        await user.click(screen.getByRole('button', { name: 'Cerrar todas las sesiones' }));
        await user.click(within(document.querySelector('dialog') as HTMLElement).getByRole('button', { name: 'Cerrar todas', hidden: true }));
        expect(remove).toHaveBeenCalledWith('/settings/sessions/all');
    });

    it('sin otras sesiones lo dice y no ofrece cerrar las demás', () => {
        render(<SessionsList copy={copy} sessions={[current]} />);

        expect(screen.getByText('No hay otras sesiones abiertas.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Cerrar las demás sesiones' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Cerrar todas las sesiones' })).toBeTruthy();
    });
});
