import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ticketsCopy } from '../test-fixtures/tickets';
import { SeenBy } from './SeenBy';

describe('SeenBy', () => {
    it('no muestra nada si nadie lo vio', () => {
        const { container } = render(<SeenBy names={[]} more={0} copy={ticketsCopy.seen} />);

        expect(container.textContent).toBe('');
    });

    it('una persona', () => {
        render(<SeenBy names={['Ana']} more={0} copy={ticketsCopy.seen} />);

        expect(screen.getByText('Visto por Ana')).toBeTruthy();
    });

    it('varias personas en una frase natural', () => {
        render(<SeenBy names={['Ana', 'Luis', 'Pedro']} more={0} copy={ticketsCopy.seen} />);

        expect(screen.getByText('Visto por Ana, Luis y Pedro')).toBeTruthy();
    });

    it('cuenta las que no caben', () => {
        render(<SeenBy names={['Ana', 'Luis']} more={3} copy={ticketsCopy.seen} />);

        expect(screen.getByText('Visto por Ana y Luis y 3 más')).toBeTruthy();
    });

    it('el nombre es texto: no se interpreta como HTML', () => {
        const { container } = render(<SeenBy names={['<i>Ana</i>']} more={0} copy={ticketsCopy.seen} />);

        expect(container.querySelector('i')).toBeNull();
        expect(container.textContent).toContain('<i>Ana</i>');
    });
});
