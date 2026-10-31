import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Register from './Register';

const post = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const react = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
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
    title: 'Registra tu empresa',
    steps_label: 'Pasos del registro',
    step_account: 'Cuenta',
    step_company: 'Empresa',
    step_status: 'Paso :current de :total: :name',
    account_detail: 'Serás el administrador de tu empresa.',
    company_detail: 'Este es el nombre que verá tu equipo.',
    name: 'Nombre completo',
    email: 'Correo',
    password: 'Contraseña',
    confirmation: 'Confirmar contraseña',
    company: 'Nombre de la empresa',
    show_password: 'Mostrar contraseña',
    hide_password: 'Ocultar contraseña',
    requirements: 'Mínimo 10 caracteres, una mayúscula y un número.',
    required_suffix: '(obligatorio)',
    required: 'Escribe tu :field.',
    company_required: 'Escribe el nombre de tu empresa.',
    invalid_email: 'Escribe un correo válido, por ejemplo nombre@empresa.com.',
    mismatch: 'Las contraseñas no coinciden.',
    summary: 'Revisa :count campos: :fields.',
    continue: 'Continuar',
    back: 'Volver',
    submit: 'Crear mi empresa',
    submitting: 'Creando tu empresa…',
    have_account: '¿Ya tienes cuenta?',
    login: 'Inicia sesión',
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

type User = ReturnType<typeof userEvent.setup>;

async function fillAccount(user: User, overrides: { email?: string; confirmation?: string } = {}) {
    await user.type(screen.getByLabelText(/^Nombre completo/), 'Ana Torres');
    await user.type(screen.getByLabelText(/^Correo/), overrides.email ?? 'ana@empresa-ejemplo.com');
    await user.type(screen.getByLabelText(/^Contraseña \(obligatorio\)/), STRONG);
    await user.type(screen.getByLabelText(/^Confirmar contraseña/), overrides.confirmation ?? STRONG);
}

describe('Register', () => {
    beforeEach(() => {
        post.mockClear();
    });

    it('empieza en el paso de la cuenta, con el paso actual marcado', () => {
        render(<Register copy={copy} />);

        const items = screen.getAllByRole('listitem');

        expect(items[0]?.getAttribute('aria-current')).toBe('step');
        expect(screen.getByLabelText(/^Nombre completo/)).toBeTruthy();
        expect(screen.queryByLabelText(/^Nombre de la empresa/)).toBeNull();
    });

    it('con el paso vacío resume los errores, enfoca el primero y no avanza', async () => {
        const user = userEvent.setup();
        render(<Register copy={copy} />);

        await user.click(screen.getByRole('button', { name: 'Continuar' }));

        expect(screen.getByRole('alert').textContent).toContain('Revisa 4 campos');
        expect(document.activeElement).toBe(screen.getByLabelText(/^Nombre completo/));
        expect(screen.queryByLabelText(/^Nombre de la empresa/)).toBeNull();
    });

    it('rechaza un correo inválido y una confirmación distinta', async () => {
        const user = userEvent.setup();
        render(<Register copy={copy} />);

        await fillAccount(user, { email: 'no-es-correo', confirmation: STRONG + 'x' });
        await user.click(screen.getByRole('button', { name: 'Continuar' }));

        expect(screen.getByText(copy.invalid_email)).toBeTruthy();
        expect(screen.getByText(copy.mismatch)).toBeTruthy();
        expect(screen.queryByLabelText(/^Nombre de la empresa/)).toBeNull();
    });

    it('con la cuenta válida pasa al paso de la empresa, anuncia el cambio y enfoca su campo', async () => {
        const user = userEvent.setup();
        render(<Register copy={copy} />);

        await fillAccount(user);
        await user.click(screen.getByRole('button', { name: 'Continuar' }));

        expect(screen.getAllByRole('listitem')[1]?.getAttribute('aria-current')).toBe('step');
        expect(screen.getByRole('status').textContent).toBe('Paso 2 de 2: Empresa');
        expect(document.activeElement).toBe(screen.getByLabelText(/^Nombre de la empresa/));
    });

    it('volver conserva lo escrito en el primer paso', async () => {
        const user = userEvent.setup();
        render(<Register copy={copy} />);

        await fillAccount(user);
        await user.click(screen.getByRole('button', { name: 'Continuar' }));
        await user.click(screen.getByRole('button', { name: 'Volver' }));

        expect(screen.getByLabelText<HTMLInputElement>(/^Nombre completo/).value).toBe('Ana Torres');
        expect(screen.getByLabelText<HTMLInputElement>(/^Correo/).value).toBe('ana@empresa-ejemplo.com');
    });

    it('sin nombre de empresa no envía nada', async () => {
        const user = userEvent.setup();
        render(<Register copy={copy} />);

        await fillAccount(user);
        await user.click(screen.getByRole('button', { name: 'Continuar' }));
        await user.click(screen.getByRole('button', { name: 'Crear mi empresa' }));

        expect(screen.getAllByText(copy.company_required).length).toBeGreaterThan(0);
        expect(post).not.toHaveBeenCalled();
    });

    it('con todo válido envía el registro a /register', async () => {
        const user = userEvent.setup();
        render(<Register copy={copy} />);

        await fillAccount(user);
        await user.click(screen.getByRole('button', { name: 'Continuar' }));
        await user.type(screen.getByLabelText(/^Nombre de la empresa/), 'Soporte Ejemplo SAS');
        await user.click(screen.getByRole('button', { name: 'Crear mi empresa' }));

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0]?.[0]).toBe('/register');
    });

    it('ofrece iniciar sesión a quien ya tiene cuenta', () => {
        render(<Register copy={copy} />);

        expect(screen.getByRole('link', { name: 'Inicia sesión' }).getAttribute('href')).toBe('/login');
    });
});
