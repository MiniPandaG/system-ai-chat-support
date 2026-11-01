import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { agentMessage, assignees, customerMessage, internalNote, ticketDetail, ticketsCopy, vipTag } from '../test-fixtures/tickets';
import type { TicketDetailData } from '../ticketTypes';
import { TicketDetail } from './TicketDetail';

let errors: Record<string, string> = {};
const { patch } = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { errors } }),
    router: { patch, post: vi.fn(), delete: vi.fn() },
    useForm: () => ({ errors: {}, processing: false, clearErrors: vi.fn(), transform: vi.fn(), post: vi.fn() }),
}));

function renderDetail(overrides: Partial<TicketDetailData> = {}, canUpdate = true) {
    return render(<TicketDetail ticket={{ ...ticketDetail, ...overrides }} assignees={assignees} tagOptions={[]} copy={ticketsCopy} canUpdate={canUpdate} />);
}

describe('TicketDetail', () => {
    beforeEach(() => {
        errors = {};
        patch.mockReset();
    });

    it('muestra el asunto, el cliente con su correo y los datos del ticket', () => {
        renderDetail();

        const header = within(screen.getByRole('heading', { name: 'No puedo entrar a mi cuenta' }).closest('header') ?? document.body);

        expect(header.getByText('Marisol Quintero')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'marisol@cliente-ejemplo.com' }).getAttribute('href')).toBe('mailto:marisol@cliente-ejemplo.com');
        expect(screen.getByText('Ticket #3')).toBeTruthy();
        expect(screen.getByText('Canal: Panel')).toBeTruthy();
    });

    it('la conversación es un registro con los mensajes en orden', () => {
        renderDetail();

        const log = screen.getByRole('log', { name: 'Conversación' });
        const articles = within(log).getAllByRole('article');

        expect(articles).toHaveLength(2);
        expect(articles[0]?.textContent).toContain('Me dice que mi contraseña es incorrecta.');
        expect(articles[1]?.textContent).toContain('Ya lo estamos revisando.');
        expect(log.getAttribute('aria-live')).toBe('polite');
    });

    it('los selectores traen el valor actual y sus opciones con los nombres del producto', () => {
        renderDetail({ status: 'waiting_customer', priority: 'high', assigned_to: 2 });

        expect(screen.getByLabelText<HTMLSelectElement>('Estado').value).toBe('waiting_customer');
        expect(screen.getByLabelText<HTMLSelectElement>('Prioridad').value).toBe('high');
        expect(screen.getByLabelText<HTMLSelectElement>('Agente asignado').value).toBe('2');
        expect(within(screen.getByLabelText('Estado')).getAllByRole('option').map((option) => option.textContent)).toEqual(['Abierto', 'En espera del cliente', 'Resuelto', 'Cerrado']);
        expect(within(screen.getByLabelText('Agente asignado')).getAllByRole('option').map((option) => option.textContent)).toEqual(['Sin asignar', 'Ana Torres', 'Luis Pérez']);
    });

    it('cambiar el estado lo envía al servidor y se ve en el acto', async () => {
        const user = userEvent.setup();
        renderDetail();

        await user.selectOptions(screen.getByLabelText('Estado'), 'resolved');

        expect(patch).toHaveBeenCalledWith('/tickets/11', { status: 'resolved' }, expect.objectContaining({ preserveScroll: true }));
        expect(screen.getByLabelText<HTMLSelectElement>('Estado').value).toBe('resolved');
    });

    it('asignar y dejar sin asignar envía el identificador o nulo', async () => {
        const user = userEvent.setup();
        renderDetail();

        await user.selectOptions(screen.getByLabelText('Agente asignado'), 'Ana Torres');
        expect(patch).toHaveBeenLastCalledWith('/tickets/11', { assigned_to: 1 }, expect.anything());

        await user.selectOptions(screen.getByLabelText('Agente asignado'), 'Sin asignar');
        expect(patch).toHaveBeenLastCalledWith('/tickets/11', { assigned_to: null }, expect.anything());
    });

    it('si el servidor rechaza el cambio, el selector vuelve al valor real', async () => {
        patch.mockImplementation((_url: string, _data: unknown, options: { onError: () => void }) => {
            options.onError();
        });
        const user = userEvent.setup();
        renderDetail({ priority: 'normal' });

        await user.selectOptions(screen.getByLabelText('Prioridad'), 'urgent');

        expect(screen.getByLabelText<HTMLSelectElement>('Prioridad').value).toBe('normal');
    });

    it('muestra el error del servidor en un aviso', () => {
        errors = { assigned_to: 'Esa persona no puede atender tickets.' };
        renderDetail();

        expect(screen.getByRole('alert').textContent).toContain('Esa persona no puede atender tickets.');
    });

    it('quien asignó a alguien ya desactivado lo ve con esa indicación', () => {
        render(
            <TicketDetail
                ticket={{ ...ticketDetail, assigned_to: 7 }}
                assignees={[...assignees, { id: 7, name: 'Marta Gil', active: false }]}
                tagOptions={[]}
                copy={ticketsCopy}
                canUpdate
            />,
        );

        expect(screen.getByRole('option', { name: 'Marta Gil (desactivado)' })).toBeTruthy();
        expect(screen.getByLabelText<HTMLSelectElement>('Agente asignado').value).toBe('7');
    });

    it('en solo lectura los selectores están deshabilitados y el estado se ve como insignia', () => {
        renderDetail({}, false);

        expect(screen.getByLabelText<HTMLSelectElement>('Estado').disabled).toBe(true);
        expect(screen.getByLabelText<HTMLSelectElement>('Prioridad').disabled).toBe(true);
        expect(screen.getByLabelText<HTMLSelectElement>('Agente asignado').disabled).toBe(true);
        expect(document.querySelector('header span.rounded-full')?.textContent).toBe('Abierto');
    });

    it('mezcla mensajes y notas internas en el orden recibido, sin separarlos en pestañas', () => {
        renderDetail({ thread: [customerMessage, internalNote, agentMessage] });

        const articles = within(screen.getByRole('log', { name: 'Conversación' })).getAllByRole('article');

        expect(articles).toHaveLength(3);
        expect(articles[1]?.textContent).toContain('Nota interna — el cliente no la ve');
        expect(articles[1]?.textContent).toContain('Revisar con el equipo de cuentas antes de responder.');
        expect(screen.queryByRole('tablist')).toBeNull();
    });

    it('muestra las etiquetas del ticket', () => {
        renderDetail({ tags: [vipTag] });

        expect(within(screen.getByRole('list', { name: 'Etiquetas' })).getByText('VIP')).toBeTruthy();
    });

    it('quien puede atender ve el compositor; el observador, solo la explicación', () => {
        const { unmount } = renderDetail();
        expect(screen.getByRole('form', { name: 'Escribir en el ticket' })).toBeTruthy();
        unmount();

        renderDetail({}, false);
        expect(screen.queryByRole('form', { name: 'Escribir en el ticket' })).toBeNull();
        expect(screen.getByText(/Tu rol es de observador/)).toBeTruthy();
    });

    it('cambiar el estado o la prioridad no vacía lo que se está escribiendo', async () => {
        const user = userEvent.setup();
        renderDetail();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Un borrador a medias');
        await user.selectOptions(screen.getByLabelText('Prioridad'), 'high');

        expect(screen.getByLabelText<HTMLTextAreaElement>('Mensaje para el cliente').value).toBe('Un borrador a medias');
    });

    it('un cliente sin correo ni nombre no deja huecos raros', () => {
        renderDetail({ customer: { name: null, email: null } });

        expect(screen.getByText('Cliente sin nombre')).toBeTruthy();
        expect(screen.queryByRole('link', { name: /@/ })).toBeNull();
    });
});
