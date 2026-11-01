import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { TicketComposer } from './TicketComposer';

interface PostOptions {
    onSuccess?: () => void;
}

const { post, transform } = vi.hoisted(() => ({ post: vi.fn(), transform: vi.fn() }));
let formErrors: Record<string, string> = {};
let processing = false;
let progress: { percentage: number } | null = null;

vi.mock('@inertiajs/react', () => ({
    router: { patch: vi.fn() },
    useForm: () => ({ errors: formErrors, processing, progress, clearErrors: vi.fn(), transform, post }),
}));

function pdf(name = 'contrato.pdf', modified = 1): File {
    return new File(['contenido'], name, { type: 'application/pdf', lastModified: modified });
}

function renderComposer() {
    return render(<TicketComposer ticketId={11} status="open" canWrite copy={ticketsCopy.composer} attachmentsCopy={ticketsCopy.attachments} />);
}

function fileInput(container: HTMLElement): HTMLInputElement {
    const input = container.querySelector('input[type=file]');
    if (!(input instanceof HTMLInputElement)) throw new Error('Falta el campo de archivos.');

    return input;
}

/** Lo que el formulario enviaría en este momento, según la última transformación registrada. */
function lastPayload(): unknown {
    const transformer = transform.mock.calls.at(-1)?.[0] as (() => unknown) | undefined;

    return transformer?.();
}

