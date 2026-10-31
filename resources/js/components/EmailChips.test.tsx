import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { EmailChips } from './EmailChips';

function Harness({ initial = [], error }: { initial?: string[]; error?: string }) {
    const [emails, setEmails] = useState<string[]>(initial);

    return (
        <EmailChips
            label="Correos"
            hint="Sepáralos con coma, espacio o Enter."
            emails={emails}
            onChange={setEmails}
            error={error}
            removeLabel="Quitar :email"
            invalidLabel=":email no es un correo válido."
        />
    );
}

describe('EmailChips', () => {
    it('convierte en insignias los correos separados por coma, espacio o Enter', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.type(screen.getByRole('textbox', { name: 'Correos' }), 'uno@empresa-ejemplo.com,dos@empresa-ejemplo.com{Enter}tres@empresa-ejemplo.com ');

        expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual([
            'uno@empresa-ejemplo.com',
            'dos@empresa-ejemplo.com',
            'tres@empresa-ejemplo.com',
        ]);
    });

    it('pasa a minúsculas y no repite correos', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.type(screen.getByRole('textbox', { name: 'Correos' }), 'Ana@Empresa-Ejemplo.com,ana@empresa-ejemplo.com,');

        expect(screen.getAllByRole('listitem')).toHaveLength(1);
        expect(screen.getByText('ana@empresa-ejemplo.com')).toBeTruthy();
    });

    it('lo que no es un correo se queda escrito y se avisa, sin crear una insignia', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        const input = screen.getByRole<HTMLInputElement>('textbox', { name: 'Correos' });
        await user.type(input, 'no-es-correo,');

        expect(screen.queryAllByRole('listitem')).toHaveLength(0);
        expect(input.value).toBe('no-es-correo');
        expect(screen.getByRole('alert').textContent).toBe('no-es-correo no es un correo válido.');
        expect(input.getAttribute('aria-invalid')).toBe('true');
    });

    it('pegar varios correos los separa de una vez', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.click(screen.getByRole('textbox', { name: 'Correos' }));
        await user.paste('a@empresa-ejemplo.com\nb@empresa-ejemplo.com; c@empresa-ejemplo.com');

        expect(screen.getAllByRole('listitem')).toHaveLength(3);
    });

    it('cada insignia se quita con su botón, que dice a quién quita', async () => {
        const user = userEvent.setup();
        render(<Harness initial={['uno@empresa-ejemplo.com', 'dos@empresa-ejemplo.com']} />);

        await user.click(screen.getByRole('button', { name: 'Quitar uno@empresa-ejemplo.com' }));

        expect(screen.getAllByRole('listitem').map((item) => item.textContent)).toEqual(['dos@empresa-ejemplo.com']);
    });

    it('Retroceso con el campo vacío quita la última insignia', async () => {
        const user = userEvent.setup();
        render(<Harness initial={['uno@empresa-ejemplo.com', 'dos@empresa-ejemplo.com']} />);

        await user.click(screen.getByRole('textbox', { name: 'Correos' }));
        await user.keyboard('{Backspace}');

        expect(screen.getAllByRole('listitem')).toHaveLength(1);
    });

    it('un correo escrito se convierte en insignia al salir del campo', async () => {
        const user = userEvent.setup();
        render(<Harness />);

        await user.type(screen.getByRole('textbox', { name: 'Correos' }), 'solo@empresa-ejemplo.com');
        await user.tab();

        expect(screen.getAllByRole('listitem')).toHaveLength(1);
    });

    it('muestra el error del servidor asociado al campo', () => {
        render(<Harness error="Ya forman parte del equipo: ana@empresa-ejemplo.com." />);

        expect(screen.getByRole('alert').textContent).toContain('Ya forman parte del equipo');
        expect(screen.getByRole('textbox', { name: 'Correos' }).getAttribute('aria-describedby')).toContain('error');
    });
});
