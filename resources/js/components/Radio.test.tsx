import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Radio } from './Radio';

describe('Radio', () => {
    it('es un radio real asociado a su etiqueta', () => {
        render(<Radio label="Cliente nuevo" name="modo" value="new" onChange={vi.fn()} />);

        expect(screen.getByRole('radio', { name: 'Cliente nuevo' })).toBeTruthy();
    });

    it('un clic en la etiqueta lo marca, no solo en el círculo', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<Radio label="Cliente nuevo" name="modo" value="new" onChange={onChange} />);

        await user.click(screen.getByText('Cliente nuevo'));

        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it('las flechas cambian entre radios del mismo grupo', async () => {
        const user = userEvent.setup();
        render(
            <fieldset>
                <Radio label="Existente" name="modo" value="existing" defaultChecked />
                <Radio label="Nuevo" name="modo" value="new" />
            </fieldset>,
        );

        screen.getByRole('radio', { name: 'Existente' }).focus();
        await user.keyboard('{ArrowDown}');

        expect(screen.getByRole<HTMLInputElement>('radio', { name: 'Nuevo' }).checked).toBe(true);
    });

    it('deshabilitado no responde', async () => {
        const onChange = vi.fn();
        const user = userEvent.setup();
        render(<Radio label="Cliente nuevo" name="modo" value="new" disabled onChange={onChange} />);

        await user.click(screen.getByText('Cliente nuevo'));

        expect(onChange).not.toHaveBeenCalled();
    });
});
