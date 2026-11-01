import { describe, expect, it } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { MAX_BYTES, MAX_FILES, addFiles, formatBytes } from './attachments';

const copy = ticketsCopy.attachments;

function file(name: string, size = 100, modified = 1): File {
    const content = new File(['x'], name, { lastModified: modified });
    Object.defineProperty(content, 'size', { value: size });

    return content;
}

describe('formatBytes', () => {
    it('usa la unidad que toca', () => {
        expect(formatBytes(0)).toBe('0 B');
        expect(formatBytes(900)).toBe('900 B');
        expect(formatBytes(1024)).toBe('1 KB');
        expect(formatBytes(340 * 1024)).toBe('340 KB');
        expect(formatBytes(1.5 * 1024 * 1024)).toBe('1,5 MB');
        expect(formatBytes(MAX_BYTES)).toBe('10,0 MB');
    });
});

describe('addFiles', () => {
    it('añade los archivos válidos en el orden en que llegan', () => {
        const result = addFiles([], [file('a.pdf'), file('b.png'), file('c.DOCX')], copy);

        expect(result.files.map((item) => item.name)).toEqual(['a.pdf', 'b.png', 'c.DOCX']);
        expect(result.errors).toEqual([]);
    });

    it('acepta las nueve extensiones de la lista, en mayúsculas o minúsculas', () => {
        const names = ['a.png', 'a.jpg', 'a.jpeg', 'a.webp', 'a.pdf', 'a.txt', 'a.csv', 'a.docx', 'a.xlsx', 'A.PDF'];

        expect(addFiles([], names.slice(0, 5).map((name) => file(name)), copy).errors).toEqual([]);
        expect(addFiles([], names.slice(5).map((name, index) => file(name, 100, index + 10)), copy).files.length).toBeGreaterThan(0);
    });

    it('descarta con su motivo lo que el servidor rechazaría', () => {
        const result = addFiles([], [file('programa.exe'), file('script.sh'), file('sin-extension'), file('doc.pdf.exe'), file('vacio.txt', 0)], copy);

        expect(result.files).toEqual([]);
        expect(result.errors).toEqual([
            '«programa.exe» no se puede adjuntar: usa PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX.',
            '«script.sh» no se puede adjuntar: usa PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX.',
            '«sin-extension» no se puede adjuntar: usa PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX.',
            '«doc.pdf.exe» no se puede adjuntar: usa PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX.',
            '«vacio.txt» está vacío.',
        ]);
    });

    it('un archivo de exactamente 10 MB entra; uno que pesa un byte más, no, y dice cuánto pesa', () => {
        expect(addFiles([], [file('justo.pdf', MAX_BYTES)], copy).files).toHaveLength(1);

        const result = addFiles([], [file('grande.pdf', MAX_BYTES + 1)], copy);

        expect(result.files).toEqual([]);
        expect(result.errors).toEqual(['«grande.pdf» pesa 10,0 MB; el máximo es 10 MB.']);
    });

    it('admite hasta cinco archivos y avisa una sola vez del resto', () => {
        const incoming = Array.from({ length: 8 }, (_, index) => file(`doc${String(index)}.pdf`, 100, index));

        const result = addFiles([], incoming, copy);

        expect(result.files).toHaveLength(MAX_FILES);
        expect(result.errors).toEqual(['Un mensaje admite hasta 5 archivos.']);
    });

    it('cuenta los que ya estaban elegidos', () => {
        const current = Array.from({ length: 4 }, (_, index) => file(`ya${String(index)}.pdf`, 100, index));

        const result = addFiles(current, [file('nuevo1.pdf', 100, 50), file('nuevo2.pdf', 100, 51)], copy);

        expect(result.files).toHaveLength(5);
        expect(result.errors).toEqual(['Un mensaje admite hasta 5 archivos.']);
    });

    it('no repite el mismo archivo elegido dos veces', () => {
        const same = file('a.pdf', 100, 7);

        const result = addFiles([same], [file('a.pdf', 100, 7)], copy);

        expect(result.files).toHaveLength(1);
        expect(result.errors).toEqual([]);
    });

    it('dos archivos con el mismo nombre pero distinto contenido sí se admiten', () => {
        const result = addFiles([file('a.pdf', 100, 1)], [file('a.pdf', 200, 2)], copy);

        expect(result.files).toHaveLength(2);
    });

    it('lo bueno se añade aunque haya algo malo en el mismo grupo', () => {
        const result = addFiles([], [file('bueno.pdf'), file('malo.exe'), file('otro.png', 100, 2)], copy);

        expect(result.files.map((item) => item.name)).toEqual(['bueno.pdf', 'otro.png']);
        expect(result.errors).toHaveLength(1);
    });

    it('no cambia la lista que recibe', () => {
        const current = [file('a.pdf')];

        addFiles(current, [file('b.pdf', 100, 2)], copy);

        expect(current).toHaveLength(1);
    });
});
