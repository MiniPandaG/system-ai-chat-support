import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Checkbox } from './Checkbox';

describe('Checkbox', () => {
    it('es una casilla real con su etiqueta asociada', () => {
        render(<Checkbox label="Ya guardé mis códigos" />);

        const box = screen.getByRole('checkbox', { name: 'Ya guardé mis códigos' });
        expect(box.getAttribute('type')).toBe('checkbox');
    });

    it('al pulsar la etiqueta, no solo el cuadro, se marca y avisa', async () => {
        const user = userEvent.setup();
        let checked = false;
        render(
            <Checkbox
                label="Ya guardé mis códigos"
                onChange={(event) => {
                    checked = event.target.checked;
                }}
            />,
        );

        await user.click(screen.getByText('Ya guardé mis códigos'));

        expect(checked).toBe(true);
    });

    it('se marca con la barra espaciadora desde el teclado', async () => {
        const user = userEvent.setup();
        render(<Checkbox label="Ya guardé mis códigos" />);

        await user.tab();
        await user.keyboard(' ');

        expect(screen.getByRole<HTMLInputElement>('checkbox').checked).toBe(true);
    });

    it('deshabilitada no cambia', async () => {
        const user = userEvent.setup();
        render(<Checkbox label="Ya guardé mis códigos" disabled />);

        await user.click(screen.getByText('Ya guardé mis códigos'));

        expect(screen.getByRole<HTMLInputElement>('checkbox').checked).toBe(false);
    });
});
