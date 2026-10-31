import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { PasswordChangeForm } from './PasswordChangeForm';
import type { PasswordChangeCopy } from './PasswordChangeForm';

const put = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const react = await import('react');

    return {
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data, setData] = react.useState(initial);

            return {
                data,
                setData: (key: keyof T, value: string) => {
                    setData((current) => ({ ...current, [key]: value }));
                },
                put,
                processing: false,
                errors: {} as Record<string, string | undefined>,
                reset: vi.fn(),
            };
        },
    };
});

const copy: PasswordChangeCopy = {
    title: 'Contraseña',
    detail: 'Al cambiarla cerramos tus otras sesiones abiertas.',
    current: 'Contraseña actual',
    new: 'Contraseña nueva',
    confirmation: 'Confirmar contraseña nueva',
    show_password: 'Mostrar contraseña',
    hide_password: 'Ocultar contraseña',
    requirements: 'Mínimo 10 caracteres, una mayúscula y un número.',
    required_suffix: '(obligatorio)',
    required: 'Escribe tu :field.',
    mismatch: 'Las contraseñas no coinciden.',
    same: 'La contraseña nueva debe ser distinta de la actual.',
    summary: 'Revisa :count campos: :fields.',
    submit: 'Cambiar contraseña',
    submitting: 'Cambiando contraseña…',
    strength: {
        label: 'Seguridad de la contraseña',
        empty: 'Aún no escribes nada',
        weak: 'Débil',
        fair: 'Aceptable',
        strong: 'Fuerte',
        missing: 'Te falta: :items.',
        length: 'llegar a 10 caracteres',
        uppercase: 'una mayúscula',
        number: 'un número',
    },
};

const STRONG = 'Ab'.repeat(7) + '1';

async function fill(user: ReturnType<typeof userEvent.setup>, current: string, next: string, confirmation: string) {
    await user.type(screen.getByLabelText(/^Contraseña actual/), current);
    await user.type(screen.getByLabelText(/^Contraseña nueva/), next);
    await user.type(screen.getByLabelText(/^Confirmar contraseña nueva/), confirmation);
    await user.click(screen.getByRole('button', { name: 'Cambiar contraseña' }));
}

describe('PasswordChangeForm', () => {
    it('con el formulario vacío resume los errores, enfoca el primero y no envía nada', async () => {
        const user = userEvent.setup();
        put.mockClear();
        render(<PasswordChangeForm copy={copy} />);

        await user.click(screen.getByRole('button', { name: 'Cambiar contraseña' }));

        expect(screen.getByRole('alert').textContent).toContain('Revisa 3 campos');
        expect(document.activeElement).toBe(screen.getByLabelText(/^Contraseña actual/));
        expect(put).not.toHaveBeenCalled();
    });

    it('rechaza una contraseña nueva que no cumple los requisitos', async () => {
        const user = userEvent.setup();
        put.mockClear();
        render(<PasswordChangeForm copy={copy} />);

        await fill(user, 'Actual-Segura-81', 'corta', 'corta');

        expect(screen.getAllByText(copy.requirements).length).toBeGreaterThan(1);
        expect(put).not.toHaveBeenCalled();
    });

    it('rechaza una nueva contraseña igual a la actual', async () => {
        const user = userEvent.setup();
        put.mockClear();
        render(<PasswordChangeForm copy={copy} />);

        await fill(user, STRONG, STRONG, STRONG);

        expect(screen.getByText(copy.same)).toBeTruthy();
        expect(put).not.toHaveBeenCalled();
    });

    it('rechaza una confirmación distinta', async () => {
        const user = userEvent.setup();
        put.mockClear();
        render(<PasswordChangeForm copy={copy} />);

        await fill(user, 'Actual-Segura-81', STRONG, STRONG + 'x');

        expect(screen.getByText(copy.mismatch)).toBeTruthy();
        expect(put).not.toHaveBeenCalled();
    });

    it('con datos válidos envía el cambio a la ruta de contraseña', async () => {
        const user = userEvent.setup();
        put.mockClear();
        render(<PasswordChangeForm copy={copy} />);

        await fill(user, 'Actual-Segura-81', STRONG, STRONG);

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0]?.[0]).toBe('/settings/password');
    });
});
