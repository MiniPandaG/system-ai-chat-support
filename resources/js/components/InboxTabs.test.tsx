import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import type { SavedView } from '../ticketTypes';
import { InboxTabs } from './InboxTabs';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const counts = { all: 14, mine: 3, unassigned: 5 };
const views: SavedView[] = [
    { id: 8, name: 'Urgentes de facturación', shared: true, count: 2, deletable: true },
    { id: 9, name: 'Mis pendientes', shared: false, count: 1, deletable: true },
];

function renderTabs(filters = {}) {
    return render(<InboxTabs copy={ticketsCopy.inbox} filters={filters} counts={counts} views={views} basePath="/tickets" />);
}

describe('InboxTabs', () => {
    it('es una navegación con los tres filtros rápidos y las vistas guardadas, cada uno con su contador', () => {
        renderTabs();

        const nav = screen.getByRole('navigation', { name: 'Vistas de la bandeja' });
        const links = within(nav).getAllByRole('link');

        expect(links.map((link) => link.textContent)).toEqual(['Todos14', 'Asignados a mí3', 'Sin asignar5', 'Urgentes de facturación (Compartida con el equipo)2', 'Mis pendientes1']);
    });

    it('cada pestaña apunta a su filtro y la de «Todos» no lleva parámetros', () => {
        renderTabs();

        expect(screen.getByRole('link', { name: /Todos/ }).getAttribute('href')).toBe('/tickets');
        expect(screen.getByRole('link', { name: /Asignados a mí/ }).getAttribute('href')).toBe('/tickets?scope=mine');
        expect(screen.getByRole('link', { name: /Sin asignar/ }).getAttribute('href')).toBe('/tickets?scope=unassigned');
        expect(screen.getByRole('link', { name: /Mis pendientes/ }).getAttribute('href')).toBe('/tickets?saved=9');
    });

    it('marca solo la vista activa con aria-current', () => {
        renderTabs({ scope: 'mine' });

        const current = screen.getAllByRole('link').filter((link) => link.getAttribute('aria-current') === 'page');

        expect(current).toHaveLength(1);
        expect(current[0]?.textContent).toContain('Asignados a mí');
    });

    it('una vista guardada activa desmarca los filtros rápidos', () => {
        renderTabs({ scope: 'mine', saved: 8 });

        const current = screen.getAllByRole('link').filter((link) => link.getAttribute('aria-current') === 'page');

        expect(current).toHaveLength(1);
        expect(current[0]?.textContent).toContain('Urgentes de facturación');
    });

    it('si hay un ticket abierto, las pestañas cambian la lista sin cerrarlo', () => {
        render(<InboxTabs copy={ticketsCopy.inbox} filters={{}} counts={counts} views={[]} basePath="/tickets/11" />);

        expect(screen.getByRole('link', { name: /Sin asignar/ }).getAttribute('href')).toBe('/tickets/11?scope=unassigned');
    });

    it('el nombre de una vista se muestra como texto', () => {
        const hostile: SavedView[] = [{ id: 1, name: '<b>x</b>', shared: false, count: 0, deletable: true }];
        const { container } = render(<InboxTabs copy={ticketsCopy.inbox} filters={{}} counts={counts} views={hostile} basePath="/tickets" />);

        expect(container.querySelector('b')).toBeNull();
        expect(screen.getByText('<b>x</b>')).toBeTruthy();
    });
});
