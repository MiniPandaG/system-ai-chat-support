import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PasswordStrength } from './PasswordStrength';

const copy = {
    label: 'Seguridad de la contraseña',
    empty: 'Aún no escribes nada',
    weak: 'Débil',
    fair: 'Aceptable',
    strong: 'Fuerte',
    missing: 'Te falta: :items.',
    length: 'llegar a 10 caracteres',
    uppercase: 'una mayúscula',
    number: 'un número',
};

describe('PasswordStrength', () => {
    it('anuncia el estado con texto y lo marca como región de estado', () => {
        render(<PasswordStrength password="" copy={copy} />);

        const status = screen.getByRole('status');
        expect(status.textContent).toContain('Seguridad de la contraseña');
        expect(status.textContent).toContain('Aún no escribes nada');
        expect(status.getAttribute('aria-live')).toBe('polite');
    });

    it('lista lo que falta, en orden, mientras se escribe', () => {
        render(<PasswordStrength password="corta" copy={copy} />);

        expect(screen.getByRole('status').textContent).toContain(
            'Débil. Te falta: llegar a 10 caracteres, una mayúscula, un número.',
        );
    });

    it('al cumplir los requisitos ya no pide nada', () => {
        render(<PasswordStrength password="Abcdefghi1" copy={copy} />);

        const text = screen.getByRole('status').textContent;
        expect(text).toContain('Aceptable');
        expect(text).not.toContain('Te falta');
    });

    it('la barra de colores es decorativa para los lectores de pantalla', () => {
        // Se arma con repeat para no dejar en el código una cadena con aspecto de contraseña real.
        const strong = `${'Ab'.repeat(7)}1`;
        const { container } = render(<PasswordStrength password={strong} copy={copy} />);

        const bar = container.querySelector('[aria-hidden="true"]');
        expect(bar).not.toBeNull();
        expect(bar?.querySelectorAll('.bg-success').length).toBe(3);
    });
});
