import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import type { TicketStatus } from '../ticketTypes';
import { TicketComposer } from './TicketComposer';

interface PostOptions {
    preserveScroll?: boolean;
    onSuccess?: () => void;
}

const { post, patch, transform, clearErrors } = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn(), transform: vi.fn(), clearErrors: vi.fn() }));
let formErrors: Record<string, string> = {};
let processing = false;

vi.mock('@inertiajs/react', () => ({
    router: { patch },
    useForm: () => ({ errors: formErrors, processing, clearErrors, transform, post }),
}));

function renderComposer(status: TicketStatus = 'open', canWrite = true) {
    return render(<TicketComposer ticketId={11} status={status} canWrite={canWrite} copy={ticketsCopy.composer} attachmentsCopy={ticketsCopy.attachments} />);
}

describe('TicketComposer', () => {
    beforeEach(() => {
        post.mockReset();
        patch.mockReset();
        transform.mockReset();
        clearErrors.mockReset();
        formErrors = {};
        processing = false;
    });

    it('empieza en «Responder al cliente» y el botón espera a que haya texto', async () => {
        const user = userEvent.setup();
        renderComposer();

        expect(screen.getByRole<HTMLInputElement>('radio', { name: 'Responder al cliente' }).checked).toBe(true);
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar respuesta' }).disabled).toBe(true);

        await user.type(screen.getByLabelText('Mensaje para el cliente'), '   ');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar respuesta' }).disabled).toBe(true);

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Hola');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar respuesta' }).disabled).toBe(false);
    });

    it('enviar una respuesta la manda a los mensajes del ticket con el texto escrito', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Ya lo revisamos');
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/messages', expect.objectContaining({ preserveScroll: true }));
        const transformer = transform.mock.calls[0]?.[0] as () => unknown;
        expect(transformer()).toEqual({ body: 'Ya lo revisamos' });
    });

    it('al guardarse, el campo se vacía; si el servidor la rechaza, el texto se conserva', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Texto');
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));
        expect(screen.getByLabelText<HTMLTextAreaElement>('Mensaje para el cliente').value).toBe('Texto');

        const options = post.mock.calls[0]?.[1] as PostOptions;
        options.onSuccess?.();
        await screen.findByDisplayValue('');
        expect(screen.getByLabelText<HTMLTextAreaElement>('Mensaje para el cliente').value).toBe('');
    });

    it('Ctrl + Enter envía y Enter solo escribe un salto de línea', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Línea uno{Enter}línea dos');
        expect(post).not.toHaveBeenCalled();
        expect(screen.getByLabelText<HTMLTextAreaElement>('Mensaje para el cliente').value).toBe('Línea uno\nlínea dos');

        await user.keyboard('{Control>}{Enter}{/Control}');
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('cambiar a «Nota interna» cambia el campo, el texto del botón, el color y lo anuncia', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();
        expect(container.querySelector('form')?.className).not.toContain('bg-bubble-nota-interna');

        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));

        expect(screen.getByLabelText('Nota para el equipo')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Guardar nota' })).toBeTruthy();
        expect(container.querySelector('form')?.className).toContain('bg-bubble-nota-interna');
        expect(screen.getByRole('status').textContent).toBe('Modo nota interna: el cliente no verá este mensaje.');
        expect(clearErrors).toHaveBeenCalled();
    });

    it('guardar una nota va a las notas del ticket, nunca a los mensajes', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));
        await user.type(screen.getByLabelText('Nota para el equipo'), 'Solo para nosotros');
        await user.click(screen.getByRole('button', { name: 'Guardar nota' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/notes', expect.anything());
        expect(post).not.toHaveBeenCalledWith('/tickets/11/messages', expect.anything());
    });

    it('lo escrito como nota nunca pasa a una respuesta al cambiar de modo', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));
        await user.type(screen.getByLabelText('Nota para el equipo'), 'Opinión interna');
        await user.click(screen.getByRole('radio', { name: 'Responder al cliente' }));

        expect(screen.getByLabelText<HTMLTextAreaElement>('Mensaje para el cliente').value).toBe('');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar respuesta' }).disabled).toBe(true);

        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));
        expect(screen.getByLabelText<HTMLTextAreaElement>('Nota para el equipo').value).toBe('Opinión interna');
    });

    it('muestra el error del servidor asociado al campo', () => {
        formErrors = { body: 'El texto no puede superar los 10000 caracteres.' };
        renderComposer();

        const field = screen.getByLabelText('Mensaje para el cliente');
        expect(field.getAttribute('aria-invalid')).toBe('true');
        expect(screen.getByText('El texto no puede superar los 10000 caracteres.')).toBeTruthy();
    });

    it('mientras se envía el botón queda en estado cargando', () => {
        processing = true;
        renderComposer();

        expect(screen.getByRole('button', { name: /Enviar respuesta/ }).getAttribute('aria-busy')).toBe('true');
    });

    it('cuenta los caracteres sobre el máximo de 10 000', () => {
        renderComposer();

        expect(screen.getByText('0 / 10000')).toBeTruthy();
    });

    it('un ticket cerrado reemplaza el compositor por un aviso con «Reabrir»', async () => {
        const user = userEvent.setup();
        renderComposer('closed');

        expect(screen.queryByLabelText('Mensaje para el cliente')).toBeNull();
        expect(screen.getByText('Este ticket está cerrado.')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Reabrir' }));

        expect(patch).toHaveBeenCalledWith('/tickets/11', { status: 'open' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('un observador no ve campo ni botones, solo por qué', () => {
        renderComposer('open', false);

        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.getByText(/Tu rol es de observador/)).toBeTruthy();
    });

    it('un observador en un ticket cerrado tampoco ve «Reabrir»', () => {
        renderComposer('closed', false);

        expect(screen.queryByRole('button', { name: 'Reabrir' })).toBeNull();
    });
});
