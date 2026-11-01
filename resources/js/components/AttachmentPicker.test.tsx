import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { AttachmentPicker } from './AttachmentPicker';

const copy = ticketsCopy.attachments;

function pdf(name = 'a.pdf', modified = 1): File {
    return new File(['contenido'], name, { type: 'application/pdf', lastModified: modified });
}

function renderPicker(props: Partial<Parameters<typeof AttachmentPicker>[0]> = {}) {
    const onChange = vi.fn();
    const utils = render(<AttachmentPicker files={[]} copy={copy} progress={null} onChange={onChange} {...props} />);

    return { ...utils, onChange };
}

function fileInput(container: HTMLElement): HTMLInputElement {
    const input = container.querySelector('input[type=file]');
    if (!(input instanceof HTMLInputElement)) throw new Error('Falta el campo de archivos.');

    return input;
}

describe('AttachmentPicker', () => {
    it('muestra el botón y, siempre a la vista, los formatos y el peso máximo', () => {
        renderPicker();

        expect(screen.getByRole('button', { name: 'Adjuntar archivos' })).toBeTruthy();
        expect(screen.getByText('PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX. Hasta 5 archivos de 10 MB cada uno.')).toBeTruthy();
    });

    it('el campo real admite varios archivos y solo los formatos de la lista', () => {
        const { container } = renderPicker();
        const input = fileInput(container);

        expect(input.multiple).toBe(true);
        expect(input.accept).toBe('.png,.jpg,.jpeg,.webp,.pdf,.txt,.csv,.docx,.xlsx');
    });

    it('el botón abre el selector de archivos', async () => {
        const user = userEvent.setup();
        const { container } = renderPicker();
        const click = vi.spyOn(fileInput(container), 'click');

        await user.click(screen.getByRole('button', { name: 'Adjuntar archivos' }));

        expect(click).toHaveBeenCalledTimes(1);
    });

    it('elegir archivos los entrega al formulario', async () => {
        const user = userEvent.setup();
        const { container, onChange } = renderPicker();
        const file = pdf();

        await user.upload(fileInput(container), file);

        expect(onChange).toHaveBeenCalledWith([file]);
    });

    it('lo que no se admite se descarta con su motivo y no se entrega', async () => {
        const user = userEvent.setup({ applyAccept: false });
        const { container, onChange } = renderPicker();

        await user.upload(fileInput(container), [new File(['MZ'], 'programa.exe'), pdf('bueno.pdf')]);

        expect(onChange).toHaveBeenCalledTimes(1);
        const delivered = onChange.mock.calls[0]?.[0] as File[];
        expect(delivered.map((file) => file.name)).toEqual(['bueno.pdf']);
        expect(screen.getByRole('alert').textContent).toContain('«programa.exe» no se puede adjuntar');
    });

    it('lista lo elegido con su peso y una «x» para quitar cada uno', async () => {
        const user = userEvent.setup();
        const first = pdf('uno.pdf', 1);
        const second = pdf('dos.pdf', 2);
        const { onChange } = renderPicker({ files: [first, second] });

        const list = screen.getByRole('list', { name: 'Archivos por enviar' });
        expect(within(list).getAllByRole('listitem')).toHaveLength(2);
        expect(within(list).getAllByText('9 B')).toHaveLength(2);

        await user.click(screen.getByRole('button', { name: 'Quitar uno.pdf' }));

        expect(onChange).toHaveBeenCalledWith([second]);
    });

    it('con cinco archivos el botón se deshabilita', () => {
        const files = Array.from({ length: 5 }, (_, index) => pdf(`d${String(index)}.pdf`, index));
        renderPicker({ files });

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Adjuntar archivos' }).disabled).toBe(true);
    });

    it('mientras se envía no se puede cambiar lo elegido', () => {
        renderPicker({ files: [pdf()], disabled: true });

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Adjuntar archivos' }).disabled).toBe(true);
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Quitar a.pdf' }).disabled).toBe(true);
    });

    it('muestra el progreso de la subida con una barra y lo anuncia por tramos de 25 %', () => {
        renderPicker({ files: [pdf()], progress: 63 });

        expect(screen.getByRole('progressbar', { name: 'Subiendo archivos… 63 %' }).getAttribute('value')).toBe('63');
        expect(screen.getByRole('status').textContent).toBe('Subiendo archivos… 50 %');
    });

    it('sin subida en curso no hay barra', () => {
        renderPicker({ files: [pdf()] });

        expect(screen.queryByRole('progressbar')).toBeNull();
    });

    it('muestra el mensaje del servidor junto a los archivos', () => {
        renderPicker({ serverError: '«malo.exe»: Este tipo de archivo no se admite.' });

        expect(screen.getByRole('alert').textContent).toContain('«malo.exe»: Este tipo de archivo no se admite.');
    });

    it('quitar un archivo borra los avisos de los descartados', async () => {
        const user = userEvent.setup({ applyAccept: false });
        const first = pdf('uno.pdf');
        const { container } = renderPicker({ files: [first] });

        await user.upload(fileInput(container), new File(['x'], 'malo.exe'));
        expect(screen.getByRole('alert')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Quitar uno.pdf' }));

        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('los nombres se muestran como texto', () => {
        const { container } = renderPicker({ files: [pdf('<b>x</b>.pdf')] });

        expect(container.querySelector('b')).toBeNull();
        expect(screen.getByText('<b>x</b>.pdf')).toBeTruthy();
    });
});
