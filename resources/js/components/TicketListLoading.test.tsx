import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { pick, ticketRows, ticketsCopy } from '../test-fixtures/tickets';
import type { TicketRowData } from '../ticketTypes';
import { TicketList, TicketListSkeleton } from './TicketList';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const first = pick(ticketRows, 0);
const second = pick(ticketRows, 1);
const third: TicketRowData = { ...first, id: 30, number: 8, subject: 'Un ticket más', match: null };

function respond(body: { data: TicketRowData[]; next_cursor: string | null }, ok = true) {
    const fetchMock = vi.fn().mockResolvedValue({ ok, status: ok ? 200 : 500, json: () => Promise.resolve(body) });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

function rowCount() {
    return within(screen.getByRole('list', { name: 'Tickets' })).getAllByRole('listitem').length;
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('TicketList: carga progresiva', () => {
    it('sin cursor no hay botón de cargar más', () => {
        render(<TicketList tickets={ticketRows} nextCursor={null} copy={ticketsCopy} selectedId={null} query="" />);

        expect(screen.queryByRole('button', { name: 'Cargar más tickets' })).toBeNull();
    });

    it('con cursor ofrece cargar más y añade el tramo siguiente al final con los mismos filtros', async () => {
        const user = userEvent.setup();
        const fetchMock = respond({ data: [third], next_cursor: null });
        render(<TicketList tickets={ticketRows} nextCursor="abc 123" copy={ticketsCopy} selectedId={null} query="?status%5B%5D=open" />);

        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));

        await waitFor(() => {
            expect(rowCount()).toBe(3);
        });
        expect(fetchMock).toHaveBeenCalledWith('/tickets/page?status%5B%5D=open&cursor=abc%20123', expect.anything());
        expect(JSON.stringify(fetchMock.mock.calls[0]?.[1])).toContain('application/json');
        expect(screen.queryByRole('button', { name: 'Cargar más tickets' })).toBeNull();
    });

    it('sin filtros el cursor va como único parámetro', async () => {
        const user = userEvent.setup();
        const fetchMock = respond({ data: [third], next_cursor: null });
        render(<TicketList tickets={ticketRows} nextCursor="xyz" copy={ticketsCopy} selectedId={null} query="" />);

        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith('/tickets/page?cursor=xyz', expect.anything());
        });
    });

    it('si hay otro tramo después, el botón sigue y usa el cursor nuevo', async () => {
        const user = userEvent.setup();
        const fetchMock = respond({ data: [third], next_cursor: 'siguiente' });
        render(<TicketList tickets={ticketRows} nextCursor="primero" copy={ticketsCopy} selectedId={null} query="" />);

        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));
        await screen.findByText('Un ticket más', { exact: false });
        fetchMock.mockClear();
        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));

        expect(fetchMock).toHaveBeenCalledWith('/tickets/page?cursor=siguiente', expect.anything());
    });

    it('un ticket que ya está arriba no se repite si vuelve a llegar en un tramo siguiente', async () => {
        const user = userEvent.setup();
        respond({ data: [first, third], next_cursor: null });
        render(<TicketList tickets={ticketRows} nextCursor="abc" copy={ticketsCopy} selectedId={null} query="" />);

        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));

        await waitFor(() => {
            expect(rowCount()).toBe(3);
        });
    });

    it('si falla, avisa y deja reintentar sin perder lo que ya había', async () => {
        const user = userEvent.setup();
        respond({ data: [], next_cursor: null }, false);
        render(<TicketList tickets={ticketRows} nextCursor="abc" copy={ticketsCopy} selectedId={null} query="" />);

        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));

        expect((await screen.findByRole('alert')).textContent).toBe('No se pudieron cargar más tickets. Inténtalo de nuevo.');
        expect(rowCount()).toBe(2);
        expect(screen.getByRole('button', { name: 'Cargar más tickets' })).toBeTruthy();
    });

    it('mientras carga muestra filas de relleno y lo anuncia', async () => {
        const user = userEvent.setup();
        let release: (value: unknown) => void = () => undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn().mockReturnValue(
                new Promise((resolve) => {
                    release = resolve;
                }),
            ),
        );
        const { container } = render(<TicketList tickets={ticketRows} nextCursor="abc" copy={ticketsCopy} selectedId={null} query="" />);

        await user.click(screen.getByRole('button', { name: 'Cargar más tickets' }));

        expect(container.querySelector('ul[aria-hidden="true"].animate-pulse')).not.toBeNull();
        expect(screen.getByText('Cargando más tickets…')).toBeTruthy();

        release({ ok: true, json: () => Promise.resolve({ data: [], next_cursor: null }) });
        await waitFor(() => {
            expect(container.querySelector('ul[aria-hidden="true"].animate-pulse')).toBeNull();
        });
    });
});

describe('TicketList: enlaces y coincidencias', () => {
    it('cada fila conserva los filtros en su enlace', () => {
        render(<TicketList tickets={ticketRows} nextCursor={null} copy={ticketsCopy} selectedId={null} query="?scope=mine" />);

        expect(screen.getAllByRole('link').map((link) => link.getAttribute('href'))).toEqual(['/tickets/11?scope=mine', '/tickets/10?scope=mine']);
    });

    it('con una búsqueda, cada fila dice en qué coincidió', () => {
        const rows: TicketRowData[] = [
            { ...first, match: 'message' },
            { ...second, match: 'customer' },
        ];
        render(<TicketList tickets={rows} nextCursor={null} copy={ticketsCopy} selectedId={null} query="?q=hola" />);

        expect(screen.getByText('Coincide en el mensaje')).toBeTruthy();
        expect(screen.getByText('Coincide en el cliente')).toBeTruthy();
    });

    it('sin búsqueda no aparece ninguna leyenda de coincidencia', () => {
        render(<TicketList tickets={ticketRows} nextCursor={null} copy={ticketsCopy} selectedId={null} query="" />);

        expect(screen.queryByText(/Coincide en/)).toBeNull();
    });
});

describe('TicketListSkeleton', () => {
    it('no se anuncia a los lectores de pantalla y tiene el número de filas pedido', () => {
        const { container } = render(<TicketListSkeleton rows={4} />);

        expect(container.querySelector('ul')?.getAttribute('aria-hidden')).toBe('true');
        expect(container.querySelectorAll('li')).toHaveLength(4);
    });
});
