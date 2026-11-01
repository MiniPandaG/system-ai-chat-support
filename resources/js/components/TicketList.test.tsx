import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ticketRows, ticketsCopy } from '../test-fixtures/tickets';
import { TicketList } from './TicketList';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('TicketList', () => {
    it('es una lista con una fila por ticket', () => {
        render(<TicketList tickets={ticketRows} nextCursor={null} copy={ticketsCopy} selectedId={null} query="" />);

        expect(screen.getByRole('list', { name: 'Tickets' })).toBeTruthy();
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    it('las flechas mueven el foco de una fila a otra y no salen de los extremos', async () => {
        const user = userEvent.setup();
        render(<TicketList tickets={ticketRows} nextCursor={null} copy={ticketsCopy} selectedId={null} query="" />);
        const [first, second] = screen.getAllByRole('link');

        first?.focus();
        await user.keyboard('{ArrowDown}');
        expect(document.activeElement).toBe(second);

        await user.keyboard('{ArrowDown}');
        expect(document.activeElement).toBe(second);

        await user.keyboard('{ArrowUp}');
        expect(document.activeElement).toBe(first);
    });

    it('marca solo el ticket abierto', () => {
        render(<TicketList tickets={ticketRows} nextCursor={null} copy={ticketsCopy} selectedId={10} query="" />);

        const current = screen.getAllByRole('link').filter((link) => link.getAttribute('aria-current') === 'true');

        expect(current).toHaveLength(1);
        expect(current[0]?.getAttribute('href')).toBe('/tickets/10');
    });
});
