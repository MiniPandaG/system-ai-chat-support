import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import type { AttachmentData } from '../ticketTypes';
import { AttachmentList } from './AttachmentList';

const copy = ticketsCopy.attachments;

const clean: AttachmentData = { id: 'a1', name: 'Contrato final.pdf', size: 340 * 1024, status: 'clean', url: 'https://app.test/attachments/a1?signature=abc' };
const pending: AttachmentData = { id: 'a2', name: 'foto.png', size: 1200, status: 'pending', url: null };
const infected: AttachmentData = { id: 'a3', name: 'virus.txt', size: 100, status: 'infected', url: null };
const failed: AttachmentData = { id: 'a4', name: 'raro.csv', size: 2048, status: 'failed', url: null };

describe('AttachmentList', () => {
    it('sin archivos no pinta nada', () => {
        const { container } = render(<AttachmentList attachments={[]} copy={copy} />);

        expect(container.firstChild).toBeNull();
    });

    it('es una lista con nombre y se anuncia cuando un estado cambia', () => {
        render(<AttachmentList attachments={[clean]} copy={copy} />);

        const list = screen.getByRole('list', { name: 'Archivos adjuntos' });

        expect(list.getAttribute('aria-live')).toBe('polite');
    });

    it('un archivo limpio es un enlace de descarga con su peso en el nombre accesible', () => {
        render(<AttachmentList attachments={[clean]} copy={copy} />);

        const link = screen.getByRole('link', { name: 'Descargar Contrato final.pdf (340 KB)' });

        expect(link.getAttribute('href')).toBe(clean.url);
        expect(link.getAttribute('download')).toBe('Contrato final.pdf');
        expect(link.textContent).toBe('Contrato final.pdf');
    });

    it('uno por revisar no es un enlace y dice por qué', () => {
        render(<AttachmentList attachments={[pending]} copy={copy} />);

        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getByText('foto.png')).toBeTruthy();
        expect(screen.getByText('1 KB · Revisando con el antivirus…')).toBeTruthy();
    });

    it('uno bloqueado lo dice con palabras y en color de peligro', () => {
        render(<AttachmentList attachments={[infected]} copy={copy} />);

        const status = screen.getByText('100 B · Bloqueado: contenía una amenaza');

        expect(screen.queryByRole('link')).toBeNull();
        expect(status.className).toContain('text-danger');
    });

    it('uno que no se pudo revisar tampoco se puede descargar', () => {
        render(<AttachmentList attachments={[failed]} copy={copy} />);

        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getByText('2 KB · No se pudo revisar, así que no se puede descargar')).toBeTruthy();
    });

    it('un archivo sin enlace nunca se vuelve un enlace por tener otro estado', () => {
        render(<AttachmentList attachments={[pending, infected, failed, clean]} copy={copy} />);

        expect(screen.getAllByRole('link')).toHaveLength(1);
        expect(within(screen.getByRole('list')).getAllByRole('listitem')).toHaveLength(4);
    });

    it('el nombre es siempre texto, nunca HTML', () => {
        const hostile: AttachmentData = { ...clean, id: 'h', name: '<img src=x onerror=alert(1)>.pdf' };
        const { container } = render(<AttachmentList attachments={[hostile]} copy={copy} />);

        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('<img src=x onerror=alert(1)>.pdf')).toBeTruthy();
    });
});