describe('TicketComposer: adjuntos', () => {
    beforeEach(() => {
        post.mockReset();
        transform.mockReset();
        formErrors = {};
        processing = false;
        progress = null;
    });

    it('al responder al cliente se pueden adjuntar archivos', () => {
        renderComposer();

        expect(screen.getByRole('button', { name: 'Adjuntar archivos' })).toBeTruthy();
    });

    it('en una nota interna no hay adjuntos', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));

        expect(screen.queryByRole('button', { name: 'Adjuntar archivos' })).toBeNull();
    });

    it('con un archivo elegido se puede enviar aunque no haya texto', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar respuesta' }).disabled).toBe(true);

        await user.upload(fileInput(container), pdf());

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar respuesta' }).disabled).toBe(false);
    });

    it('envía el texto y los archivos juntos a los mensajes del ticket', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();
        const file = pdf();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Te adjunto el contrato');
        await user.upload(fileInput(container), file);
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/messages', expect.anything());
        expect(lastPayload()).toEqual({ body: 'Te adjunto el contrato', files: [file] });
    });

    it('con solo archivos envía el texto vacío y los archivos', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();
        const file = pdf();

        await user.upload(fileInput(container), file);
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));

        expect(lastPayload()).toEqual({ body: '', files: [file] });
    });

    it('sin archivos no manda ninguna clave de archivos', async () => {
        const user = userEvent.setup();
        renderComposer();

        await user.type(screen.getByLabelText('Mensaje para el cliente'), 'Solo texto');
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));

        expect(lastPayload()).toEqual({ body: 'Solo texto' });
    });

    it('al guardarse, los archivos elegidos se vacían; si falla, se conservan', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();

        await user.upload(fileInput(container), pdf());
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));
        expect(screen.getByRole('list', { name: 'Archivos por enviar' })).toBeTruthy();

        (post.mock.calls[0]?.[1] as PostOptions).onSuccess?.();

        await waitFor(() => {
            expect(screen.queryByRole('list', { name: 'Archivos por enviar' })).toBeNull();
        });
    });

    it('una nota nunca lleva los archivos que había elegidos para la respuesta, y vuelven al cambiar de modo', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();
        const file = pdf();

        await user.upload(fileInput(container), file);
        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));
        await user.type(screen.getByLabelText('Nota para el equipo'), 'Una nota');
        await user.click(screen.getByRole('button', { name: 'Guardar nota' }));

        expect(post).toHaveBeenCalledWith('/tickets/11/notes', expect.anything());
        expect(lastPayload()).toEqual({ body: 'Una nota' });

        await user.click(screen.getByRole('radio', { name: 'Responder al cliente' }));
        expect(screen.getByText('contrato.pdf')).toBeTruthy();
    });

    it('en una nota, tener archivos elegidos para la respuesta no habilita enviar sin texto', async () => {
        const user = userEvent.setup();
        const { container } = renderComposer();

        await user.upload(fileInput(container), pdf());
        await user.click(screen.getByRole('radio', { name: 'Nota interna' }));

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar nota' }).disabled).toBe(true);
    });

    it('muestra el error del servidor sobre los archivos', () => {
        formErrors = { files: '«malo.exe»: Este tipo de archivo no se admite. Usa PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX.' };
        renderComposer();

        expect(screen.getByRole('alert').textContent).toContain('«malo.exe»: Este tipo de archivo no se admite.');
    });

    it('también muestra el error de un archivo concreto', () => {
        formErrors = { 'files.1': 'Cada archivo puede pesar hasta 10 MB.' };
        renderComposer();

        expect(screen.getByRole('alert').textContent).toContain('Cada archivo puede pesar hasta 10 MB.');
    });

    it('mientras sube muestra el progreso real del envío', async () => {
        const user = userEvent.setup();
        const { container, rerender } = renderComposer();
        await user.upload(fileInput(container), pdf());

        processing = true;
        progress = { percentage: 60 };
        rerender(<TicketComposer ticketId={11} status="open" canWrite copy={ticketsCopy.composer} attachmentsCopy={ticketsCopy.attachments} />);

        expect(screen.getByRole('progressbar', { name: 'Subiendo archivos… 60 %' })).toBeTruthy();
    });

    it('sin archivos, enviar no muestra barra de progreso', () => {
        processing = true;
        renderComposer();

        expect(screen.queryByRole('progressbar')).toBeNull();
    });

    describe('arrastrar y soltar', () => {
        it('soltar archivos sobre el compositor los añade, igual que el botón', () => {
            renderComposer();
            const form = screen.getByRole('form', { name: 'Escribir en el ticket' });

            fireEvent.drop(form, { dataTransfer: { files: [pdf('arrastrado.pdf')], types: ['Files'] } });

            expect(screen.getByText('arrastrado.pdf')).toBeTruthy();
        });

        it('al arrastrar se resalta el compositor y se dice qué hacer', () => {
            renderComposer();
            const form = screen.getByRole('form', { name: 'Escribir en el ticket' });

            fireEvent.dragOver(form, { dataTransfer: { types: ['Files'] } });

            expect(form.className).toContain('border-primary');
            expect(screen.getByText('Suelta los archivos para adjuntarlos')).toBeTruthy();

            fireEvent.dragLeave(form);
            expect(form.className).not.toContain('border-primary');
        });

        it('arrastrar texto u otra cosa que no sea un archivo no resalta nada', () => {
            renderComposer();
            const form = screen.getByRole('form', { name: 'Escribir en el ticket' });

            fireEvent.dragOver(form, { dataTransfer: { types: ['text/plain'] } });

            expect(form.className).not.toContain('border-primary');
        });

        it('en una nota, soltar archivos no hace nada', async () => {
            const user = userEvent.setup();
            renderComposer();
            await user.click(screen.getByRole('radio', { name: 'Nota interna' }));
            const form = screen.getByRole('form', { name: 'Escribir en el ticket' });

            fireEvent.drop(form, { dataTransfer: { files: [pdf('arrastrado.pdf')], types: ['Files'] } });
            await user.click(screen.getByRole('radio', { name: 'Responder al cliente' }));

            expect(screen.queryByText('arrastrado.pdf')).toBeNull();
        });

        it('un archivo no admitido soltado se descarta con su motivo', () => {
            renderComposer();
            const form = screen.getByRole('form', { name: 'Escribir en el ticket' });

            fireEvent.drop(form, { dataTransfer: { files: [new File(['MZ'], 'programa.exe')], types: ['Files'] } });

            expect(screen.queryByText('programa.exe')).toBeNull();
        });
    });
});
