import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { TextField } from './TextField';

describe('TextField', () => {
    it('la etiqueta está asociada al campo', () => {
        render(<TextField label="Correo (obligatorio)" name="email" />);

        expect(screen.getByLabelText('Correo (obligatorio)').tagName).toBe('INPUT');
    });

    it('el error se marca como inválido y se une al campo con aria-describedby', () => {
        render(<TextField label="Correo" name="email" error="Escribe un correo válido." />);

        const input = screen.getByLabelText('Correo');
        const message = screen.getByText('Escribe un correo válido.');

        expect(input.getAttribute('aria-invalid')).toBe('true');
        expect(input.getAttribute('aria-describedby')).toContain(message.id);
    });

    it('el texto de ayuda queda unido al campo incluso sin error', () => {
        render(<TextField label="Contraseña" name="password" help="Mínimo 10 caracteres." />);

        const input = screen.getByLabelText('Contraseña');
        const help = screen.getByText('Mínimo 10 caracteres.');

        expect(input.getAttribute('aria-describedby')).toBe(help.id);
        expect(input.getAttribute('aria-invalid')).toBeNull();
    });

    it('el botón de mostrar contraseña cambia el tipo, el nombre accesible y el estado', async () => {
        const user = userEvent.setup();
        render(<TextField label="Contraseña" name="password" type="password" showLabel="Mostrar contraseña" hideLabel="Ocultar contraseña" />);

        const input = screen.getByLabelText('Contraseña');
        expect(input.getAttribute('type')).toBe('password');

        await user.click(screen.getByRole('button', { name: 'Mostrar contraseña' }));
        expect(input.getAttribute('type')).toBe('text');

        const hide = screen.getByRole('button', { name: 'Ocultar contraseña' });
        expect(hide.getAttribute('aria-pressed')).toBe('true');

        await user.click(hide);
        expect(input.getAttribute('type')).toBe('password');
    });

    it('el botón de mostrar contraseña se alcanza con el teclado y no envía el formulario', async () => {
        const user = userEvent.setup();
        let submitted = false;
        render(
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submitted = true;
                }}
            >
                <TextField label="Contraseña" name="password" type="password" showLabel="Mostrar contraseña" hideLabel="Ocultar contraseña" />
            </form>,
        );

        await user.tab();
        await user.tab();
        expect(document.activeElement?.getAttribute('aria-label')).toBe('Mostrar contraseña');

        await user.keyboard('{Enter}');
        expect(submitted).toBe(false);
    });
});
