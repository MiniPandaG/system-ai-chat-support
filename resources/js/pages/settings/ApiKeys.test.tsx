import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import ApiKeys from './ApiKeys';

let shared: Partial<SharedProps> = {};
const post = vi.fn();
const routerPost = vi.fn();
const routerDelete = vi.fn();
const writeText = vi.fn();
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        router: {
            post: (url: string, body: unknown, options: unknown) => {
                routerPost(url, body, options);
            },
            delete: (url: string, body: unknown, options: unknown) => {
                routerDelete(url, body, options);
            },
        },
        useForm: (initial: { name: string; permission: string; expires_in_days: string }) => {
            const [data, setData] = useState(initial);

            return {
                data,
                errors: formErrors,
                processing: false,
                setData: (key: 'name' | 'permission' | 'expires_in_days', value: string) => {
                    setData((current) => ({ ...current, [key]: value }));
                },
                post,
                reset: () => {
                    setData(initial);
                },
                clearErrors: vi.fn(),
            };
        },
        usePage: () => ({ props: shared }),
    };
});

const copy = {
    title: 'Llaves de API',
    detail: 'Con una llave tus sistemas crean tickets.',
    docs: 'Ver la documentación de la API',
    create: 'Crear una llave',
    empty_title: 'Todavía no tienes llaves de API.',
    empty_detail: 'Crea la primera.',
    count: ':count de :max llaves activas.',
    never_used: 'Nunca usada',
    never_expires: 'No caduca',
    expires: 'Caduca el :date',
    status_active: 'Activa',
    status_revoked: 'Revocada',
    status_expired: 'Caducada',
    permission_read: 'Lectura',
    permission_write: 'Escritura',
    permission_admin: 'Administración',
    form_title: 'Crear una llave de API',
    name_label: 'Nombre',
    name_help: 'Para reconocerla en la lista.',
    permission_label: 'Nivel de acceso',
    permission_read_help: 'Consultar.',
    permission_write_help: 'Crear.',
    permission_admin_help: 'Cambiar.',
    expiration_label: 'Caducidad',
    expiration_never: 'Nunca',
    expiration_30: 'En 30 días',
    expiration_90: 'En 90 días',
    expiration_365: 'En 1 año',
    submit: 'Crear llave',
    submitting: 'Creando llave',
    cancel: 'Cancelar',
    reveal_title: 'Tu llave «:name»',
    reveal_warning: 'Copia esta llave ahora. No podrás volver a verla completa.',
    reveal_label: 'Llave de API',
    copy: 'Copiar',
    copied: 'Copiado',
    copy_failed: 'No se pudo copiar. Selecciona la llave y cópiala a mano.',
    reveal_confirm: 'Ya copié la llave',
    reveal_close: 'Listo, cerrar',
    rotate: 'Rotar',
    rotate_label: 'Rotar la llave :name',
    rotate_title: '¿Rotar la llave «:name»?',
    rotate_detail: 'Se crea una llave nueva.',
    rotate_confirm: 'Rotar llave',
    rotating: 'Rotando llave',
    revoke: 'Revocar',
    revoke_label: 'Revocar la llave :name',
    revoke_title: '¿Revocar la llave «:name»?',
    revoke_detail: 'Dejará de funcionar de inmediato.',
    revoke_confirm: 'Revocar llave',
    revoking: 'Revocando llave',
};

const keys = [
    { id: 1, name: 'prod-backend-01', prefix: 'gk_1_AbCdEfGh', permission: 'write' as const, created_at: '2026-10-01T10:00:00Z', last_used_at: null, expires_at: null, status: 'active' as const },
    { id: 2, name: 'antigua', prefix: 'gk_1_ZyXwVuTs', permission: 'read' as const, created_at: '2026-09-01T10:00:00Z', last_used_at: '2026-10-02T10:00:00Z', expires_at: '2026-12-01T00:00:00Z', status: 'revoked' as const },
];

const fullKey = `gk_1_${'A'.repeat(40)}`;

