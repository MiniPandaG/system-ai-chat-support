import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { TextAreaField } from './TextAreaField';

describe('TextAreaField', () => {
    it('tiene su etiqueta visible asociada', () => {
        render(<TextAreaField label="Descripción" value="" onChange={vi.fn()} />);

        expect(screen.getByLabelText('Descripción').tagName).toBe('TEXTAREA');
    });

    it('la ayuda y el error se asocian con aria-describedby y el error marca el campo inválido', () => {
        render(<TextAreaField label="Descripción" help="Lo que cuenta el cliente." error="Describe lo que cuenta el cliente." value="" onChange={vi.fn()} />);
        const field = screen.getByLabelText('Descripción');
        const described = (field.getAttribute('aria-describedby') ?? '').split(' ');

        expect(field.getAttribute('aria-invalid')).toBe('true');
        expect(described).toHaveLength(2);
        expect(document.getElementById(described[0] ?? '')?.textContent).toBe('Lo que cuenta el cliente.');
        expect(document.getElementById(described[1] ?? '')?.textContent).toBe('Describe lo que cuenta el cliente.');
    });

    it('el contador solo pasa a color de peligro al exceder el máximo', () => {
        const { rerender } = render(<TextAreaField label="Descripción" maxLength={5} value="abcde" onChange={vi.fn()} />);
        expect(screen.getByText('5 / 5').className).toContain('text-text-tertiary');

        rerender(<TextAreaField label="Descripción" maxLength={5} value="abcdef" onChange={vi.fn()} />);
        expect(screen.getByText('6 / 5').className).toContain('text-danger');
    });

    it('sin máximo no hay contador', () => {
        render(<TextAreaField label="Descripción" value="abc" onChange={vi.fn()} />);

        expect(screen.queryByText(/\/ /)).toBeNull();
    });

    it('entrega lo que se escribe', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<TextAreaField label="Descripción" value="" onChange={onChange} />);

        await user.type(screen.getByLabelText('Descripción'), 'a');

        expect(onChange).toHaveBeenCalled();
    });

    it('deshabilitado no se puede escribir', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<TextAreaField label="Descripción" value="" disabled onChange={onChange} />);

        await user.type(screen.getByLabelText('Descripción'), 'a');

        expect(onChange).not.toHaveBeenCalled();
    });
});
