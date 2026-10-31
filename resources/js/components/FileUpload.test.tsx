import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { FileUpload } from './FileUpload';

const copy = {
    drop: 'Arrastra un archivo o',
    select: 'selecciona uno',
    help: 'PNG, JPEG o WebP, máximo 2 MB.',
    uploading: 'Subiendo :name…',
    progress: ':percent % subido',
    cancel: 'Cancelar la subida',
    too_large: 'Este archivo pesa :size MB; el máximo es :max MB.',
    wrong_type: 'Solo se admiten imágenes PNG, JPEG o WebP.',
};

const accept = ['image/png', 'image/jpeg', 'image/webp'];
const max = 2 * 1024 * 1024;

function setup(overrides: Partial<Parameters<typeof FileUpload>[0]> = {}) {
    const onSelect = vi.fn();
    const onCancel = vi.fn();
    render(<FileUpload copy={copy} accept={accept} maxBytes={max} uploading={null} onSelect={onSelect} onCancel={onCancel} {...overrides} />);

    return { onSelect, onCancel };
}

function fileOf(name: string, type: string, size = 1000): File {
    return new File([new Uint8Array(size)], name, { type });
}

describe('FileUpload', () => {
    it('muestra siempre los formatos y el tamaño máximo, antes de intentar subir', () => {
        setup();

        expect(screen.getByText('PNG, JPEG o WebP, máximo 2 MB.')).toBeTruthy();
        expect(screen.getByText('selecciona uno')).toBeTruthy();
    });

    it('el input real es un archivo con los tipos admitidos y se llega a él con el teclado', async () => {
        const user = userEvent.setup();
        setup();

        const input = screen.getByLabelText<HTMLInputElement>(/Arrastra un archivo/);

        expect(input.type).toBe('file');
        expect(input.accept).toBe('image/png,image/jpeg,image/webp');

        await user.tab();
        expect(document.activeElement).toBe(input);
    });

    it('entrega un archivo válido', async () => {
        const user = userEvent.setup();
        const { onSelect } = setup();
        const file = fileOf('logo.png', 'image/png');

        await user.upload(screen.getByLabelText(/Arrastra un archivo/), file);

        expect(onSelect).toHaveBeenCalledWith(file);
    });

    it('rechaza en el navegador un tipo no admitido, sin entregarlo', () => {
        const { onSelect } = setup();

        fireEvent.change(screen.getByLabelText(/Arrastra un archivo/), { target: { files: [fileOf('logo.svg', 'image/svg+xml')] } });

        expect(onSelect).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toBe('Solo se admiten imágenes PNG, JPEG o WebP.');
    });

    it('rechaza un archivo demasiado pesado con el tamaño exacto', () => {
        const { onSelect } = setup();

        fireEvent.change(screen.getByLabelText(/Arrastra un archivo/), { target: { files: [fileOf('logo.png', 'image/png', 3 * 1024 * 1024)] } });

        expect(onSelect).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toBe('Este archivo pesa 3,0 MB; el máximo es 2,0 MB.');
    });

    it('soltar un archivo sobre la zona lo entrega, y soltar uno inválido muestra el error', () => {
        const { onSelect } = setup();
        const zone = screen.getByText('selecciona uno').closest('label');
        if (zone === null) throw new Error('No hay zona.');

        const good = fileOf('logo.webp', 'image/webp');
        fireEvent.drop(zone, { dataTransfer: { files: [good] } });
        expect(onSelect).toHaveBeenCalledWith(good);

        fireEvent.drop(zone, { dataTransfer: { files: [fileOf('nota.txt', 'text/plain')] } });
        expect(screen.getByRole('alert')).toBeTruthy();
    });

    it('arrastrar sobre la zona cambia su aspecto y salir lo restablece', () => {
        setup();
        const zone = screen.getByText('selecciona uno').closest('label');
        if (zone === null) throw new Error('No hay zona.');

        fireEvent.dragOver(zone);
        expect(zone.className).toContain('border-primary');

        fireEvent.dragLeave(zone);
        expect(zone.className).toContain('border-dashed');
    });

    it('mientras sube muestra el avance, lo anuncia por tramos y permite cancelar', async () => {
        const user = userEvent.setup();
        const { onCancel } = setup({ uploading: { name: 'logo.png', percent: 62 } });

        const bar = screen.getByRole('progressbar');

        expect(bar.getAttribute('aria-valuenow')).toBe('62');
        expect(screen.getByText('Subiendo logo.png…')).toBeTruthy();
        expect(screen.getByRole('status').textContent).toBe('50 % subido');

        await user.click(screen.getByRole('button', { name: 'Cancelar la subida' }));
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('muestra el error que devuelve el servidor', () => {
        setup({ error: 'La extensión del archivo no corresponde a lo que contiene.' });

        expect(screen.getByRole('alert').textContent).toContain('La extensión del archivo no corresponde');
    });
});
