import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ticketsCopy, vipTag } from '../test-fixtures/tickets';
import type { TagData } from '../ticketTypes';
import { TicketTags } from './TicketTags';

interface RequestOptions {
    onSuccess?: () => void;
    onFinish?: () => void;
}

const { post, destroy } = vi.hoisted(() => ({ post: vi.fn(), destroy: vi.fn() }));
let errors: Record<string, string> = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { errors } }),
    router: { post, delete: destroy },
}));

const facturacion: TagData = { id: 6, name: 'Facturación', tone: 'warning' };

function renderTags(tags: TagData[] = [], options: TagData[] = [], canEdit = true) {
    return render(<TicketTags ticketId={11} tags={tags} options={options} canEdit={canEdit} copy={ticketsCopy.tags} />);
}

describe('TicketTags', () => {
    beforeEach(() => {
        post.mockReset();
        destroy.mockReset();
        errors = {};
    });

    it('sin etiquetas lo dice con texto', () => {
        renderTags();

        expect(screen.getByText('Sin etiquetas')).toBeTruthy();
        expect(screen.queryByRole('list')).toBeNull();
    });

    it('muestra las etiquetas con el nombre como texto, nunca como HTML', () => {
        const { container } = renderTags([{ id: 9, name: '<b>x</b>', tone: 'neutral' }]);

        expect(screen.getByText('<b>x</b>')).toBeTruthy();
        expect(container.querySelector('b')).toBeNull();
    });

    it('quitar una etiqueta pide el borrado de esa etiqueta en este ticket', async () => {
        const user = userEvent.setup();
        renderTags([vipTag]);

        await user.click(screen.getByRole('button', { name: 'Quitar etiqueta VIP' }));

        expect(destroy).toHaveBeenCalledWith('/tickets/11/tags/5', expect.objectContaining({ preserveScroll: true }));
    });

    it('quien no puede etiquetar ve las etiquetas sin botones para quitarlas ni añadir', () => {
        renderTags([vipTag], [], false);

        expect(screen.getByText('VIP')).toBeTruthy();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('el botón «+» abre el panel y avisa que está expandido', async () => {
        const user = userEvent.setup();
        renderTags();

        const trigger = screen.getByRole('button', { name: 'Añadir etiqueta' });
        expect(trigger.getAttribute('aria-expanded')).toBe('false');

        await user.click(trigger);

        expect(trigger.getAttribute('aria-expanded')).toBe('true');
        expect(screen.getByText('Etiquetas de tu empresa')).toBeTruthy();
    });

    it('ofrece las etiquetas de la empresa que el ticket aún no tiene', async () => {
        const user = userEvent.setup();
        renderTags([vipTag], [vipTag, facturacion]);

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));
        const panel = within(screen.getByRole('group', { name: 'Añadir etiqueta' }));

        expect(panel.getByRole('button', { name: 'Facturación' })).toBeTruthy();
        expect(panel.queryByRole('button', { name: 'VIP' })).toBeNull();
    });

    it('elegir una existente la manda por su identificador', async () => {
        const user = userEvent.setup();
        renderTags([], [facturacion]);

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));
        await user.click(screen.getByRole('button', { name: 'Facturación' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/tags', { tag_id: 6 }, expect.objectContaining({ preserveScroll: true }));
    });

    it('sin etiquetas disponibles explica qué hacer', async () => {
        const user = userEvent.setup();
        renderTags();

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));

        expect(screen.getByText('Aún no hay etiquetas disponibles. Crea la primera abajo.')).toBeTruthy();
    });

    it('crear una nueva manda el nombre y el tono elegido de la lista cerrada', async () => {
        const user = userEvent.setup();
        renderTags();

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));
        const create = screen.getByRole<HTMLButtonElement>('button', { name: 'Crear y añadir' });
        expect(create.disabled).toBe(true);

        await user.type(screen.getByLabelText('Nombre'), 'Reembolso');
        await user.selectOptions(screen.getByLabelText('Color'), 'Verde');
        await user.click(create);

        expect(post).toHaveBeenCalledWith('/tickets/11/tags', { name: 'Reembolso', tone: 'success' }, expect.anything());
        expect(within(screen.getByLabelText('Color')).getAllByRole('option').map((option) => option.textContent)).toEqual(['Gris', 'Principal', 'Azul', 'Verde', 'Ámbar', 'Rojo']);
    });

    it('el nombre admite como máximo 30 caracteres', async () => {
        const user = userEvent.setup();
        renderTags();

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));

        expect(screen.getByLabelText('Nombre').getAttribute('maxlength')).toBe('30');
    });

    it('al guardarse, el formulario se limpia', async () => {
        const user = userEvent.setup();
        renderTags();

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));
        await user.type(screen.getByLabelText('Nombre'), 'Reembolso');
        await user.click(screen.getByRole('button', { name: 'Crear y añadir' }));
        const options = post.mock.calls[0]?.[2] as RequestOptions;
        options.onSuccess?.();
        options.onFinish?.();

        await screen.findByDisplayValue('');
        expect(screen.getByLabelText<HTMLInputElement>('Nombre').value).toBe('');
    });

    it('muestra el error del servidor asociado al nombre', async () => {
        errors = { name: 'Un ticket admite hasta 10 etiquetas. Quita alguna para añadir otra.' };
        const user = userEvent.setup();
        renderTags();

        await user.click(screen.getByRole('button', { name: 'Añadir etiqueta' }));

        expect(screen.getByLabelText('Nombre').getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText('Un ticket admite hasta 10 etiquetas. Quita alguna para añadir otra.')).toBeTruthy();
    });

    it('Esc cierra el panel y devuelve el foco al botón «+»', async () => {
        const user = userEvent.setup();
        renderTags();

        const trigger = screen.getByRole('button', { name: 'Añadir etiqueta' });
        await user.click(trigger);
        await user.type(screen.getByLabelText('Nombre'), 'a');
        await user.keyboard('{Escape}');

        expect(screen.queryByText('Etiquetas de tu empresa')).toBeNull();
        expect(document.activeElement).toBe(trigger);
    });
});
