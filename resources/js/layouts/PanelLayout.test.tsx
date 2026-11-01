import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { disconnect } from '../realtime/client';
import type { SharedProps } from '../types';
import { PanelLayout } from './PanelLayout';

const post = vi.fn();

vi.mock('../realtime/client', () => ({
    disconnect: vi.fn(),
    connect: vi.fn(),
    listen: vi.fn(() => () => undefined),
    getConnectionState: () => 'disconnected',
    onConnectionState: () => () => undefined,
}));

let shared: Partial<SharedProps> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    useForm: () => ({ post, processing: false }),
    usePage: () => ({ props: shared }),
}));

const panel: SharedProps['panel'] = {
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
    plan: 'Plan',
    platform: 'Plataforma',
    open_menu: 'Abrir menú',
    close_menu: 'Cerrar menú',
    logout: 'Cerrar sesión',
    logging_out: 'Cerrando sesión…',
    roles: { admin: 'Administrador', agent: 'Agente', observer: 'Observador' },
};

describe('PanelLayout', () => {
    beforeEach(() => {
        post.mockClear();
        vi.mocked(disconnect).mockClear();
        shared = {
            panel,
            auth: { user: { id: 1, name: 'Ana Torres', email: 'ana@empresa-ejemplo.com', role: 'admin' }, tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null }, can: { tickets: true, team: true, branding: true, plan: true } },
        };
    });

    it('muestra el título, el contenido y el nombre de la empresa', () => {
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>Contenido de la sección</p>
            </PanelLayout>,
        );

        expect(screen.getByRole('heading', { level: 1, name: 'Mi cuenta' })).toBeTruthy();
        expect(screen.getByText('Contenido de la sección')).toBeTruthy();
        expect(screen.getByText('Soporte Ejemplo SAS')).toBeTruthy();
        expect(screen.getByText(/Ana Torres/).textContent).toContain('Administrador');
    });

    it('solo ofrece Equipo a quien puede verlo', () => {
        const { unmount } = render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.getAllByRole('link', { name: 'Equipo', hidden: true }).length).toBeGreaterThan(0);
        unmount();

        shared = { panel, auth: { user: { id: 1, name: 'Luis', email: 'luis@empresa-ejemplo.com', role: 'agent' }, tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null }, can: { tickets: true, team: false, branding: false, plan: false } } };
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.queryByRole('link', { name: 'Equipo', hidden: true })).toBeNull();
    });

    it('la navegación marca la sección actual con aria-current', () => {
        render(
            <PanelLayout title="Seguridad" section="security">
                <p>x</p>
            </PanelLayout>,
        );

        const nav = screen.getAllByRole('navigation', { name: 'Navegación principal', hidden: true })[0];
        if (nav === undefined) throw new Error('No hay navegación.');

        expect(within(nav).getByRole('link', { name: 'Seguridad', hidden: true }).getAttribute('aria-current')).toBe('page');
        expect(within(nav).getByRole('link', { name: 'Mi cuenta', hidden: true }).getAttribute('aria-current')).toBeNull();
    });

    it('el personal de la plataforma ve «Plataforma» y ningún rol de empresa', () => {
        shared = { panel, auth: { user: { id: 1, name: 'Admin Plataforma', email: 'admin@pandacore.net', role: null }, tenant: null, can: { tickets: false, team: false, branding: false, plan: false } } };

        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );

        expect(screen.getByText('Plataforma')).toBeTruthy();
        expect(screen.getByText(/Admin Plataforma/).textContent).not.toContain('·');
    });

    it('cerrar sesión envía la petición a /logout', async () => {
        const user = userEvent.setup();
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );

        await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));

        expect(post).toHaveBeenCalledWith('/logout', expect.anything());
    });

    it('al terminar de cerrar sesión se corta la conexión en vivo', async () => {
        const user = userEvent.setup();
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );

        await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
        const options = post.mock.calls[0]?.[1] as { onFinish: () => void };
        options.onFinish();

        expect(disconnect).toHaveBeenCalledTimes(1);
    });

    it('muestra el indicador de conexión solo a quien ve la bandeja', () => {
        const { unmount } = render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.getByRole('img', { name: 'Sin conexión en vivo' })).toBeTruthy();
        unmount();

        shared = { panel, auth: { user: { id: 1, name: 'Admin Plataforma', email: 'admin@pandacore.net', role: null }, tenant: null, can: { tickets: false, team: false, branding: false, plan: false } } };
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.queryByRole('img', { name: 'Sin conexión en vivo' })).toBeNull();
    });

    it('el botón de menú abre la hoja de navegación y cerrarla la oculta', async () => {
        const user = userEvent.setup();
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );

        const sheet = document.querySelector('dialog');
        expect(sheet?.hasAttribute('open')).toBe(false);

        await user.click(screen.getByRole('button', { name: 'Abrir menú' }));
        expect(sheet?.hasAttribute('open')).toBe(true);

        await user.click(screen.getByRole('button', { name: 'Cerrar menú', hidden: true }));
        expect(sheet?.hasAttribute('open')).toBe(false);
    });

    it('aplica la paleta de la empresa como variables CSS y la quita al desmontar', () => {
        shared = {
            panel,
            auth: {
                user: { id: 1, name: 'Ana', email: 'ana@empresa-ejemplo.com', role: 'admin' },
                tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: { '--color-primary': '#2164DA', '--color-primary-foreground': '#F6F1EA' } },
                can: { tickets: true, team: true, branding: true, plan: true },
            },
        };

        const first = document.createElement('style');
        first.id = 'brand-theme';
        document.head.appendChild(first);

        const { unmount } = render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );

        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('#2164DA');
        expect(document.documentElement.style.getPropertyValue('--color-primary-foreground')).toBe('#F6F1EA');
        expect(document.getElementById('brand-theme')).toBeNull();

        unmount();

        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('');
    });

    it('sin paleta no toca las variables', () => {
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );

        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('');
    });

    it('solo ofrece Plan a quien puede verlo', () => {
        const { unmount } = render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.getAllByRole('link', { name: 'Plan', hidden: true }).length).toBeGreaterThan(0);
        unmount();

        shared = { panel, auth: { user: { id: 1, name: 'Luis', email: 'luis@empresa-ejemplo.com', role: 'agent' }, tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null }, can: { tickets: true, team: false, branding: false, plan: false } } };
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.queryByRole('link', { name: 'Plan', hidden: true })).toBeNull();
    });

    it('la bandeja es la primera sección y solo la ve quien puede abrirla', () => {
        const { unmount } = render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        const links = screen.getAllByRole('link', { hidden: true });

        expect(links[0]?.getAttribute('href')).toBe('/tickets');
        expect(links[0]?.textContent).toBe('Bandeja');
        unmount();

        shared = { panel, auth: { user: { id: 1, name: 'Admin Plataforma', email: 'admin@pandacore.net', role: null }, tenant: null, can: { tickets: false, team: false, branding: false, plan: false } } };
        render(
            <PanelLayout title="Mi cuenta" section="account">
                <p>x</p>
            </PanelLayout>,
        );
        expect(screen.queryByRole('link', { name: 'Bandeja', hidden: true })).toBeNull();
    });

    it('con bare reparte todo el alto sin contenedor centrado ni relleno', () => {
        const { container } = render(
            <PanelLayout title="Bandeja" section="inbox" bare>
                <p>paneles</p>
            </PanelLayout>,
        );

        expect(container.firstElementChild?.className).toContain('h-dvh');
        expect(screen.getByText('paneles').parentElement?.tagName).toBe('MAIN');
        expect(screen.getByText('paneles').parentElement?.className).toContain('overflow-hidden');
    });
});
