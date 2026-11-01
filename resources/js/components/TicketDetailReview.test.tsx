import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { agentMessage, assignees, customerMessage, ticketDetail, ticketsCopy } from '../test-fixtures/tickets';
import type { AttachmentStatus, TicketDetailData } from '../ticketTypes';
import { TicketDetail } from './TicketDetail';

const { reload, post } = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn() }));
let connection = 'connected';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { errors: {} } }),
    router: { patch: vi.fn(), post: vi.fn(), delete: vi.fn(), reload },
    useForm: () => ({ errors: {}, processing: false, progress: null, clearErrors: vi.fn(), transform: vi.fn(), post }),
}));

vi.mock('../realtime/hooks', () => ({ useConnectionState: () => connection, useChannel: vi.fn() }));

function ticketWith(status: AttachmentStatus | null): TicketDetailData {
    const attachments = status === null ? [] : [{ id: 'a1', name: 'informe.pdf', size: 100, status, url: status === 'clean' ? 'https://app.test/a1' : null }];

    return { ...ticketDetail, thread: [customerMessage, { ...agentMessage, attachments }] };
}

function renderDetail(ticket: TicketDetailData) {
    return render(<TicketDetail ticket={ticket} assignees={assignees} tagOptions={[]} copy={ticketsCopy} canUpdate />);
}

describe('TicketDetail: adjuntos por revisar', () => {
    beforeEach(() => {
        reload.mockReset();
        post.mockReset();
        connection = 'connected';
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('ya no consulta cada pocos segundos: el servidor avisa cuando la revisión termina', () => {
        vi.useFakeTimers();
        renderDetail(ticketWith('pending'));

        act(() => {
            vi.advanceTimersByTime(120000);
        });

        expect(reload).not.toHaveBeenCalled();
    });

    it('con conexión en vivo no muestra ningún aviso de actualización', () => {
        renderDetail(ticketWith('pending'));

        expect(screen.queryByRole('button', { name: 'Actualizar' })).toBeNull();
    });

    it('sin conexión en vivo y con un archivo por revisar ofrece actualizar a mano', () => {
        connection = 'unavailable';
        renderDetail(ticketWith('pending'));

        expect(screen.getByText('Sin conexión en vivo: el resultado de la revisión no aparece solo.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Actualizar' })).toBeTruthy();
    });

    it('«Actualizar» vuelve a pedir solo el ticket y avisa mientras trabaja', async () => {
        connection = 'disconnected';
        const user = userEvent.setup();
        renderDetail(ticketWith('pending'));

        await user.click(screen.getByRole('button', { name: 'Actualizar' }));

        expect(reload).toHaveBeenCalledWith(expect.objectContaining({ only: ['selected'] }));
        const options = reload.mock.calls[0]?.[0] as { onFinish: () => void };
        expect(screen.getByRole<HTMLButtonElement>('button', { name: /Actualizar/ }).disabled).toBe(true);
        expect(screen.getByText('Actualizando…')).toBeTruthy();

        act(() => {
            options.onFinish();
        });
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Actualizar' }).disabled).toBe(false);
        expect(screen.queryByText('Actualizando…')).toBeNull();
    });

    it('sin archivos por revisar no hay aviso aunque no haya conexión', () => {
        connection = 'unavailable';
        for (const status of ['clean', 'infected', 'failed', null] as const) {
            const { unmount } = renderDetail(ticketWith(status));

            expect(screen.queryByRole('button', { name: 'Actualizar' })).toBeNull();
            unmount();
        }
    });
});

describe('TicketDetail: lo que se está enviando', () => {
    beforeEach(() => {
        post.mockReset();
        connection = 'connected';
    });

    it('al enviar una respuesta aparece enseguida en la conversación como «Enviando…» y se va al terminar', async () => {
        const user = userEvent.setup();
        renderDetail(ticketWith(null));

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Estamos en ello');
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));

        const log = screen.getByRole('log');
        const pending = Array.from(log.querySelectorAll('article[aria-busy="true"]'));
        expect(pending).toHaveLength(1);
        expect(pending[0]?.textContent).toContain('Estamos en ello');
        expect(pending[0]?.textContent).toContain('Enviando…');

        const options = post.mock.calls[0]?.[1] as { onFinish: () => void };
        act(() => {
            options.onFinish();
        });
        expect(log.querySelectorAll('article[aria-busy="true"]')).toHaveLength(0);
    });
});
