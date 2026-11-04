import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import type { TicketAiData } from '../ticketTypes';
import { AiDraftPanel } from './AiDraftPanel';

interface PostOptions {
    preserveScroll?: boolean;
    onFinish?: () => void;
}

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
let errors: Record<string, string> = {};

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url: string, data: unknown, options: PostOptions) => {
            post(url, data, options);
            options.onFinish?.();
        },
    },
    usePage: () => ({ props: { errors } }),
}));

const draft: TicketAiData = {
    id: 9,
    status: 'drafted',
    status_label: 'Borrador para revisar',
    reason_label: 'Respondió con información de la base de conocimiento.',
    body: 'Tienes 30 días para devolverlo.\nEl reembolso tarda 5 días.',
    sources: [
        { kind: 'article', title: 'Política de devoluciones' },
        { kind: 'document', title: 'Manual de envíos' },
    ],
    created_label: '3 nov, 4:00 pm',
};

function renderPanel(ai: TicketAiData = draft) {
    return render(<AiDraftPanel ticketId={11} ai={ai} copy={ticketsCopy.ai} />);
}

describe('AiDraftPanel', () => {
    beforeEach(() => {
        post.mockReset();
        errors = {};
    });

    it('muestra el borrador como texto, avisa que el cliente no lo ve y dice en qué se basó', () => {
        renderPanel();

        expect(screen.getByRole('heading', { name: 'Borrador de la IA' })).toBeTruthy();
        expect(screen.getByText('El cliente todavía no lo ve. Revísalo antes de enviarlo.')).toBeTruthy();
        expect(screen.getByText(/Tienes 30 días para devolverlo\./).textContent).toBe('Tienes 30 días para devolverlo.\nEl reembolso tarda 5 días.');
        expect(screen.getByText('Se basó en: Política de devoluciones, Manual de envíos')).toBeTruthy();
    });

    it('un texto con HTML se ve como texto, nunca como elementos', () => {
        const { container } = renderPanel({ ...draft, body: '<img src=x onerror="alert(1)"><b>negrita</b>' });

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('b')).toBeNull();
        expect(screen.getByText('<img src=x onerror="alert(1)"><b>negrita</b>')).toBeTruthy();
    });

    it('«Enviar tal cual» aprueba el borrador sin mandar texto', async () => {
        const user = userEvent.setup();
        renderPanel();

        await user.click(screen.getByRole('button', { name: 'Enviar tal cual' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/ai/9/approve', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('editar abre el campo con el texto y envía lo que la persona dejó', async () => {
        const user = userEvent.setup();
        renderPanel();

        await user.click(screen.getByRole('button', { name: 'Editar' }));
        const field = screen.getByLabelText('Texto que se enviará al cliente');
        expect((field as HTMLTextAreaElement).value).toBe(draft.body);
        await user.clear(field);
        await user.type(field, 'Son 15 días.');
        await user.click(screen.getByRole('button', { name: 'Enviar con mis cambios' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/ai/9/approve', { body: 'Son 15 días.' }, expect.anything());
    });

    it('con el campo vacío no se puede enviar y cancelar vuelve al borrador original', async () => {
        const user = userEvent.setup();
        renderPanel();

        await user.click(screen.getByRole('button', { name: 'Editar' }));
        await user.clear(screen.getByLabelText('Texto que se enviará al cliente'));
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar con mis cambios' }).disabled).toBe(true);

        await user.click(screen.getByRole('button', { name: 'Cancelar edición' }));

        expect(screen.queryByLabelText('Texto que se enviará al cliente')).toBeNull();
        expect(screen.getByText(/Tienes 30 días para devolverlo\./)).toBeTruthy();
        expect(post).not.toHaveBeenCalled();
    });

    it('descartar pide confirmar antes de hacer nada', async () => {
        const user = userEvent.setup();
        renderPanel();

        await user.click(screen.getByRole('button', { name: 'Descartar' }));
        expect(post).not.toHaveBeenCalled();

        const dialog = screen.getByRole('alertdialog', { hidden: true });
        expect(within(dialog).getByText('¿Descartar este borrador?')).toBeTruthy();
        await user.click(within(dialog).getByRole('button', { name: 'Descartar borrador', hidden: true }));

        expect(post).toHaveBeenCalledWith('/tickets/11/ai/9/discard', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('si el servidor rechaza (otra persona ya lo envió), lo dice como aviso', () => {
        errors = { draft: 'Este borrador ya no está disponible: alguien lo envió o lo descartó.' };
        renderPanel();

        expect(screen.getByRole('alert').textContent).toContain('Este borrador ya no está disponible');
    });

    it('sin borrador solo dice en qué quedó el último intento y por qué, sin botones', () => {
        renderPanel({ ...draft, status: 'no_answer', status_label: 'Sin respuesta', reason_label: 'No encontró en la base de conocimiento nada parecido a la pregunta.', body: null, sources: [] });

        expect(screen.getByText('Sin respuesta')).toBeTruthy();
        expect(screen.getByText('No encontró en la base de conocimiento nada parecido a la pregunta.')).toBeTruthy();
        expect(screen.queryByRole('button')).toBeNull();
    });
});
