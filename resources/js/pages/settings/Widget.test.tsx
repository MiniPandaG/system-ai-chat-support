import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Widget from './Widget';

let shared: Partial<SharedProps> = {};
const post = vi.fn();
const destroy = vi.fn();
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', () => {
    const data = { origin: '' };

    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        router: {
            delete: (url: string, options: unknown) => {
                destroy(url, options);
            },
        },
        useForm: () => ({
            data,
            errors: formErrors,
            processing: false,
            setData: (key: 'origin', value: string) => {
                data[key] = value;
            },
            post,
            reset: vi.fn(),
        }),
        usePage: () => ({ props: shared }),
    };
});

const copy = {
    title: 'Chat web',
    detail: 'El chat que tus clientes usan desde tu sitio.',
    status_label: 'Estado',
    status_on: 'Activo',
    status_off: 'Apagado',
    status_off_detail: 'El chat no se muestra en ningún sitio hasta que permitas al menos uno.',
    install_title: 'Instalación',
    install_detail: 'Pega este código en tus páginas.',
    snippet_label: 'Código para incrustar el chat',
    copy: 'Copiar código',
    copied: 'Código copiado.',
    copy_failed: 'No se pudo copiar. Selecciona el código y cópialo a mano.',
    origins_title: 'Sitios permitidos',
    origins_detail: 'El chat solo se muestra en los sitios que agregues aquí.',
    origin_label: 'Dirección del sitio',
    origin_help: 'Con https.',
    add: 'Agregar sitio',
    adding: 'Agregando sitio',
    remove: 'Quitar',
    remove_label: 'Quitar el sitio :origin',
    removing: 'Quitando sitio',
    empty_title: 'Aún no permites ningún sitio.',
    empty_detail: 'Agrega la dirección de tu sitio para encender el chat.',
    count: ':count de :max sitios permitidos.',
    confirm_title: '¿Quitar :origin?',
    confirm_detail: 'El chat dejará de mostrarse en ese sitio de inmediato.',
    confirm_last_detail: 'Es el último sitio permitido: el chat se apagará.',
    confirm_cancel: 'Cancelar',
    confirm_accept: 'Quitar sitio',
    appearance_title: 'Apariencia',
    appearance_detail: 'Cómo se muestra el chat en tu sitio.',
    appearance_mode: 'Modo',
    appearance_mode_light: 'Claro',
    appearance_mode_dark: 'Oscuro',
    appearance_mode_site: 'Seguir al visitante',
    appearance_mode_site_help: 'Usa el modo del sistema de quien visita tu sitio.',
    appearance_corner: 'Esquina del botón',
    appearance_corner_right: 'Inferior derecha',
    appearance_corner_left: 'Inferior izquierda',
    appearance_save: 'Guardar apariencia',
    appearance_saving: 'Guardando apariencia',
};

const snippet = '<script src="https://app.tikarochat.test/widget.js" data-key="AbCdEfGhIjKlMnOpQrStUvWxYz012345" async></script>';

function renderPage(overrides: Partial<Parameters<typeof Widget>[0]> = {}) {
    return render(<Widget copy={copy} snippet={snippet} enabled origins={[{ id: 1, origin: 'https://tienda-ejemplo.com' }, { id: 2, origin: 'https://www.tienda-ejemplo.com' }]} max={10} appearance={{ mode: 'claro', corner: 'right' }} {...overrides} />);
}

describe('Widget (ajustes del chat web)', () => {
    beforeEach(() => {
        post.mockReset();
        destroy.mockReset();
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
                live_on: 'En vivo',
                live_connecting: 'Conectando…',
                live_off: 'Sin conexión en vivo',
                team: 'Equipo',
                branding: 'Marca',
                widget: 'Chat web',
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
                can: { tickets: true, team: true, branding: true, plan: true, widget: true },
            },
        };
    });

    it('muestra el estado, el código para incrustar y los sitios permitidos con su conteo', () => {
        renderPage();

        expect(screen.getByText('Activo')).toBeTruthy();
        expect(screen.getByLabelText('Código para incrustar el chat').textContent).toBe(snippet);
        expect(screen.getByText('2 de 10 sitios permitidos.')).toBeTruthy();
        expect(screen.getByText('https://www.tienda-ejemplo.com')).toBeTruthy();
    });

    it('apagado explica por qué y cómo encenderlo', () => {
        renderPage({ enabled: false, origins: [] });

        expect(screen.getByText('Apagado')).toBeTruthy();
        expect(screen.getByText('El chat no se muestra en ningún sitio hasta que permitas al menos uno.')).toBeTruthy();
        expect(screen.getByText('Aún no permites ningún sitio.')).toBeTruthy();
    });

    it('la navegación marca «Chat web» como la sección actual', () => {
        renderPage();

        expect(screen.getAllByRole('link', { name: /Chat web/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
    });

    it('no se puede agregar un sitio con el campo vacío', () => {
        renderPage();

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Agregar sitio' }).disabled).toBe(true);
        expect(post).not.toHaveBeenCalled();
    });

    it('el motivo del servidor queda junto al campo', () => {
        formErrors = { origin: 'Ese sitio ya está permitido.' };
        renderPage();

        const field = screen.getByLabelText('Dirección del sitio');
        const message = screen.getByText('Ese sitio ya está permitido.');

        expect(field.getAttribute('aria-invalid')).toBe('true');
        expect(field.getAttribute('aria-describedby')).toContain(message.id);
    });

    it('un error del plan aparece como aviso', () => {
        shared = { ...shared, errors: { limit: 'Tu plan no incluye más canales.' } };
        renderPage();

        expect(screen.getByRole('alert').textContent).toContain('Tu plan no incluye más canales.');
    });

    it('quitar un sitio pide confirmar y avisa cuando es el último', async () => {
        const user = userEvent.setup();
        renderPage({ origins: [{ id: 7, origin: 'https://tienda-ejemplo.com' }] });

        await user.click(screen.getByRole('button', { name: 'Quitar el sitio https://tienda-ejemplo.com' }));

        const dialog = screen.getByRole('alertdialog', { hidden: true });
        expect(within(dialog).getByText('¿Quitar https://tienda-ejemplo.com?')).toBeTruthy();
        expect(within(dialog).getByText('Es el último sitio permitido: el chat se apagará.')).toBeTruthy();

        await user.click(within(dialog).getByRole('button', { name: 'Quitar sitio', hidden: true }));

        expect(destroy).toHaveBeenCalledWith('/settings/widget/origins/7', expect.objectContaining({ preserveScroll: true }));
    });

    it('con varios sitios la confirmación no habla de apagar el chat', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Quitar el sitio https://tienda-ejemplo.com' }));

        expect(screen.getByText('El chat dejará de mostrarse en ese sitio de inmediato.', { exact: true })).toBeTruthy();
    });

    it('copiar el código usa el portapapeles y lo confirma; si falla, lo dice', async () => {
        const user = userEvent.setup();
        const writeText = vi.fn().mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('denegado'));
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Copiar código' }));
        expect(writeText).toHaveBeenCalledWith(snippet);
        expect(await screen.findByText('Código copiado.')).toBeTruthy();

        await user.click(screen.getByRole('button', { name: 'Copiar código' }));
        expect(await screen.findByText('No se pudo copiar. Selecciona el código y cópialo a mano.')).toBeTruthy();
    });
});
