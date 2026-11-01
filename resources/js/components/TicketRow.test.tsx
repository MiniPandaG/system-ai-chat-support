import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { pick, ticketRows, ticketsCopy } from '../test-fixtures/tickets';
import { TicketRow } from './TicketRow';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

function renderRow(index: number, selected = false) {
    const row = pick(ticketRows, index);

    render(
        <ul>
            <TicketRow ticket={row} copy={ticketsCopy} selected={selected} />
        </ul>,
    );

    return screen.getByRole('link');
}

describe('TicketRow', () => {
    it('es un enlace al ticket con cliente, número, asunto y estado', () => {
        const link = renderRow(0);

        expect(link.getAttribute('href')).toBe('/tickets/11');
        expect(within(link).getByText('Marisol Quintero')).toBeTruthy();
        expect(within(link).getByText('#3')).toBeTruthy();
        expect(within(link).getByText(/No puedo entrar a mi cuenta/)).toBeTruthy();
        expect(within(link).getByText('Abierto')).toBeTruthy();
    });

    it('dice la prioridad con texto, no solo con la barra de color', () => {
        renderRow(0);

        expect(screen.getByText('Prioridad: Urgente')).toBeTruthy();
    });

    it('sin agente dice «Sin asignar» y con agente muestra su nombre', () => {
        const { unmount } = render(
            <ul>
                <TicketRow ticket={pick(ticketRows, 0)} copy={ticketsCopy} selected={false} />
            </ul>,
        );
        expect(screen.getByText('Sin asignar')).toBeTruthy();
        unmount();

        renderRow(1);
        expect(screen.getByText('Ana Torres')).toBeTruthy();
        expect(screen.getByText('En espera del cliente')).toBeTruthy();
    });

    it('la hora lleva su fecha exacta en el título y en dateTime', () => {
        const link = renderRow(0);
        const time = link.querySelector('time');

        expect(time?.getAttribute('dateTime')).toBe('2026-10-31T15:00:00-05:00');
        expect(time?.getAttribute('title')).toBe('31 de oct, 3:00 pm');
    });

    it('el ticket abierto se marca con aria-current, no solo con color', () => {
        expect(renderRow(0, true).getAttribute('aria-current')).toBe('true');
    });

    it('un cliente sin nombre muestra el texto de reemplazo', () => {
        render(
            <ul>
                <TicketRow ticket={{ ...pick(ticketRows, 0), customer_name: null }} copy={ticketsCopy} selected={false} />
            </ul>,
        );

        expect(screen.getByText('Cliente sin nombre')).toBeTruthy();
    });
});

describe('TicketRow: sin leer', () => {
    function renderUnread(unread: boolean) {
        return render(
            <ul>
                <TicketRow ticket={{ ...pick(ticketRows, 0), unread }} copy={ticketsCopy} selected={false} />
            </ul>,
        );
    }

    it('un ticket sin leer lleva un punto y lo dice con palabras, no solo con color', () => {
        const { container } = renderUnread(true);

        expect(container.querySelector('.bg-primary.rounded-full')).not.toBeNull();
        expect(screen.getByText('Sin leer').className).toContain('sr-only');
        expect(screen.getByRole('link').textContent).toContain('Sin leer');
    });

    it('el nombre del cliente se destaca en negrita cuando hay algo sin leer', () => {
        renderUnread(true);

        expect(screen.getByText('Marisol Quintero').parentElement?.className).toContain('font-semibold');
    });

    it('un ticket leído no lleva punto ni texto de más', () => {
        const { container } = renderUnread(false);

        expect(container.querySelector('.bg-primary.rounded-full')).toBeNull();
        expect(screen.queryByText('Sin leer')).toBeNull();
        expect(screen.getByText('Marisol Quintero').parentElement?.className).not.toContain('font-semibold');
    });
});
