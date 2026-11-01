import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { assignees, ticketsCopy } from '../test-fixtures/tickets';
import { NewTicketDialog } from './NewTicketDialog';

let pageErrors: Record<string, string> = {};
const post = vi.fn();
let transformer: ((data: Record<string, unknown>) => Record<string, unknown>) | null = null;
let lastData: Record<string, unknown> = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { errors: pageErrors } }),
    // Un useForm mínimo pero con estado real, para poder escribir en los campos.
    useForm: (initial: Record<string, string>) => {
        const [data, setData] = useState(initial);
        lastData = data;

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: string, value: string) => {
                setData((current) => ({ ...current, [key]: value }));
            },
            reset: () => {
                setData(initial);
            },
            clearErrors: vi.fn(),
            transform: (callback: (data: Record<string, unknown>) => Record<string, unknown>) => {
                transformer = callback;
            },
            post: (...args: unknown[]) => {
                post(transformer?.(lastData), ...args);
            },
        };
    },
}));

vi.mock('./CustomerPicker', () => ({
    CustomerPicker: ({ onSelect, selected }: { onSelect: (customer: { id: number; name: string; email: string | null } | null) => void; selected: { name: string } | null }) => (
        <div>
            {selected === null ? (
                <button
                    type="button"
                    onClick={() => {
                        onSelect({ id: 4, name: 'Marisol Quintero', email: null });
                    }}
                >
                    elegir cliente de prueba
                </button>
            ) : (
                <p>elegido: {selected.name}</p>
            )}
        </div>
    ),
}));

function renderDialog(onClose = vi.fn()) {
    render(<NewTicketDialog open copy={ticketsCopy} assignees={assignees} onClose={onClose} />);

    return onClose;
}

describe('NewTicketDialog', () => {
    beforeEach(() => {
        pageErrors = {};
        post.mockReset();
        transformer = null;
    });

    it('se abre como un diálogo con su título y enfoca el asunto', () => {
        renderDialog();

        const dialog = document.querySelector('dialog[aria-labelledby="new-ticket-title"]');

        expect(dialog?.hasAttribute('open')).toBe(true);
        expect(screen.getByRole('heading', { name: 'Nuevo ticket' })).toBeTruthy();
        expect(document.activeElement).toBe(screen.getByLabelText('Asunto'));
    });

    it('crear está deshabilitado hasta tener asunto, descripción y cliente', async () => {
        const user = userEvent.setup();
        renderDialog();
        const submit = screen.getByRole('button', { name: 'Crear ticket' });

        expect((submit as HTMLButtonElement).disabled).toBe(true);

        await user.type(screen.getByLabelText('Asunto'), 'No puedo entrar');
        await user.type(screen.getByLabelText('Descripción'), 'Dice que mi clave es incorrecta.');
        expect((submit as HTMLButtonElement).disabled).toBe(true);

        await user.click(screen.getByRole('button', { name: 'elegir cliente de prueba' }));
        expect((submit as HTMLButtonElement).disabled).toBe(false);
    });

    it('con un cliente existente envía solo su identificador', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.type(screen.getByLabelText('Asunto'), 'No puedo entrar');
        await user.type(screen.getByLabelText('Descripción'), 'Dice que mi clave es incorrecta.');
        await user.click(screen.getByRole('button', { name: 'elegir cliente de prueba' }));
        await user.selectOptions(screen.getByLabelText('Prioridad'), 'high');
        await user.selectOptions(screen.getByLabelText('Agente asignado'), 'Luis Pérez');
        await user.click(screen.getByRole('button', { name: 'Crear ticket' }));

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0]?.[0]).toEqual({
            subject: 'No puedo entrar',
            description: 'Dice que mi clave es incorrecta.',
            priority: 'high',
            assigned_to: 2,
            customer_id: 4,
        });
        expect(post.mock.calls[0]?.[1]).toBe('/tickets');
    });

    it('con un cliente nuevo envía su nombre y correo, sin identificador', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.type(screen.getByLabelText('Asunto'), 'Factura duplicada');
        await user.type(screen.getByLabelText('Descripción'), 'Me cobraron dos veces.');
        await user.click(screen.getByRole('radio', { name: 'Cliente nuevo' }));
        await user.type(screen.getByLabelText('Nombre del cliente'), 'Julián Pardo');
        await user.type(screen.getByLabelText('Correo del cliente (opcional)'), 'julian@cliente-ejemplo.com');
        await user.click(screen.getByRole('button', { name: 'Crear ticket' }));

        expect(post.mock.calls[0]?.[0]).toEqual({
            subject: 'Factura duplicada',
            description: 'Me cobraron dos veces.',
            priority: 'normal',
            assigned_to: null,
            customer_name: 'Julián Pardo',
            customer_email: 'julian@cliente-ejemplo.com',
        });
    });

    it('cancelar avisa a quien lo abrió y no envía nada', async () => {
        const user = userEvent.setup();
        const onClose = renderDialog();

        await user.click(screen.getByRole('button', { name: 'Cancelar' }));

        expect(onClose).toHaveBeenCalledTimes(1);
        expect(post).not.toHaveBeenCalled();
    });

    it('muestra el mensaje del límite del plan dentro del diálogo', () => {
        pageErrors = { limit: 'Tu plan Inicial permite 100 tickets al mes y ya usaste 100.' };
        renderDialog();

        expect(screen.getByRole('alert').textContent).toContain('Tu plan Inicial permite 100 tickets al mes');
    });

    it('los máximos del servidor se ven en el formulario: contador de la descripción y tope del asunto', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.type(screen.getByLabelText('Descripción'), 'Hola');

        expect(screen.getByText('4 / 10000')).toBeTruthy();
        expect(screen.getByLabelText('Asunto').getAttribute('maxlength')).toBe('150');
    });

    it('el cliente existente es la opción de partida', () => {
        renderDialog();

        expect(screen.getByRole<HTMLInputElement>('radio', { name: 'Cliente existente' }).checked).toBe(true);
        expect(screen.getByRole<HTMLInputElement>('radio', { name: 'Cliente nuevo' }).checked).toBe(false);
    });
});
