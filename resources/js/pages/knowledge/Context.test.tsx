import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Context from './Context';

let shared: Partial<SharedProps> = {};
const put = vi.fn();
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const { useState: useStateReact } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        useForm: (initial: { body: string }) => {
            const [data, setAll] = useStateReact(initial);
            const [defaults, setDefaults] = useStateReact(initial);

            return {
                data,
                errors: formErrors,
                processing: false,
                isDirty: data.body !== defaults.body,
                setData: (_key: 'body', value: string) => {
                    setAll({ body: value });
                },
                setDefaults: () => {
                    setDefaults(data);
                },
                put: (url: string, options: { onSuccess?: () => void }) => {
                    put(url, options);
                    options.onSuccess?.();
                },
            };
        },
        usePage: () => ({ props: shared }),
    };
});

const copy = {
    title: 'Contexto de la empresa',
    detail: 'Cuéntale a la IA quién eres.',
    tabs_label: 'Secciones de la base de conocimiento',
    tab_articles: 'Artículos',
    tab_documents: 'Documentos',
    tab_context: 'Contexto',
    label: 'Contexto de la empresa',
    help: 'Por ejemplo: «Somos una tienda de componentes electrónicos.»',
    save: 'Guardar cambios',
    saving: 'Guardando',
    saved: 'Contexto guardado.',
    unsaved: 'Cambios sin guardar',
    read_only: 'Solo un administrador puede editar el contexto de la empresa.',
    empty_readonly: 'Todavía no hay contexto. Un administrador puede escribirlo.',
};

function renderPage(overrides: Partial<Parameters<typeof Context>[0]> = {}) {
    return render(<Context copy={copy} body="Somos una tienda." can_edit max={4000} {...overrides} />);
}

describe('Context (contexto de la empresa)', () => {
    beforeEach(() => {
        put.mockReset();
        formErrors = {};
        shared = {
            errors: {},
            flash: { status: null, recovery_codes: null },
            panel: {
                nav_label: 'Navegación principal',
                account: 'Mi cuenta',
                security: 'Seguridad',
                inbox: 'Bandeja',
                inbox_unassigned: ':count sin asignar',
                knowledge: 'Base de conocimiento',
                live_on: 'En vivo',
                live_connecting: 'Conectando…',
                live_off: 'Sin conexión en vivo',
                team: 'Equipo',
                branding: 'Marca',
                widget: 'Chat web',
                api_keys: 'Llaves de API',
                plan: 'Plan',
                platform: 'Plataforma',
                open_menu: 'Abrir menú',
                close_menu: 'Cerrar menú',
                logout: 'Cerrar sesión',
                logging_out: 'Cerrando sesión…',
                roles: { admin: 'Administrador' },
            },
            auth: {
                user: { id: 1, name: 'Ana', email: 'ana@empresa-ejemplo.com', role: 'admin' },
                tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null },
                can: { tickets: true, team: true, branding: true, plan: true, widget: true, api_keys: true, knowledge: true },
            },
        };
    });

    it('el administrador ve el texto guardado en un campo con contador y guardado explícito', async () => {
        const user = userEvent.setup();
        renderPage();

        const field = screen.getByLabelText<HTMLTextAreaElement>('Contexto de la empresa');
        expect(field.value).toBe('Somos una tienda.');
        expect(screen.getByText('17 / 4000')).toBeTruthy();
        const save = screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar cambios' });
        expect(save.disabled).toBe(true);

        await user.type(field, ' Vendemos componentes.');
        expect(screen.getByText('Cambios sin guardar')).toBeTruthy();
        await user.click(save);

        expect(put).toHaveBeenCalledWith('/knowledge/context', expect.objectContaining({ preserveScroll: true }));
        expect(screen.queryByText('Cambios sin guardar')).toBeNull();
    });

    it('muestra el error del servidor junto al campo', () => {
        formErrors = { body: 'El contexto es demasiado largo. El máximo es de 4.000 caracteres.' };
        renderPage();

        expect(screen.getByText('El contexto es demasiado largo. El máximo es de 4.000 caracteres.')).toBeTruthy();
    });

    it('muestra el aviso de guardado que llega del servidor', () => {
        shared = { ...shared, flash: { status: 'Contexto guardado.', recovery_codes: null } };
        renderPage();

        expect(screen.getByText('Contexto guardado.')).toBeTruthy();
    });

    it('marca «Base de conocimiento» y la pestaña «Contexto» como la sección actual', () => {
        renderPage();

        expect(screen.getAllByRole('link', { name: /Base de conocimiento/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
        expect(screen.getByRole('link', { name: 'Contexto' }).getAttribute('aria-current')).toBe('page');
    });

    it('quien solo lee ve el texto como texto, sin campo ni botón', () => {
        const { container } = renderPage({ can_edit: false, body: '<img src=x onerror=alert(1)> Somos una tienda.' });

        expect(screen.getByText('Solo un administrador puede editar el contexto de la empresa.')).toBeTruthy();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Guardar cambios' })).toBeNull();
        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(screen.getByText('<img src=x onerror=alert(1)> Somos una tienda.')).toBeTruthy();
    });

    it('quien solo lee y no hay contexto lo ve dicho', () => {
        renderPage({ can_edit: false, body: '' });

        expect(screen.getByText('Todavía no hay contexto. Un administrador puede escribirlo.')).toBeTruthy();
    });
});
