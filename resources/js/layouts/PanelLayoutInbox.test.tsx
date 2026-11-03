import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../types';
import { PanelLayout } from './PanelLayout';

let shared: Partial<SharedProps> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    useForm: () => ({ post: vi.fn(), processing: false }),
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
    widget: 'Chat web',
    api_keys: 'Llaves de API',
    knowledge: 'Base de conocimiento',
    plan: 'Plan',
    platform: 'Plataforma',
    open_menu: 'Abrir menú',
    close_menu: 'Cerrar menú',
    logout: 'Cerrar sesión',
    logging_out: 'Cerrando sesión…',
    roles: { admin: 'Administrador', agent: 'Agente' },
};

function setAuth(unassigned: number | null | undefined) {
    shared = {
        panel,
        auth: {
            user: { id: 1, name: 'Ana Torres', email: 'ana@empresa-ejemplo.com', role: 'agent' },
            tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null },
            can: { tickets: true, team: false, branding: false, plan: false, widget: false, api_keys: false, knowledge: false },
            ...(unassigned === undefined ? {} : { inbox: { unassigned } }),
        },
    };
}

function renderLayout() {
    return render(
        <PanelLayout title="Bandeja" section="inbox">
            <p>x</p>
        </PanelLayout>,
    );
}

describe('PanelLayout: contador de la bandeja', () => {
    beforeEach(() => {
        setAuth(undefined);
    });

    it('con tickets sin asignar muestra el número junto a «Bandeja» y lo dice completo a los lectores de pantalla', () => {
        setAuth(14);
        renderLayout();

        const link = screen.getAllByRole('link', { name: /Bandeja/, hidden: true })[0];

        expect(link?.textContent).toContain('14');
        expect(link?.textContent).toContain('14 sin asignar');
    });

    it('sin tickets sin asignar no muestra ningún número', () => {
        setAuth(0);
        renderLayout();

        expect(screen.getAllByRole('link', { name: /Bandeja/, hidden: true })[0]?.textContent).toBe('Bandeja');
    });

    it('si el servidor no envía el contador, la barra sigue igual', () => {
        setAuth(null);
        renderLayout();

        expect(screen.getAllByRole('link', { name: /Bandeja/, hidden: true })[0]?.textContent).toBe('Bandeja');
    });

    it('el número solo aparece en «Bandeja», no en las demás secciones', () => {
        setAuth(9);
        renderLayout();

        const account = screen.getAllByRole('link', { name: /Mi cuenta/, hidden: true })[0];

        expect(account?.textContent).toBe('Mi cuenta');
    });
});
