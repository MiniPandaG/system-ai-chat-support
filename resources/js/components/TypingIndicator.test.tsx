import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { TypingIndicator } from './TypingIndicator';

function status(): HTMLElement {
    return screen.getByRole('status');
}

describe('TypingIndicator', () => {
    it('sin nadie escribiendo la región existe pero está vacía, para que el compositor no salte', () => {
        render(<TypingIndicator people={[]} copy={ticketsCopy.typing} />);

        expect(status().textContent).toBe('');
        expect(status().getAttribute('aria-live')).toBe('polite');
    });

    it('una persona', () => {
        render(<TypingIndicator people={['Ana Torres']} copy={ticketsCopy.typing} />);

        expect(status().textContent).toBe('Ana Torres está escribiendo…');
    });

    it('dos personas', () => {
        render(<TypingIndicator people={['Ana', 'Luis']} copy={ticketsCopy.typing} />);

        expect(status().textContent).toBe('Ana y Luis están escribiendo…');
    });

    it('tres o más', () => {
        render(<TypingIndicator people={['Ana', 'Luis', 'Pedro']} copy={ticketsCopy.typing} />);

        expect(status().textContent).toBe('Varias personas están escribiendo…');
    });

    it('si no se conoce el nombre no inventa uno', () => {
        render(<TypingIndicator people={[null]} copy={ticketsCopy.typing} />);

        expect(status().textContent).toBe('Alguien está escribiendo…');
    });

    it('dos personas de las que una no se conoce se resumen sin nombres', () => {
        render(<TypingIndicator people={['Ana', null]} copy={ticketsCopy.typing} />);

        expect(status().textContent).toBe('Varias personas están escribiendo…');
    });

    it('el nombre es texto: no se interpreta como HTML', () => {
        const { container } = render(<TypingIndicator people={['<b>Ana</b>']} copy={ticketsCopy.typing} />);

        expect(container.querySelector('b')).toBeNull();
        expect(status().textContent).toContain('<b>Ana</b>');
    });
});
