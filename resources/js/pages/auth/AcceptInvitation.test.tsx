import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AcceptInvitation from './AcceptInvitation';

const post = vi.fn();
let serverErrors: Record<string, string | undefined> = {};

vi.mock('@inertiajs/react', async () => {
    const react = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        usePage: () => ({ props: { errors: serverErrors } }),
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data, setData] = react.useState(initial);

            return {
                data,
                setData: (key: keyof T, value: string) => {
                    setData((current) => ({ ...current, [key]: value }));
                },
                post,
                processing: false,
                errors: {} as Record<string, string | undefined>,
                reset: vi.fn(),
            };
        },
    };
});

const copy = {
    title: 'Únete a tu equipo',
    detail: ':company te invitó como :role. Elige tu nombre y una contraseña.',
    email: 'Correo',
    name: 'Nombre completo',
    password: 'Contraseña',
    confirmation: 'Confirmar contraseña',
    show_password: 'Mostrar contraseña',
    hide_password: 'Ocultar contraseña',
    requirements: 'Mínimo 10 caracteres, una mayúscula y un número.',
    required_suffix: '(obligatorio)',
    required: 'Escribe tu :field.',
    mismatch: 'Las contraseñas no coinciden.',
    summary: 'Revisa :count campos: :fields.',
    submit: 'Crear mi cuenta',
    submitting: 'Creando tu cuenta…',
    invalid: 'Esta invitación no es válida o ya caducó.',
    invalid_title: 'No pudimos abrir la invitación',
    login: 'Ir a iniciar sesión',
    roles: { admin: 'administrador', agent: 'agente', observer: 'observador' },
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

const invitation = { email: 'nueva@empresa-ejemplo.com', role: 'agent', company: 'Soporte Ejemplo SAS' };
const STRONG = 'Ab'.repeat(7) + '1';

describe('AcceptInvitation', () => {
    beforeEach(() => {
        post.mockClear();
        serverErrors = {};
    });

    it('muestra la empresa y el rol, con el correo de la invitación solo de lectura', () => {
        render(<AcceptInvitation copy={copy} token="tok" invitation={invitation} />);

        expect(screen.getByText('Soporte Ejemplo SAS te invitó como agente. Elige tu nombre y una contraseña.')).toBeTruthy();

        const email = screen.getByLabelText<HTMLInputElement>('Correo');

        expect(email.value).toBe('nueva@empresa-ejemplo.com');
        expect(email.readOnly).toBe(true);
    });

    it('una invitación inválida muestra solo el aviso genérico y el camino a iniciar sesión', () => {
        render(<AcceptInvitation copy={copy} token="tok" invitation={null} />);

        expect(screen.getByRole('alert').textContent).toBe('Esta invitación no es válida o ya caducó.');
        expect(screen.getByRole('link', { name: 'Ir a iniciar sesión' }).getAttribute('href')).toBe('/login');
        expect(screen.queryByLabelText('Nombre completo (obligatorio)')).toBeNull();
    });

    it('con el formulario vacío resume los errores y no envía nada', async () => {
        const user = userEvent.setup();
        render(<AcceptInvitation copy={copy} token="tok" invitation={invitation} />);

        await user.click(screen.getByRole('button', { name: 'Crear mi cuenta' }));

        expect(screen.getByRole('alert').textContent).toContain('Revisa 3 campos');
        expect(post).not.toHaveBeenCalled();
    });

    it('rechaza una confirmación distinta', async () => {
        const user = userEvent.setup();
        render(<AcceptInvitation copy={copy} token="tok" invitation={invitation} />);

        await user.type(screen.getByLabelText(/^Nombre completo/), 'Luis Pérez');
        await user.type(screen.getByLabelText(/^Contraseña \(obligatorio\)/), STRONG);
        await user.type(screen.getByLabelText(/^Confirmar contraseña/), STRONG + 'x');
        await user.click(screen.getByRole('button', { name: 'Crear mi cuenta' }));

        expect(screen.getByText(copy.mismatch)).toBeTruthy();
        expect(post).not.toHaveBeenCalled();
    });

    it('con datos válidos envía la aceptación a la ruta del token', async () => {
        const user = userEvent.setup();
        render(<AcceptInvitation copy={copy} token="tok" invitation={invitation} />);

        await user.type(screen.getByLabelText(/^Nombre completo/), 'Luis Pérez');
        await user.type(screen.getByLabelText(/^Contraseña \(obligatorio\)/), STRONG);
        await user.type(screen.getByLabelText(/^Confirmar contraseña/), STRONG);
        await user.click(screen.getByRole('button', { name: 'Crear mi cuenta' }));

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0]?.[0]).toBe('/invitations/tok');
    });

    it('muestra el error del token que devuelve el servidor', () => {
        serverErrors = { token: 'Este correo ya tiene una cuenta en GeckoChat.' };
        render(<AcceptInvitation copy={copy} token="tok" invitation={invitation} />);

        expect(screen.getByText('Este correo ya tiene una cuenta en GeckoChat.')).toBeTruthy();
    });
});
