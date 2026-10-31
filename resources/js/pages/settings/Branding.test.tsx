import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Branding from './Branding';

const post = vi.fn();
const remove = vi.fn();
const routerPut = vi.fn();
const put = vi.fn();
let shared: Partial<SharedProps> = {};

vi.mock('@inertiajs/react', async () => {
    const react = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
        usePage: () => ({ props: shared }),
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data, setData] = react.useState(initial);

            return {
                data,
                setData: (key: keyof T, value: string) => {
                    setData((current) => ({ ...current, [key]: value }));
                },
                put,
                post: vi.fn(),
                processing: false,
                errors: {} as Record<string, string | undefined>,
            };
        },
        router: {
            post: (url: string, data: unknown, options?: { onFinish?: () => void }) => {
                post(url, data);
                options?.onFinish?.();
            },
            put: (url: string, data: unknown, options?: { onFinish?: () => void }) => {
                routerPut(url, data);
                options?.onFinish?.();
            },
            delete: (url: string, options?: { onFinish?: () => void }) => {
                remove(url);
                options?.onFinish?.();
            },
        },
    };
});

const copy = {
    title: 'Marca',
    detail: 'Tu logo y el nombre con el que te ven.',
    logo: {
        title: 'Logo',
        help: 'PNG, JPEG o WebP, máximo 2 MB.',
        drop: 'Arrastra un archivo o',
        select: 'selecciona uno',
        replace: 'Reemplazar',
        remove: 'Quitar logo',
        removing: 'Quitando…',
        uploading: 'Subiendo :name…',
        progress: ':percent % subido',
        cancel: 'Cancelar la subida',
        done: 'Logo guardado.',
        preview_round: 'Así se verá en círculo',
        preview_square: 'Así se verá en cuadrado',
        alt: 'Logo de tu empresa',
        confirm_title: '¿Quitar el logo?',
        confirm_detail: 'El archivo se borra.',
        confirm_cancel: 'Cancelar',
        confirm_accept: 'Quitar',
        too_large: 'Este archivo pesa :size MB; el máximo es :max MB.',
        wrong_type: 'Solo se admiten imágenes PNG, JPEG o WebP.',
    },
    name: {
        title: 'Nombre de la empresa',
        detail: 'Aparece en el panel.',
        label: 'Nombre',
        required_suffix: '(obligatorio)',
        required: 'Escribe el nombre de tu empresa.',
    },
    logo_required: 'Elige un archivo para subir.',
    colors: {
        title: 'Colores',
        detail: 'Elige los colores de tu marca.',
        advanced: 'Colores de estado (avanzado)',
        picker: 'Selector de color de :label',
        use_suggestion: 'Usar este tono (:hex)',
        format: 'Escribe un color hexadecimal de 6 dígitos, por ejemplo #F65F1E.',
        checking: 'Comprobando el contraste…',
        unavailable: 'No pudimos comprobar el contraste. Inténtalo de nuevo.',
        labels: { primary: 'Primario', secondary: 'Secundario', accent: 'Acento', background: 'Fondo', surface: 'Superficie', text: 'Texto', success: 'Éxito', warning: 'Advertencia', danger: 'Peligro', info: 'Información' },
        help: { primary: 'Botones y foco.', secondary: 'Navegación.', accent: 'Resaltes.', background: 'Fondo de página.', surface: 'Tarjetas.', text: 'Texto principal.', success: 'Éxito.', warning: 'Advertencia.', danger: 'Errores.', info: 'Notas.' },
    },
    preview: {
        title: 'Vista previa',
        detail: 'Así se ven tus colores.',
        panel: 'Panel',
        widget: 'Widget de chat',
        email: 'Correo de ticket',
        panel_nav: 'Bandeja',
        panel_nav_other: 'Equipo',
        panel_button: 'Guardar cambios',
        panel_outline: 'Cancelar',
        panel_badge: 'Nuevo',
        panel_done: 'Resuelto',
        panel_field: 'Correo del cliente',
        panel_field_value: 'laura@cliente.com',
        widget_status: 'Normalmente responde en minutos',
        widget_agent: 'Hola, ¿en qué podemos ayudarte?',
        widget_client: 'Quiero cambiar mi plan.',
        widget_placeholder: 'Escribe tu mensaje',
        widget_send: 'Enviar',
        email_greeting: 'Hola, Laura',
        email_quote: 'Ya revisamos tu caso.',
        email_button: 'Responder en el portal',
        email_alt: 'o responde directamente a este correo.',
        email_footer: ':company · Gestionar notificaciones',
        description: 'Tres ejemplos de la marca.',
    },
    save: {
        save: 'Guardar cambios',
        saving: 'Guardando…',
        unsaved: 'Cambios sin guardar',
        restore: 'Restaurar paleta por defecto',
        restore_title: '¿Restaurar la paleta por defecto?',
        restore_detail: 'Tus colores vuelven al naranja y blanco de fábrica.',
        restore_cancel: 'Cancelar',
        restore_accept: 'Restaurar',
        restoring: 'Restaurando…',
    },
};

