import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { OutgoingBubble } from './OutgoingBubble';

describe('OutgoingBubble', () => {
    it('una respuesta se ve atenuada, a la derecha y con el texto como texto', () => {
        const { container } = render(<OutgoingBubble outgoing={{ kind: 'reply', body: '<b>Hola</b>', files: 0 }} copy={ticketsCopy.composer} />);

        const article = container.querySelector('article');
        expect(article?.getAttribute('aria-busy')).toBe('true');
        expect(article?.className).toContain('items-end');
        expect(article?.className).toContain('opacity-70');
        expect(screen.getByText('<b>Hola</b>')).toBeTruthy();
        expect(container.querySelector('b')).toBeNull();
        expect(screen.getByText('Enviando…')).toBeTruthy();
    });

    it('una nota conserva el aspecto de nota interna', () => {
        const { container } = render(<OutgoingBubble outgoing={{ kind: 'note', body: 'Para el equipo', files: 0 }} copy={ticketsCopy.composer} />);

        expect(container.querySelector('.bg-bubble-nota-interna')).not.toBeNull();
        expect(screen.getByText('Para el equipo')).toBeTruthy();
    });

    it('si lleva archivos dice cuántos, y un mensaje solo de archivos no pinta una burbuja vacía', () => {
        const { container } = render(<OutgoingBubble outgoing={{ kind: 'reply', body: '', files: 2 }} copy={ticketsCopy.composer} />);

        expect(screen.getByText('2 archivos adjuntos')).toBeTruthy();
        expect(container.querySelector('.bg-bubble-agente')).toBeNull();
    });
});
