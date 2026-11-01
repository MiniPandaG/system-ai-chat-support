import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { internalNote, ticketsCopy } from '../test-fixtures/tickets';
import { NoteBubble } from './NoteBubble';

describe('NoteBubble', () => {
    it('es un artículo con autor y hora en texto accesible', () => {
        render(<NoteBubble note={internalNote} copy={ticketsCopy.note} />);

        expect(screen.getByRole('article', { name: 'Nota interna de Luis Pérez, 31 de oct, 3:03 pm' })).toBeTruthy();
    });

    it('lleva la leyenda de que el cliente no la ve, con el color propio de las notas', () => {
        const { container } = render(<NoteBubble note={internalNote} copy={ticketsCopy.note} />);

        expect(screen.getByText('Nota interna — el cliente no la ve')).toBeTruthy();
        expect(container.querySelector('.bg-bubble-nota-interna')).not.toBeNull();
        expect(container.querySelector('svg')).not.toBeNull();
    });

    it('ocupa el ancho completo, sin alinearse a un lado como las burbujas', () => {
        const { container } = render(<NoteBubble note={internalNote} copy={ticketsCopy.note} />);

        expect(container.querySelector('article')?.className).not.toMatch(/items-(start|end)/);
    });

    it('un texto con HTML se muestra como texto, nunca como elementos', () => {
        const hostile = '<img src=x onerror="alert(1)"><script>alert(2)</script>';
        const { container } = render(<NoteBubble note={{ ...internalNote, body: hostile }} copy={ticketsCopy.note} />);

        expect(container.querySelector('img, script')).toBeNull();
        expect(container.querySelector('.whitespace-pre-wrap')?.textContent).toBe(hostile);
    });

    it('respeta los saltos de línea', () => {
        const { container } = render(<NoteBubble note={{ ...internalNote, body: 'Uno\nDos' }} copy={ticketsCopy.note} />);

        expect(container.querySelector('.whitespace-pre-wrap')?.textContent).toBe('Uno\nDos');
    });
});
