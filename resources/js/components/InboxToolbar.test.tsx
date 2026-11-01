import { act, fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { assignees, ticketsCopy } from '../test-fixtures/tickets';
import type { InboxFilters, SavedView, TagData } from '../ticketTypes';
import { InboxToolbar } from './InboxToolbar';

let errors: Record<string, string> = {};
const { post, destroy } = vi.hoisted(() => ({ post: vi.fn(), destroy: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({ props: { errors } }),
    router: { post, delete: destroy },
}));

const tags: TagData[] = [{ id: 4, name: 'Facturación', tone: 'warning' }];
const counts = { all: 10, mine: 2, unassigned: 3 };
const savedView: SavedView = { id: 9, name: 'Urgentes', shared: false, count: 2, deletable: true };

function renderToolbar(filters: InboxFilters = {}, extra: { views?: SavedView[]; canSaveView?: boolean } = {}) {
    const onVisit = vi.fn();
    const utils = render(
        <InboxToolbar
            copy={ticketsCopy}
            filters={filters}
            counts={counts}
            views={extra.views ?? []}
            assignees={assignees}
            tags={tags}
            canSaveView={extra.canSaveView ?? true}
            basePath="/tickets"
            onVisit={onVisit}
        />,
    );

    return { ...utils, onVisit };
}

describe('InboxToolbar', () => {
    beforeEach(() => {
        errors = {};
        post.mockReset();
        destroy.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('búsqueda', () => {
        it('el campo tiene nombre accesible y una ayuda que explica dónde busca', () => {
            renderToolbar();

            const field = screen.getByRole('searchbox', { name: 'Buscar en la bandeja' });

            expect(field.getAttribute('aria-describedby')).not.toBeNull();
            expect(screen.getByText('Busca en los mensajes, el asunto, el cliente y el número de ticket.')).toBeTruthy();
        });

        it('espera a que se deje de escribir y busca una sola vez', () => {
            vi.useFakeTimers();
            const { onVisit } = renderToolbar();
            const field = screen.getByRole('searchbox');

            act(() => {
                for (const value of ['f', 'fa', 'fac', 'factura']) {
                    // Cada pulsación reinicia la espera.
                    fireEvent.change(field, { target: { value: value } });
                    vi.advanceTimersByTime(100);
                }
            });
            expect(onVisit).not.toHaveBeenCalled();

            act(() => {
                vi.advanceTimersByTime(400);
            });

            expect(onVisit).toHaveBeenCalledTimes(1);
            expect(onVisit).toHaveBeenCalledWith({ q: 'factura' });
        });

        it('un solo carácter no busca nada', () => {
            vi.useFakeTimers();
            const { onVisit } = renderToolbar();
            const field = screen.getByRole('searchbox');

            act(() => {
                fireEvent.change(field, { target: { value: 'a' } });
                vi.advanceTimersByTime(1000);
            });

            expect(onVisit).not.toHaveBeenCalled();
        });

        it('vaciar el campo quita la búsqueda', () => {
            vi.useFakeTimers();
            const { onVisit } = renderToolbar({ q: 'factura' });
            const field = screen.getByRole<HTMLInputElement>('searchbox');
            expect(field.value).toBe('factura');

            act(() => {
                fireEvent.change(field, { target: { value: '' } });
                vi.advanceTimersByTime(500);
            });

            expect(onVisit).toHaveBeenCalledWith({});
        });

        it('conserva una vista guardada al buscar dentro de ella', () => {
            vi.useFakeTimers();
            const { onVisit } = renderToolbar({ saved: 9 }, { views: [savedView] });

            act(() => {
                const field = screen.getByRole('searchbox');
                fireEvent.change(field, { target: { value: 'reembolso' } });
                vi.advanceTimersByTime(500);
            });

            expect(onVisit).toHaveBeenCalledWith({ saved: 9, q: 'reembolso' });
        });
    });

    describe('orden', () => {
        it('ofrece los tres criterios y cambia el orden sin tocar los filtros', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar({ status: ['open'] });
            const select = screen.getByLabelText<HTMLSelectElement>('Ordenar por');

            expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual(['Más reciente', 'Más antiguo', 'Prioridad']);

            await user.selectOptions(select, 'priority');

            expect(onVisit).toHaveBeenCalledWith({ status: ['open'], sort: 'priority' });
        });

        it('volver a «Más reciente» quita el parámetro de orden', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar({ sort: 'oldest' });

            await user.selectOptions(screen.getByLabelText('Ordenar por'), 'recent');

            expect(onVisit).toHaveBeenCalledWith({ sort: undefined });
        });
    });

    describe('panel de filtros', () => {
        it('empieza cerrado y el botón avisa si está expandido', async () => {
            const user = userEvent.setup();
            renderToolbar();

            const button = screen.getByRole('button', { name: 'Filtros' });
            expect(button.getAttribute('aria-expanded')).toBe('false');
            expect(screen.queryByRole('region', { name: 'Filtros avanzados' })).toBeNull();

            await user.click(button);

            expect(button.getAttribute('aria-expanded')).toBe('true');
            expect(screen.getByRole('region', { name: 'Filtros avanzados' })).toBeTruthy();
        });

        it('al abrirlo el foco pasa a su primer control y al cerrarlo vuelve al botón', async () => {
            const user = userEvent.setup();
            renderToolbar();
            const button = screen.getByRole('button', { name: 'Filtros' });

            await user.click(button);
            expect(document.activeElement).toBe(within(screen.getByRole('region', { name: 'Filtros avanzados' })).getAllByRole('checkbox')[0]);

            await user.click(button);
            expect(document.activeElement).toBe(button);
        });

        it('marcar un estado cambia los filtros y suelta la vista guardada', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar({ saved: 9, priority: ['high'] }, { views: [savedView] });

            await user.click(screen.getByRole('button', { name: /Filtros/ }));
            await user.click(screen.getByRole('checkbox', { name: 'Abierto' }));

            expect(onVisit).toHaveBeenCalledWith({ saved: null, priority: ['high'], status: ['open'] });
        });

        it('desmarcar quita solo ese valor', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar({ status: ['open', 'closed'] });

            await user.click(screen.getByRole('button', { name: /Filtros/ }));
            await user.click(screen.getByRole('checkbox', { name: 'Abierto' }));

            expect(onVisit).toHaveBeenCalledWith({ status: ['closed'], saved: null });
        });

        it('elegir agente, etiqueta y fechas los aplica en combinación', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar({ status: ['open'] });

            await user.click(screen.getByRole('button', { name: /Filtros/ }));
            await user.selectOptions(screen.getByLabelText('Agente'), 'Ana Torres');
            expect(onVisit).toHaveBeenLastCalledWith({ status: ['open'], agent: 1, saved: null });

            await user.selectOptions(screen.getByLabelText('Etiqueta'), 'Facturación');
            expect(onVisit).toHaveBeenLastCalledWith({ status: ['open'], tag: 4, saved: null });
        });

        it('las fechas se escriben como fecha y se pasan tal cual', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar();

            await user.click(screen.getByRole('button', { name: /Filtros/ }));
            await user.type(screen.getByLabelText('Creado desde'), '2026-10-01');

            expect(onVisit).toHaveBeenLastCalledWith({ from: '2026-10-01', saved: null });
        });

        it('el botón muestra cuántos filtros hay activos', () => {
            renderToolbar({ scope: 'mine', status: ['open', 'closed'], q: 'algo' });

            expect(screen.getByRole('button', { name: 'Filtros (3)' })).toBeTruthy();
        });

        it('«Quitar filtros» está deshabilitado sin filtros y los quita todos, también la búsqueda', async () => {
            const user = userEvent.setup();
            const { onVisit, unmount } = renderToolbar();
            await user.click(screen.getByRole('button', { name: 'Filtros' }));
            expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Quitar filtros' }).disabled).toBe(true);
            unmount();

            const second = renderToolbar({ status: ['open'], q: 'hola' });
            await user.click(screen.getByRole('button', { name: /Filtros/ }));
            await user.click(screen.getByRole('button', { name: 'Quitar filtros' }));

            expect(second.onVisit).toHaveBeenCalledWith({});
            expect(onVisit).not.toHaveBeenCalled();
            expect(screen.getByRole<HTMLInputElement>('searchbox').value).toBe('');
        });
    });

    describe('filtros activos', () => {
        it('se muestran como insignias que se quitan con una «x»', async () => {
            const user = userEvent.setup();
            const { onVisit } = renderToolbar({ status: ['open', 'closed'], tag: 4, agent: 2 });

            const list = screen.getByRole('list', { name: 'Filtros' });
            expect(within(list).getAllByRole('listitem').map((item) => item.textContent)).toEqual(['Abierto', 'Cerrado', 'Luis Pérez', 'Facturación']);

            await user.click(screen.getByRole('button', { name: 'Quitar el filtro Cerrado' }));

            expect(onVisit).toHaveBeenCalledWith({ status: ['open'], tag: 4, agent: 2, saved: null });
        });

        it('sin filtros no hay lista de insignias', () => {
            renderToolbar();

            expect(screen.queryByRole('list', { name: 'Filtros' })).toBeNull();
        });
    });

    describe('guardar como vista', () => {
        async function openSaveForm(filters: InboxFilters = { status: ['open'] }) {
            const user = userEvent.setup();
            const utils = renderToolbar(filters);
            await user.click(screen.getByRole('button', { name: /Filtros/ }));
            await user.click(screen.getByRole('button', { name: 'Guardar como vista' }));

            return { user, ...utils };
        }

        it('solo se puede guardar con al menos un filtro; una búsqueda sola no cuenta', async () => {
            const user = userEvent.setup();
            renderToolbar({ q: 'hola' });
            await user.click(screen.getByRole('button', { name: /Filtros/ }));

            expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar como vista' }).disabled).toBe(true);
        });

        it('quien no puede guardar vistas no ve el botón', async () => {
            const user = userEvent.setup();
            renderToolbar({ status: ['open'] }, { canSaveView: false });
            await user.click(screen.getByRole('button', { name: /Filtros/ }));

            expect(screen.queryByRole('button', { name: 'Guardar como vista' })).toBeNull();
        });

        it('pide un nombre y si se comparte; sin nombre no se envía', async () => {
            await openSaveForm();

            expect(screen.getByLabelText('Nombre de la vista').getAttribute('maxlength')).toBe('60');
            expect(screen.getByRole('checkbox', { name: 'Compartir con el equipo' })).toBeTruthy();
            expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar vista' }).disabled).toBe(true);
        });

        it('envía los filtros actuales con el nombre y si es compartida, sin la vista activa', async () => {
            const { user } = await openSaveForm({ status: ['open'], priority: ['urgent'], sort: 'priority' });

            await user.type(screen.getByLabelText('Nombre de la vista'), 'Urgentes abiertos');
            await user.click(screen.getByRole('checkbox', { name: 'Compartir con el equipo' }));
            await user.click(screen.getByRole('button', { name: 'Guardar vista' }));

            expect(post).toHaveBeenCalledWith(
                '/tickets/views',
                { status: ['open'], priority: ['urgent'], sort: 'priority', name: 'Urgentes abiertos', shared: true },
                expect.objectContaining({ preserveScroll: true }),
            );
        });

        it('muestra el error del servidor asociado al nombre', async () => {
            errors = { name: 'Ya tienes 10 vistas privadas, el máximo. Elimina alguna para crear otra.' };
            await openSaveForm();

            expect(screen.getByLabelText('Nombre de la vista').getAttribute('aria-invalid')).toBe('true');
            expect(screen.getByText(/Ya tienes 10 vistas privadas/)).toBeTruthy();
        });

        it('cancelar cierra el formulario sin enviar nada', async () => {
            const { user } = await openSaveForm();

            await user.click(screen.getByRole('button', { name: 'Cancelar' }));

            expect(screen.queryByLabelText('Nombre de la vista')).toBeNull();
            expect(post).not.toHaveBeenCalled();
        });
    });

    describe('vista guardada activa', () => {
        it('ofrece eliminarla y pide confirmación antes de hacerlo', async () => {
            const user = userEvent.setup();
            renderToolbar({ saved: 9 }, { views: [savedView] });

            await user.click(screen.getByRole('button', { name: 'Eliminar esta vista' }));
            expect(destroy).not.toHaveBeenCalled();

            await user.click(screen.getByRole('button', { name: 'Eliminar vista' }));

            expect(destroy).toHaveBeenCalledWith('/tickets/views/9', expect.anything());
        });

        it('si no se puede eliminar, no ofrece el botón', () => {
            renderToolbar({ saved: 9 }, { views: [{ ...savedView, deletable: false }] });

            expect(screen.queryByRole('button', { name: 'Eliminar esta vista' })).toBeNull();
        });

        it('sin vista activa tampoco hay botón de eliminar', () => {
            renderToolbar({}, { views: [savedView] });

            expect(screen.queryByRole('button', { name: 'Eliminar esta vista' })).toBeNull();
        });
    });
});
