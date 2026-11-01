import { act, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { agentMessage, assignees, customerMessage, ticketDetail, ticketsCopy } from '../test-fixtures/tickets';
import type { AttachmentStatus, TicketDetailData } from '../ticketTypes';
import { TicketDetail } from './TicketDetail';

const { reload } = vi.hoisted(() => ({ reload: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { errors: {} } }),
    router: { patch: vi.fn(), post: vi.fn(), delete: vi.fn(), reload },
    useForm: () => ({ errors: {}, processing: false, progress: null, clearErrors: vi.fn(), transform: vi.fn(), post: vi.fn() }),
}));

function ticketWith(status: AttachmentStatus | null): TicketDetailData {
    const attachments = status === null ? [] : [{ id: 'a1', name: 'informe.pdf', size: 100, status, url: status === 'clean' ? 'https://app.test/a1' : null }];

    return { ...ticketDetail, thread: [customerMessage, { ...agentMessage, attachments }] };
}

function renderDetail(ticket: TicketDetailData) {
    return render(<TicketDetail ticket={ticket} assignees={assignees} tagOptions={[]} copy={ticketsCopy} canUpdate />);
}

describe('TicketDetail: adjuntos por revisar', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        reload.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('con un archivo por revisar consulta cada pocos segundos solo lo que cambió', () => {
        renderDetail(ticketWith('pending'));

        act(() => {
            vi.advanceTimersByTime(3000);
        });
        expect(reload).toHaveBeenCalledTimes(1);
        expect(reload).toHaveBeenCalledWith({ only: ['selected'] });

        act(() => {
            vi.advanceTimersByTime(6000);
        });
        expect(reload).toHaveBeenCalledTimes(3);
    });

    it('se detiene después de unos intentos: no consulta para siempre', () => {
        renderDetail(ticketWith('pending'));

        act(() => {
            vi.advanceTimersByTime(3000 * 60);
        });

        expect(reload).toHaveBeenCalledTimes(20);
    });

    it('sin archivos por revisar no consulta nada', () => {
        for (const status of ['clean', 'infected', 'failed', null] as const) {
            const { unmount } = renderDetail(ticketWith(status));

            act(() => {
                vi.advanceTimersByTime(30000);
            });
            unmount();
        }

        expect(reload).not.toHaveBeenCalled();
    });

    it('deja de consultar al cerrar el ticket', () => {
        const { unmount } = renderDetail(ticketWith('pending'));
        unmount();

        act(() => {
            vi.advanceTimersByTime(30000);
        });

        expect(reload).not.toHaveBeenCalled();
    });

    it('cuando el archivo ya salió limpio, deja de consultar', () => {
        const { rerender } = renderDetail(ticketWith('pending'));
        act(() => {
            vi.advanceTimersByTime(3000);
        });
        expect(reload).toHaveBeenCalledTimes(1);

        rerender(<TicketDetail ticket={ticketWith('clean')} assignees={assignees} tagOptions={[]} copy={ticketsCopy} canUpdate />);
        act(() => {
            vi.advanceTimersByTime(30000);
        });

        expect(reload).toHaveBeenCalledTimes(1);
    });
});