/** userEvent.setup() instala su propio portapapeles: el de la prueba se pone después. */
function setupUser() {
    const user = userEvent.setup();
    Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });

    return user;
}

function renderPage(overrides: Partial<Parameters<typeof ApiKeys>[0]> = {}) {
    return render(<ApiKeys copy={copy} keys={keys} created={null} max={10} {...overrides} />);
}

describe('ApiKeys (llaves de API)', () => {
    beforeEach(() => {
        post.mockReset();
        routerPost.mockReset();
        routerDelete.mockReset();
        writeText.mockReset();
        writeText.mockResolvedValue(undefined);
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
                api_keys: 'Llaves de API',
                knowledge: 'Base de conocimiento', ai: 'Asistente de IA', metrics: 'Métricas',
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
                can: { tickets: true, team: true, branding: true, plan: true, widget: true, api_keys: true, knowledge: true, ai: true, metrics: true },
            },
        };
    });

    it('lista las llaves solo con su prefijo, su acceso, su estado y su último uso', () => {
        renderPage();

        expect(screen.getByText('prod-backend-01')).toBeTruthy();
        expect(screen.getByText('gk_1_AbCdEfGh···')).toBeTruthy();
        expect(screen.getByText('Nunca usada')).toBeTruthy();
        expect(screen.getByText('Activa')).toBeTruthy();
        expect(screen.getByText('Revocada')).toBeTruthy();
        expect(screen.getByText('1 de 10 llaves activas.')).toBeTruthy();
    });

    it('una llave revocada no ofrece rotar ni revocar', () => {
        renderPage();

        expect(screen.getByRole('button', { name: 'Rotar la llave prod-backend-01' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Rotar la llave antigua' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Revocar la llave antigua' })).toBeNull();
    });

    it('sin llaves explica qué hacer', () => {
        renderPage({ keys: [] });

        expect(screen.getByText('Todavía no tienes llaves de API.')).toBeTruthy();
    });

    it('en el tope de llaves activas «Crear una llave» se deshabilita', () => {
        renderPage({ max: 1 });

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Crear una llave' }).disabled).toBe(true);
    });

    it('la navegación marca «Llaves de API» como la sección actual', () => {
        renderPage();

        expect(screen.getAllByRole('link', { name: /Llaves de API/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
    });

    it('crear: el botón espera un nombre y envía al servidor', async () => {
        const user = setupUser();
        renderPage();
        await user.click(screen.getByRole('button', { name: 'Crear una llave' }));

        const dialog = screen.getByRole('dialog', { name: 'Crear una llave de API' });
        expect(within(dialog).getByRole<HTMLButtonElement>('button', { name: 'Crear llave' }).disabled).toBe(true);
        expect(within(dialog).getByRole('radio', { name: 'Lectura' })).toBeTruthy();
        expect(within(dialog).getByRole('radio', { name: 'Administración' })).toBeTruthy();

        await user.type(within(dialog).getByLabelText('Nombre'), 'sistema-facturacion');
        await user.click(within(dialog).getByRole('button', { name: 'Crear llave' }));

        expect(post).toHaveBeenCalledWith('/settings/api-keys', expect.objectContaining({ preserveScroll: true }));
    });

    it('los errores del servidor aparecen junto al campo', async () => {
        formErrors = { name: 'Escribe un nombre para reconocer la llave.', permission: 'Elige un nivel de acceso.' };
        const user = setupUser();
        renderPage();
        await user.click(screen.getByRole('button', { name: 'Crear una llave' }));

        const dialog = screen.getByRole('dialog', { name: 'Crear una llave de API' });
        expect(within(dialog).getByText('Escribe un nombre para reconocer la llave.')).toBeTruthy();
        expect(within(dialog).getByText('Elige un nivel de acceso.')).toBeTruthy();
    });

    it('rotar pide confirmación y manda la petición a la llave elegida', async () => {
        const user = setupUser();
        renderPage();
        await user.click(screen.getByRole('button', { name: 'Rotar la llave prod-backend-01' }));

        const dialog = screen.getByRole('alertdialog', { name: /Rotar/, hidden: true });
        expect(within(dialog).getByText('¿Rotar la llave «prod-backend-01»?')).toBeTruthy();
        expect(routerPost).not.toHaveBeenCalled();

        await user.click(within(dialog).getByRole('button', { name: 'Rotar llave', hidden: true }));

        expect(routerPost).toHaveBeenCalledWith('/settings/api-keys/1/rotate', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('revocar pide confirmación y manda el borrado a la llave elegida', async () => {
        const user = setupUser();
        renderPage();
        await user.click(screen.getByRole('button', { name: 'Revocar la llave prod-backend-01' }));

        const dialog = screen.getByRole('alertdialog', { name: /Revocar/, hidden: true });
        expect(within(dialog).getByText('Dejará de funcionar de inmediato.')).toBeTruthy();

        await user.click(within(dialog).getByRole('button', { name: 'Revocar llave', hidden: true }));

        expect(routerDelete).toHaveBeenCalledWith('/settings/api-keys/1', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('cancelar la confirmación no manda nada', async () => {
        const user = setupUser();
        renderPage();
        await user.click(screen.getByRole('button', { name: 'Revocar la llave prod-backend-01' }));

        await user.click(within(screen.getByRole('alertdialog', { name: /Revocar/, hidden: true })).getByRole('button', { name: 'Cancelar', hidden: true }));

        expect(routerDelete).not.toHaveBeenCalled();
    });

    it('una llave recién creada se muestra completa, como texto, con su advertencia', () => {
        renderPage({ created: { name: 'prod-backend-01', value: fullKey } });

        const dialog = screen.getByRole('dialog', { name: 'Tu llave «prod-backend-01»' });
        expect(within(dialog).getByText('Copia esta llave ahora. No podrás volver a verla completa.')).toBeTruthy();
        expect(within(dialog).getByLabelText('Llave de API').textContent).toBe(fullKey);
    });

    it('sin una llave nueva el diálogo no muestra ninguna', () => {
        renderPage();

        expect(document.body.textContent).not.toContain(fullKey);
    });

    it('copiar usa el portapapeles y lo anuncia', async () => {
        const user = setupUser();
        renderPage({ created: { name: 'prod-backend-01', value: fullKey } });
        const dialog = screen.getByRole('dialog', { name: 'Tu llave «prod-backend-01»' });

        await user.click(within(dialog).getByRole('button', { name: 'Copiar' }));

        expect(writeText).toHaveBeenCalledWith(fullKey);
        expect((await within(dialog).findAllByText('Copiado')).length).toBeGreaterThan(0);
    });

    it('si el portapapeles falla dice cómo copiarla a mano', async () => {
        writeText.mockRejectedValue(new Error('denegado'));
        const user = setupUser();
        renderPage({ created: { name: 'prod-backend-01', value: fullKey } });
        const dialog = screen.getByRole('dialog', { name: 'Tu llave «prod-backend-01»' });

        await user.click(within(dialog).getByRole('button', { name: 'Copiar' }));

        expect(await within(dialog).findByText('No se pudo copiar. Selecciona la llave y cópiala a mano.')).toBeTruthy();
    });

    it('no se puede cerrar sin confirmar que se copió, ni con Escape', async () => {
        const user = setupUser();
        renderPage({ created: { name: 'prod-backend-01', value: fullKey } });
        const dialog = screen.getByRole('dialog', { name: 'Tu llave «prod-backend-01»' });
        const close = within(dialog).getByRole<HTMLButtonElement>('button', { name: 'Listo, cerrar' });

        expect(close.disabled).toBe(true);
        const cancel = new Event('cancel', { cancelable: true });
        dialog.dispatchEvent(cancel);
        expect(cancel.defaultPrevented).toBe(true);

        await user.click(within(dialog).getByLabelText('Ya copié la llave'));
        expect(close.disabled).toBe(false);

        await user.click(close);

        expect((dialog as HTMLDialogElement).open).toBe(false);
    });
});
