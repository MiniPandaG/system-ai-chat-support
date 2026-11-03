import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { assignees, ticketDetail, ticketRows, ticketsCopy } from '../../test-fixtures/tickets';
import type { SharedProps } from '../../types';
import Index from './Index';

let shared: Partial<SharedProps> = {};
const { get } = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    useForm: () => ({ post: vi.fn(), processing: false, data: { subject: '', description: '', priority: 'normal', assigned_to: '', customer_name: '', customer_email: '' }, errors: {}, setData: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(), transform: vi.fn() }),
    usePage: () => ({ props: shared, url: '/tickets' }),
    router: { patch: vi.fn(), get, post: vi.fn(), delete: vi.fn() },
}));

const panel = {
    nav_label: 'Navegación principal',
    inbox: 'Bandeja',
    inbox_unassigned: ':count sin asignar',
    live_on: 'En vivo',
    live_connecting: 'Conectando…',
    live_off: 'Sin conexión en vivo',
    account: 'Mi cuenta',
    security: 'Seguridad',
    team: 'Equipo',
    branding: 'Marca',
    widget: 'Chat web',
    api_keys: 'Llaves de API',
    knowledge: 'Base de conocimiento',
    plan: 'Plan',
    platform: 'Plataforma',
    open_menu: 'Abrir menú',
    close_menu: 'Cerrar menú',
    logout: 'Cerrar sesión',
    logging_out: 'Cerrando sesión…',
    roles: { admin: 'Administrador', agent: 'Agente' },
};

function setShared(flashStatus: string | null = null) {
    shared = {
        errors: {},
        flash: { status: flashStatus, recovery_codes: null },
        panel,
        auth: {
            user: { id: 1, name: 'Ana Torres', email: 'ana@empresa-ejemplo.com', role: 'agent' },
            tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null },
            can: { tickets: true, team: false, branding: false, plan: false, widget: false, api_keys: false, knowledge: false },
        },
    };
}

function renderIndex(overrides: Partial<Parameters<typeof Index>[0]> = {}) {
    return render(
        <Index
            copy={ticketsCopy}
            tickets={ticketRows}
            next_cursor={null}
            total={ticketRows.length}
            filters={{}}
            counts={{ all: 2, mine: 1, unassigned: 1 }}
            views={[]}
            filter_tags={[]}
            selected={null}
            assignees={assignees}
            tag_options={[]}
            can={{ create: true, update: true, save_view: true }}
            {...overrides}
        />,
    );
}

describe('Bandeja', () => {
    beforeEach(() => {
        setShared();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('lista los tickets y cuenta cuántos hay', () => {
        renderIndex();

        expect(within(screen.getByRole('list', { name: 'Tickets' })).getAllByRole('listitem')).toHaveLength(2);
        expect(screen.getByText('2 tickets', { selector: 'span.font-medium' })).toBeTruthy();
    });

    it('sin ticket abierto invita a elegir uno', () => {
        renderIndex();

        expect(screen.getByText('Selecciona un ticket para ver la conversación.')).toBeTruthy();
        expect(screen.queryByRole('log')).toBeNull();
    });

    it('con un ticket abierto muestra su conversación y la fila marcada', () => {
        renderIndex({ selected: ticketDetail });

        expect(screen.getByRole('log', { name: 'Conversación' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'No puedo entrar a mi cuenta' })).toBeTruthy();
        expect(screen.getAllByRole('link').filter((link) => link.getAttribute('aria-current') === 'true')).toHaveLength(1);
    });

    it('desde pantallas angostas el ticket abierto ofrece volver a la bandeja', () => {
        renderIndex({ selected: ticketDetail });

        const back = screen.getByRole('link', { name: 'Volver a la bandeja' });

        expect(back.getAttribute('href')).toBe('/tickets');
        expect(back.closest('div')?.className).toContain('lg:hidden');
    });

    it('«Nuevo ticket» abre el formulario', async () => {
        const user = userEvent.setup();
        renderIndex();

        expect(document.querySelector('dialog[aria-labelledby="new-ticket-title"]')?.hasAttribute('open')).toBe(false);

        await user.click(screen.getByRole('button', { name: /Nuevo ticket/ }));

        expect(document.querySelector('dialog[aria-labelledby="new-ticket-title"]')?.hasAttribute('open')).toBe(true);
    });

    it('un observador no ve el botón ni el formulario', () => {
        renderIndex({ can: { create: false, update: false, save_view: false } });

        expect(screen.queryByRole('button', { name: /Nuevo ticket/ })).toBeNull();
        expect(document.querySelector('dialog[aria-labelledby="new-ticket-title"]')).toBeNull();
    });

    it('una bandeja vacía dice qué significa y qué hacer', () => {
        renderIndex({ tickets: [], total: 0 });

        expect(screen.getByRole('heading', { name: 'Aún no ha llegado ningún ticket.' })).toBeTruthy();
        expect(screen.getByText('Crea el primero desde aquí.')).toBeTruthy();
        expect(screen.queryByRole('list', { name: 'Tickets' })).toBeNull();
        expect(within(screen.getByRole('region', { name: 'Tickets' })).getAllByRole('button', { name: 'Nuevo ticket' })).toHaveLength(2);
    });

    it('una bandeja vacía para un observador no ofrece crear nada', () => {
        renderIndex({ tickets: [], total: 0, can: { create: false, update: false, save_view: false } });

        expect(screen.getByText('Cuando un agente cree un ticket, aparecerá aquí.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Nuevo ticket' })).toBeNull();
    });

    it('el aviso de éxito se muestra y se va solo a los 4 segundos', () => {
        vi.useFakeTimers();
        setShared('Ticket #4 creado.');
        renderIndex();

        expect(screen.getByText('Ticket #4 creado.')).toBeTruthy();

        act(() => {
            vi.advanceTimersByTime(4000);
        });

        expect(screen.queryByText('Ticket #4 creado.')).toBeNull();
    });

    it('sin mensaje del servidor no hay aviso', () => {
        renderIndex();

        expect(screen.queryByText('Ticket #4 creado.')).toBeNull();
    });

    it('con filtros que no encuentran nada dice por qué y ofrece quitarlos', async () => {
        const user = userEvent.setup();
        renderIndex({ tickets: [], total: 0, filters: { status: ['closed'] } });

        expect(screen.getByRole('heading', { name: 'Ningún ticket coincide con estos filtros.' })).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Quitar filtros' }));

        expect(get).toHaveBeenCalledWith('/tickets', {}, expect.objectContaining({ preserveState: true, replace: true }));
    });

    it('un ticket abierto conserva los filtros en el enlace de volver y en las filas', () => {
        renderIndex({ selected: ticketDetail, filters: { status: ['open'] } });

        expect(screen.getByRole('link', { name: 'Volver a la bandeja' }).getAttribute('href')).toBe('/tickets?status%5B%5D=open');
        expect(screen.getAllByRole('link').some((link) => link.getAttribute('href') === '/tickets/11?status%5B%5D=open')).toBe(true);
    });

    it('el contador de la lista dice cuántos tickets cumplen los filtros', () => {
        renderIndex({ total: 12345 });

        expect(screen.getByText('12.345 tickets')).toBeTruthy();
    });
});
