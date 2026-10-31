import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ColorField } from './ColorField';

type Status = Parameters<typeof ColorField>[0]['status'];

function setup(status: Status = null, value = '#F65F1E') {
    const onChange = vi.fn();
    render(
        <ColorField
            label="Primario"
            help="Botones de acción y foco."
            pickerLabel="Selector de color de :label"
            value={value}
            onChange={onChange}
            status={status}
            useSuggestion="Usar este tono (:hex)"
        />,
    );

    return { onChange };
}

describe('ColorField', () => {
    it('tiene una etiqueta de texto además del cuadro de color, y los dos controles', () => {
        setup();

        expect(screen.getByLabelText<HTMLInputElement>('Primario').value).toBe('#F65F1E');
        expect(screen.getByLabelText<HTMLInputElement>('Selector de color de Primario').type).toBe('color');
        expect(screen.getByText('Botones de acción y foco.')).toBeTruthy();
    });

    it('escribir el hexadecimal a mano avisa del cambio', async () => {
        const user = userEvent.setup();
        const { onChange } = setup(null, '');

        await user.type(screen.getByLabelText('Primario'), '#2164DA');

        expect(onChange).toHaveBeenLastCalledWith('A');
        expect(onChange).toHaveBeenCalledTimes(7);
    });

    it('con una revisión correcta muestra una marca, sin texto adicional', () => {
        setup({ kind: 'ok' });

        expect(screen.getByRole('status').textContent).toBe('');
        expect(document.querySelector('svg')).not.toBeNull();
    });

    it('con un problema muestra la explicación asociada al campo y se anuncia', () => {
        setup({ kind: 'issue', message: 'Este color no da suficiente contraste: 4,1:1 (mínimo 4,5:1).', suggestion: null });

        const input = screen.getByLabelText('Primario');
        const message = screen.getByRole('status');

        expect(message.textContent).toContain('4,1:1');
        expect(input.getAttribute('aria-describedby')).toBe(message.id);
        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('ofrece usar el tono propuesto y lo aplica con un clic', async () => {
        const user = userEvent.setup();
        const { onChange } = setup({ kind: 'issue', message: 'Poco contraste.', suggestion: '#2164DA' });

        await user.click(screen.getByRole('button', { name: 'Usar este tono (#2164DA)' }));

        expect(onChange).toHaveBeenCalledWith('#2164DA');
    });

    it('un hexadecimal mal escrito muestra el formato esperado y no ofrece tono', () => {
        setup({ kind: 'format', message: 'Escribe un color hexadecimal de 6 dígitos.' }, '#F65');

        expect(screen.getByRole('status').textContent).toContain('hexadecimal de 6 dígitos');
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('el selector nativo recibe un color válido aunque el texto aún esté a medias', () => {
        setup(null, '#F6');

        expect(screen.getByLabelText<HTMLInputElement>('Selector de color de Primario').value).toBe('#000000');
    });
});
