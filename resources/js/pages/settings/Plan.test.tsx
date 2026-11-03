import { render, screen, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Plan from './Plan';

let shared: Partial<SharedProps> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
    useForm: () => ({ post: vi.fn(), processing: false }),
    usePage: () => ({ props: shared }),
}));

const copy = {
    title: 'Plan',
    current: 'Tu plan actual',
    detail: 'Lo que incluye tu plan y cuánto estás usando.',
    usage: ':used de :max',
    unlimited: 'Sin límite',
    remaining: 'Te quedan :remaining',
    none_left: 'No te queda ninguno',
    over: 'Superas el límite: usas :used y tu plan permite :max. Nadie pierde el acceso.',
    full: 'Llegaste al límite de tu plan.',
    monthly: 'Se renueva el primer día de cada mes.',
    included: 'Incluye hasta :max',
    not_available: 'Todavía no disponible en tu cuenta: se medirá cuando exista.',
    labels: { agents: 'Puestos de agente', channels: 'Canales', tickets_per_month: 'Tickets al mes', ai_credits: 'Créditos de IA al mes' },
    help: { agents: 'Cuentan los administradores y agentes activos.', channels: 'Los canales por donde te escriben.', tickets_per_month: 'Conversaciones nuevas cada mes.', ai_credits: 'Lo que puede usar la IA cada mes.' },
};

const limits = [
    { key: 'agents' as const, monthly: false, measured: true, limit: 5, used: 2, remaining: 3, over: false },
    { key: 'channels' as const, monthly: false, measured: false, limit: 3, used: 0, remaining: 3, over: false },
    { key: 'tickets_per_month' as const, monthly: true, measured: false, limit: 1000, used: 0, remaining: 1000, over: false },
    { key: 'ai_credits' as const, monthly: true, measured: false, limit: 500, used: 0, remaining: 500, over: false },
];

const seatsDetail = '1 miembro activo y 1 invitación pendiente.';

interface LimitRow {
    key: 'agents' | 'channels' | 'tickets_per_month' | 'ai_credits';
    monthly: boolean;
    measured: boolean;
    limit: number | null;
    used: number;
    remaining: number | null;
    over: boolean;
}

function withAgents(changes: Partial<LimitRow>): LimitRow[] {
    return limits.map((item): LimitRow => (item.key === 'agents' ? { ...item, ...changes } : item));
}

function card(name: string): HTMLElement {
    const heading = screen.getByRole('heading', { name });
    const item = heading.closest('li');
    if (item === null) throw new Error(`No hay tarjeta para ${name}.`);

    return item;
}

describe('Plan', () => {
    beforeEach(() => {
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
                can: { tickets: true, team: true, branding: true, plan: true, widget: true, api_keys: true },
            },
        };
    });

    it('muestra el nombre del plan y una tarjeta por límite', () => {
        render(<Plan copy={copy} plan={{ name: 'Negocio' }} limits={limits} seatsDetail={seatsDetail} />);

        expect(screen.getByText('Negocio')).toBeTruthy();
        expect(screen.getAllByRole('listitem')).toHaveLength(4);
    });

    it('los puestos de agente muestran uso, barra, lo que queda y el desglose', () => {
        render(<Plan copy={copy} plan={{ name: 'Equipo' }} limits={limits} seatsDetail={seatsDetail} />);

        const agents = card('Puestos de agente');

        expect(within(agents).getByText('2 de 5')).toBeTruthy();
        expect(within(agents).getByRole('progressbar', { name: 'Puestos de agente: 2 de 5' })).toBeTruthy();
        expect(within(agents).getByText('Te quedan 3')).toBeTruthy();
        expect(within(agents).getByText(seatsDetail)).toBeTruthy();
    });

    it('lo que todavía no se mide muestra lo incluido y lo dice, sin barra ni uso inventado', () => {
        render(<Plan copy={copy} plan={{ name: 'Equipo' }} limits={limits} seatsDetail={seatsDetail} />);

        for (const [name, included] of [['Canales', 'Incluye hasta 3'], ['Tickets al mes', 'Incluye hasta 1.000'], ['Créditos de IA al mes', 'Incluye hasta 500']] as const) {
            const item = card(name);

            expect(within(item).getByText(included)).toBeTruthy();
            expect(within(item).queryByRole('progressbar')).toBeNull();
            expect(within(item).getByText(/Todavía no disponible/)).toBeTruthy();
        }
    });

    it('los límites mensuales dicen cuándo se renuevan y los demás no', () => {
        render(<Plan copy={copy} plan={{ name: 'Equipo' }} limits={limits} seatsDetail={seatsDetail} />);

        expect(within(card('Tickets al mes')).getByText('Se renueva el primer día de cada mes.')).toBeTruthy();
        expect(within(card('Canales')).queryByText('Se renueva el primer día de cada mes.')).toBeNull();
    });

    it('sin puestos libres avisa con texto, y la barra pasa a advertencia', () => {
        render(<Plan copy={copy} plan={{ name: 'Inicial' }} limits={withAgents({ limit: 2, used: 2, remaining: 0 })} seatsDetail={seatsDetail} />);

        const agents = card('Puestos de agente');

        expect(within(agents).getByText('No te queda ninguno')).toBeTruthy();
        expect(within(agents).getByText('Llegaste al límite de tu plan.')).toBeTruthy();
        expect(within(agents).getByRole('progressbar').firstElementChild?.className).toContain('bg-warning');
    });

    it('si está por encima del tope muestra el aviso fuerte con los números', () => {
        render(<Plan copy={copy} plan={{ name: 'Inicial' }} limits={withAgents({ limit: 2, used: 5, remaining: 0, over: true })} seatsDetail={seatsDetail} />);

        const agents = card('Puestos de agente');

        expect(within(agents).getByText('Superas el límite: usas 5 y tu plan permite 2. Nadie pierde el acceso.')).toBeTruthy();
        expect(within(agents).queryByText('Llegaste al límite de tu plan.')).toBeNull();
        expect(within(agents).getByRole('progressbar').firstElementChild?.className).toContain('bg-danger');
    });

    it('un límite sin tope se muestra como sin límite y sin barra', () => {
        render(<Plan copy={copy} plan={{ name: 'Negocio' }} limits={withAgents({ limit: null, remaining: null })} seatsDetail={seatsDetail} />);

        const agents = card('Puestos de agente');

        expect(within(agents).getByText('Sin límite')).toBeTruthy();
        expect(within(agents).queryByRole('progressbar')).toBeNull();
    });

    it('es solo lectura: no hay botones para cambiar de plan', () => {
        render(<Plan copy={copy} plan={{ name: 'Equipo' }} limits={limits} seatsDetail={seatsDetail} />);

        const main = document.querySelector('main');

        expect(main?.querySelectorAll('button, form, input, select')).toHaveLength(0);
    });
});
