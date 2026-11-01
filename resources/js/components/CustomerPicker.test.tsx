import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { CustomerPicker } from './CustomerPicker';

const found = [
    { id: 4, name: 'Marisol Quintero', email: 'marisol@cliente-ejemplo.com' },
    { id: 9, name: 'Marisol Duarte', email: null },
];

let fetchMock: ReturnType<typeof vi.fn>;

function respondWith(data: unknown[], ok = true) {
    fetchMock.mockResolvedValue({ ok, json: () => Promise.resolve({ data }) });
}

async function typeAndWait(user: ReturnType<typeof userEvent.setup>, text: string) {
    await user.type(screen.getByLabelText('Buscar por nombre o correo'), text);
    await act(async () => {
        await vi.advanceTimersByTimeAsync(300);
    });
}

describe('CustomerPicker', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('no busca con menos de dos letras', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'm');

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('espera a que se deje de escribir y pide la búsqueda una sola vez', async () => {
        respondWith(found);
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'mari');

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0]?.[0]).toBe('/customers/search?q=mari');
        expect((fetchMock.mock.calls[0]?.[1] as { headers: Record<string, string> }).headers.Accept).toBe('application/json');
    });

    it('codifica lo escrito en la dirección', async () => {
        respondWith([]);
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'a&b=c');

        expect(fetchMock.mock.calls[0]?.[0]).toBe('/customers/search?q=a%26b%3Dc');
    });

    it('lista los resultados como botones y anuncia cuántos hay', async () => {
        respondWith(found);
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'mari');

        expect(screen.getByRole('status').textContent).toBe('2 clientes encontrados.');
        expect(screen.getByRole('button', { name: /Marisol Quintero/ })).toBeTruthy();
        expect(screen.getByRole('button', { name: /Marisol Duarte/ })).toBeTruthy();
    });

    it('con un solo resultado usa el singular', async () => {
        respondWith([found[0]]);
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'mari');

        expect(screen.getByRole('status').textContent).toBe('1 cliente encontrado.');
    });

    it('elegir un cliente lo entrega a quien lo usa', async () => {
        respondWith(found);
        const onSelect = vi.fn();
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={onSelect} />);

        await typeAndWait(user, 'mari');
        await user.click(screen.getByRole('button', { name: /Marisol Quintero/ }));

        expect(onSelect).toHaveBeenCalledWith(found[0]);
    });

    it('sin coincidencias dice qué hacer, con el término escrito', async () => {
        respondWith([]);
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'zzz');

        expect(screen.getByRole('status').textContent).toBe('Ningún cliente coincide con «zzz». Crea uno nuevo.');
    });

    it('si la búsqueda falla no rompe la pantalla ni deja resultados viejos', async () => {
        fetchMock.mockRejectedValue(new Error('sin conexión'));
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'mari');

        expect(screen.queryByRole('button', { name: /Marisol/ })).toBeNull();
        expect(screen.getByRole('status').textContent).toBe('');
    });

    it('una respuesta con error del servidor se trata como sin resultados', async () => {
        respondWith([], false);
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} onSelect={vi.fn()} />);

        await typeAndWait(user, 'mari');

        expect(screen.getByRole('status').textContent).toContain('Ningún cliente coincide');
    });

    it('con un cliente elegido lo muestra y permite cambiarlo', async () => {
        const onSelect = vi.fn();
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        render(<CustomerPicker copy={ticketsCopy.new} selected={found[0] ?? null} onSelect={onSelect} />);

        expect(screen.getByText('Marisol Quintero')).toBeTruthy();
        expect(screen.getByText('marisol@cliente-ejemplo.com')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Cambiar cliente' }));

        expect(onSelect).toHaveBeenCalledWith(null);
    });

    it('muestra el error del servidor junto al buscador', () => {
        render(<CustomerPicker copy={ticketsCopy.new} selected={null} error="Ese cliente ya no existe en tu empresa." onSelect={vi.fn()} />);

        expect(screen.getByText('Ese cliente ya no existe en tu empresa.')).toBeTruthy();
        expect(screen.getByLabelText('Buscar por nombre o correo').getAttribute('aria-invalid')).toBe('true');
    });
});