const defaults = { primary: '#F65F1E', secondary: '#2E5EAA', accent: '#2BAFBF', background: '#15110D', surface: '#1D1813', text: '#F6F1EA', success: '#35A373', warning: '#E0A526', danger: '#C83B3F', info: '#2E6FB0' };
const foregrounds = { primary: '#1D1208', secondary: '#F6F1EA', accent: '#1D1208', success: '#1D1208', warning: '#1D1208', danger: '#F6F1EA', info: '#F6F1EA' };
const palette = { colors: defaults, defaults, foregrounds };

const limits = { max_bytes: 2 * 1024 * 1024, accepted: ['image/png', 'image/jpeg', 'image/webp'] };
const logo = { url: 'http://localhost/files/abc?signature=x', width: 128, height: 128 };

describe('Branding', () => {
    beforeEach(() => {
        post.mockClear();
        remove.mockClear();
        routerPut.mockClear();
        put.mockClear();
        shared = {
            errors: {},
            flash: { status: null, recovery_codes: null },
            panel: {
                nav_label: 'Navegación principal',
                account: 'Mi cuenta',
                security: 'Seguridad',
                team: 'Equipo',
                branding: 'Marca',
                platform: 'Plataforma',
                open_menu: 'Abrir menú',
                close_menu: 'Cerrar menú',
                logout: 'Cerrar sesión',
                logging_out: 'Cerrando sesión…',
                roles: { admin: 'Administrador' },
            },
            auth: {
                user: { name: 'Ana', email: 'ana@empresa-ejemplo.com', role: 'admin' },
                tenant: { name: 'Soporte Ejemplo SAS', logo_url: null, theme: null },
                can: { team: true, branding: true },
            },
        };
    });

    it('sin logo muestra solo la zona de carga y el nombre actual', () => {
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

        expect(screen.getByLabelText(/Arrastra un archivo/)).toBeTruthy();
        expect(screen.queryByText('Así se verá en círculo')).toBeNull();
        expect(screen.getByLabelText<HTMLInputElement>(/^Nombre \(obligatorio\)/).value).toBe('Soporte Ejemplo SAS');
    });

    it('con logo lo muestra en círculo y en cuadrado a la vez', () => {
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={logo} palette={palette} limits={limits} />);

        expect(screen.getByAltText('Logo de tu empresa').getAttribute('src')).toBe(logo.url);
        expect(screen.getByText('Así se verá en círculo')).toBeTruthy();
        expect(screen.getByText('Así se verá en cuadrado')).toBeTruthy();
    });

    it('subir un archivo válido lo envía como formulario a la ruta del logo', async () => {
        const user = userEvent.setup();
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);
        const file = new File([new Uint8Array(10)], 'logo.png', { type: 'image/png' });

        await user.upload(screen.getByLabelText(/Arrastra un archivo/), file);

        expect(post).toHaveBeenCalledWith('/settings/branding/logo', { logo: file });
    });

    it('un archivo inválido ni siquiera se envía', async () => {
        const user = userEvent.setup({ applyAccept: false });
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

        await user.upload(screen.getByLabelText(/Arrastra un archivo/), new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' }));

        expect(post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert').textContent).toBe('Solo se admiten imágenes PNG, JPEG o WebP.');
    });

    it('muestra el error que devuelve el servidor al subir', () => {
        shared = { ...shared, errors: { logo: 'La extensión del archivo no corresponde a lo que contiene.' } };
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

        expect(screen.getByText(/La extensión del archivo no corresponde/)).toBeTruthy();
    });

    it('quitar el logo pide confirmación y solo actúa al confirmar', async () => {
        const user = userEvent.setup();
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={logo} palette={palette} limits={limits} />);

        await user.click(screen.getByRole('button', { name: 'Quitar logo' }));
        expect(remove).not.toHaveBeenCalled();

        const dialog = document.querySelector<HTMLElement>('dialog[role="alertdialog"]');
        if (dialog === null) throw new Error('No hay diálogo.');
        await user.click(within(dialog).getByRole('button', { name: 'Quitar', hidden: true }));

        expect(remove).toHaveBeenCalledWith('/settings/branding/logo');
    });

    it('el botón de guardar el nombre solo se habilita cuando hay cambios', async () => {
        const user = userEvent.setup();
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

        const save = screen.getByRole('button', { name: 'Guardar cambios' });
        expect(save.hasAttribute('disabled')).toBe(true);

        await user.type(screen.getByLabelText(/^Nombre \(obligatorio\)/), ' Nuevo');
        expect(save.hasAttribute('disabled')).toBe(false);

        await user.click(save);
        expect(routerPut).toHaveBeenCalledWith('/settings/branding/name', { name: 'Soporte Ejemplo SAS Nuevo' });
    });

    it('un nombre vacío no se envía y explica por qué', async () => {
        const user = userEvent.setup();
        render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

        await user.clear(screen.getByLabelText(/^Nombre \(obligatorio\)/));
        await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        expect(screen.getByText('Escribe el nombre de tu empresa.')).toBeTruthy();
        expect(routerPut).not.toHaveBeenCalled();
    });

    describe('colores', () => {
        const failing = {
            ok: false,
            colors: { ...defaults, primary: '#2F6FE0' },
            foregrounds: {},
            issues: [
                {
                    key: 'primary',
                    kind: 'foreground',
                    message: 'Este color no da suficiente contraste con el texto: 4,1:1 (mínimo 4,5:1). Te proponemos #2164DA (4,7:1).',
                    suggestion: { hex: '#2164DA', ratio: '4,7' },
                },
            ],
        };
        const passing = { ok: true, colors: defaults, foregrounds: {}, issues: [] };

        const setColor = async (user: ReturnType<typeof userEvent.setup>, label: string, value: string) => {
            const field = screen.getByLabelText<HTMLInputElement>(label);
            await user.clear(field);
            await user.type(field, value);
        };

        it('muestra los seis colores principales y los de estado en una sección aparte', () => {
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            for (const label of ['Primario', 'Secundario', 'Acento', 'Fondo', 'Superficie', 'Texto']) {
                expect(screen.getByLabelText(label)).toBeTruthy();
            }

            const advanced = document.querySelector('details');
            expect(advanced?.textContent).toContain('Colores de estado (avanzado)');
            expect(advanced?.querySelectorAll('input[type="text"]')).toHaveLength(4);
            expect(screen.getByRole('button', { name: 'Guardar cambios' }).hasAttribute('disabled')).toBe(true);
        });

        it('un color con poco contraste muestra la explicación del servidor y mantiene guardar deshabilitado', async () => {
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve(failing) }));
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            await setColor(user, 'Primario', '#2F6FE0');

            expect(await screen.findByText(/4,1:1 \(mínimo 4,5:1\)/)).toBeTruthy();
            expect(screen.getByRole('button', { name: 'Guardar cambios' }).hasAttribute('disabled')).toBe(true);
        });

        it('usar el tono propuesto lo aplica y, al pasar la revisión, se puede guardar solo lo que cambió', async () => {
            const fetchMock = vi
                .fn()
                .mockResolvedValueOnce({ ok: true, json: () => Promise.resolve(failing) })
                .mockResolvedValue({ ok: true, json: () => Promise.resolve(passing) });
            vi.stubGlobal('fetch', fetchMock);
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            await setColor(user, 'Primario', '#2F6FE0');
            await user.click(await screen.findByRole('button', { name: 'Usar este tono (#2164DA)' }));

            expect(screen.getByLabelText<HTMLInputElement>('Primario').value).toBe('#2164DA');

            const save = screen.getByRole('button', { name: 'Guardar cambios' });
            await vi.waitFor(() => {
                expect(save.hasAttribute('disabled')).toBe(false);
            });

            await user.click(save);

            expect(routerPut).toHaveBeenCalledWith('/settings/branding/colors', { colors: { primary: '#2164DA' } });
        });

        it('un hexadecimal a medias muestra el formato esperado y no consulta al servidor', async () => {
            const fetchMock = vi.fn();
            vi.stubGlobal('fetch', fetchMock);
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            await setColor(user, 'Primario', '#2F6');

            expect(screen.getByText(/hexadecimal de 6 dígitos/)).toBeTruthy();
            expect(fetchMock).not.toHaveBeenCalled();
            expect(screen.getByRole('button', { name: 'Guardar cambios' }).hasAttribute('disabled')).toBe(true);
        });

        it('si no se puede comprobar el contraste lo dice y no deja guardar', async () => {
            vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('sin red')));
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            await setColor(user, 'Primario', '#2164DA');

            expect(await screen.findByText('No pudimos comprobar el contraste. Inténtalo de nuevo.')).toBeTruthy();
            expect(screen.getByRole('button', { name: 'Guardar cambios' }).hasAttribute('disabled')).toBe(true);
        });
    });

    describe('vista previa y restaurar', () => {
        it('muestra las tres miniaturas con un texto que las describe, sin controles enfocables', () => {
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            const preview = screen.getByRole('region', { name: 'Vista previa' });

            expect(within(preview).getByText('Tres ejemplos de la marca.').className).toContain('sr-only');
            for (const caption of ['Panel', 'Widget de chat', 'Correo de ticket']) expect(within(preview).getByText(caption)).toBeTruthy();

            const miniatures = preview.querySelector('[aria-hidden="true"]');

            expect(miniatures?.querySelectorAll('[data-brand-scope]')).toHaveLength(3);
            expect(miniatures?.querySelectorAll('button, a, input, select, textarea')).toHaveLength(0);
        });

        it('el widget y el correo nacen en modo claro y el panel conserva el oscuro', () => {
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            const scopes = [...document.querySelectorAll<HTMLElement>('[data-brand-scope]')];

            expect(scopes.map((scope) => scope.dataset.mode ?? 'oscuro')).toEqual(['oscuro', 'claro', 'claro']);
            expect(scopes[1]?.style.getPropertyValue('--color-background')).toBe('#FFFFFF');
            expect(scopes[2]?.style.getPropertyValue('--color-surface')).toBe('#F7F4EF');
        });

        it('el panel de la vista previa usa solo el primario y su texto', () => {
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            const panel = document.querySelector<HTMLElement>('[data-brand-scope]');

            expect(panel?.style.getPropertyValue('--color-primary')).toBe('#F65F1E');
            expect(panel?.style.getPropertyValue('--color-primary-foreground')).toBe('#1D1208');
            expect(panel?.style.getPropertyValue('--color-accent')).toBe('');
        });

        it('al elegir un color la vista previa lo muestra enseguida, con el texto calculado por el servidor', async () => {
            const report = { ok: true, colors: { ...defaults, primary: '#2164DA' }, foregrounds: { ...foregrounds, primary: '#F6F1EA' }, issues: [] };
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve(report) }));
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            const field = screen.getByLabelText<HTMLInputElement>('Primario');
            await user.clear(field);
            await user.type(field, '#2164DA');

            const panel = document.querySelector<HTMLElement>('[data-brand-scope]');

            expect(panel?.style.getPropertyValue('--color-primary')).toBe('#2164DA');
            await vi.waitFor(() => {
                expect(panel?.style.getPropertyValue('--color-primary-foreground')).toBe('#F6F1EA');
            });
        });

        it('una paleta con colores inválidos a medias no se aplica a la vista previa', async () => {
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            const field = screen.getByLabelText<HTMLInputElement>('Primario');
            await user.clear(field);
            await user.type(field, '#2164');

            expect(document.querySelector<HTMLElement>('[data-brand-scope]')?.style.getPropertyValue('--color-primary')).toBe('');
        });

        it('el botón de guardar es uno solo, está deshabilitado sin cambios y avisa cuando hay pendientes', async () => {
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: () => Promise.resolve({ ok: true, colors: defaults, foregrounds, issues: [] }) }));
            const user = userEvent.setup();
            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            expect(screen.getAllByRole('button', { name: 'Guardar cambios' })).toHaveLength(1);
            expect(screen.getByRole('button', { name: 'Guardar cambios' }).hasAttribute('disabled')).toBe(true);
            expect(screen.queryByText('Cambios sin guardar')).toBeNull();

            await user.type(screen.getByLabelText(/^Nombre \(obligatorio\)/), ' Nuevo');

            expect(screen.getByText('Cambios sin guardar')).toBeTruthy();
        });

        it('restaurar está deshabilitado con la paleta de fábrica y, si no, pide confirmación antes de actuar', async () => {
            const user = userEvent.setup();
            const custom = { ...palette, colors: { ...defaults, primary: '#2164DA' } };
            const { unmount } = render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={palette} limits={limits} />);

            expect(screen.getByRole('button', { name: 'Restaurar paleta por defecto' }).hasAttribute('disabled')).toBe(true);
            unmount();

            render(<Branding copy={copy} name="Soporte Ejemplo SAS" logo={null} palette={custom} limits={limits} />);
            await user.click(screen.getByRole('button', { name: 'Restaurar paleta por defecto' }));
            expect(remove).not.toHaveBeenCalled();

            const dialog = document.querySelector<HTMLElement>('dialog[role="alertdialog"][aria-labelledby]');
            const open = [...document.querySelectorAll<HTMLElement>('dialog[role="alertdialog"]')].find((candidate) => candidate.hasAttribute('open'));
            if (dialog === null || open === undefined) throw new Error('No hay diálogo abierto.');

            expect(within(open).getByText('¿Restaurar la paleta por defecto?')).toBeTruthy();
            await user.click(within(open).getByRole('button', { name: 'Restaurar', hidden: true }));

            expect(remove).toHaveBeenCalledWith('/settings/branding/colors');
        });
    });
});
