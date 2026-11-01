import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { assignees, ticketDetail, ticketsCopy } from '../test-fixtures/tickets';
import type { TicketDetailData } from '../ticketTypes';
import { TicketDetail } from './TicketDetail';

const { useTypists } = vi.hoisted(() => ({ useTypists: vi.fn() }));
let typists: number[] = [];

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { errors: {}, auth: { user: { id: 1, name: 'Ana', email: 'ana@empresa-ejemplo.com', role: 'agent' }, tenant: { id: 7 } } } }),
    router: { patch: vi.fn(), post: vi.fn(), delete: vi.fn(), reload: vi.fn() },
    useForm: () => ({ errors: {}, processing: false, progress: null, clearErrors: vi.fn(), transform: vi.fn(), post: vi.fn() }),
}));

vi.mock('../realtime/hooks', () => ({ useConnectionState: () => 'connected', useChannel: vi.fn() }));
vi.mock('../realtime/useTypists', () => ({
    useTypists: (...args: unknown[]) => {
        useTypists(...args);

        return typists;
    },
}));

function renderDetail(overrides: Partial<TicketDetailData> = {}, canUpdate = true) {
    return render(<TicketDetail ticket={{ ...ticketDetail, ...overrides }} assignees={assignees} tagOptions={[]} copy={ticketsCopy} canUpdate={canUpdate} />);
}

describe('TicketDetail: visto por', () => {
    it('muestra a los compañeros que ya vieron lo último', () => {
        renderDetail({ seen_by: ['Luis Pérez', 'Pedro Gil'], seen_more: 0 });

        expect(screen.getByText('Visto por Luis Pérez y Pedro Gil')).toBeTruthy();
    });

    it('cuenta los que no caben', () => {
        renderDetail({ seen_by: ['Luis Pérez'], seen_more: 4 });

        expect(screen.getByText('Visto por Luis Pérez y 4 más')).toBeTruthy();
    });

    it('si nadie lo vio no muestra nada', () => {
        renderDetail({ seen_by: [], seen_more: 0 });

        expect(screen.queryByText(/Visto por/)).toBeNull();
    });
});

describe('TicketDetail: escribiendo', () => {
    beforeEach(() => {
        typists = [];
        useTypists.mockReset();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('escucha a su empresa y a su ticket, sin contarse a sí misma', () => {
        renderDetail();

        expect(useTypists).toHaveBeenCalledWith(7, ticketDetail.id, 1);
    });

    it('sin nadie escribiendo la región está vacía', () => {
        renderDetail();

        const region = screen.getAllByRole('status').find((element) => element.className.includes('min-h-5'));
        expect(region?.textContent).toBe('');
    });

    it('dice quién escribe con el nombre del equipo', () => {
        const first = assignees[0];
        typists = first === undefined ? [] : [first.id];

        renderDetail();

        expect(screen.getByText(`${first?.name ?? ''} está escribiendo…`)).toBeTruthy();
    });

    it('una persona que no está en la lista del equipo aparece sin nombre, sin inventar uno', () => {
        typists = [987654];

        renderDetail();

        expect(screen.getByText('Alguien está escribiendo…')).toBeTruthy();
    });

    it('al escribir avisa al servidor una sola vez aunque se pulsen muchas teclas', async () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true });
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderDetail();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Estamos revisando tu caso');

        const calls = fetchMock.mock.calls.filter((call) => String(call[0]).endsWith('/typing'));
        expect(calls).toHaveLength(1);
        expect(calls[0]?.[0]).toBe(`/tickets/${String(ticketDetail.id)}/typing`);
    });

    it('un observador no puede escribir y nunca avisa', () => {
        const fetchMock = vi.fn().mockResolvedValue({ ok: true });
        vi.stubGlobal('fetch', fetchMock);
        renderDetail({}, false);

        expect(screen.queryByLabelText('Mensaje para el cliente')).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
