import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Index from './Index';

let shared: Partial<SharedProps> = {};
const { get } = vi.hoisted(() => ({ get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        get: (url: string, data: unknown, options: unknown) => {
            get(url, data, options);
        },
        post: vi.fn(),
    },
    useForm: () => ({ post: vi.fn(), processing: false }),
    usePage: () => ({ props: shared }),
}));

const copy = {
    title: 'Métricas',
    range_label: 'Rango de fechas',
    presets: { today: 'Hoy', '7d': '7 días', '30d': '30 días', custom: 'Personalizado' },
    from: 'Desde',
    to: 'Hasta',
    apply: 'Aplicar',
    period: 'Del :from al :to',
    previous_hint: 'Los cambios se comparan con los :days días anteriores.',
    sampled: 'Con tantos tickets, las medianas se calculan con los más recientes del rango.',
    no_data: 'Sin datos en este periodo.',
    no_data_detail: 'Prueba con otro rango de fechas.',
    cards: {
        open_now: 'Abiertos ahora',
        open_now_hint: 'Abiertos y esperando al cliente',
        received: 'Tickets recibidos',
        resolved: 'Tickets resueltos',
        first_response: 'Primera respuesta',
        first_response_hint: 'Mediana, de la creación a la primera respuesta',
        resolution: 'Tiempo de resolución',
        resolution_hint: 'Mediana, de la creación a la resolución',
        csat: 'Satisfacción',
        csat_hint: ':count respuestas',
        csat_none: 'Sin respuestas todavía',
        ai_resolved: 'Resueltos solo con la IA',
        ai_resolved_hint: ':by de :total resueltos, sin mensajes de personas',
        ai_cost: 'Costo de la IA',
        ai_cost_hint: ':calls llamadas · :credits créditos',
    },
    change: { better: 'mejora', worse: 'empeora', same: 'sin cambio', none: 'Sin periodo anterior con qué comparar', percent: ':sign:value %', points: ':sign:value puntos', pp: ':sign:value pp' },
    csat: {
        title: 'Satisfacción de los clientes',
        average: 'Promedio de :average sobre 5',
        responses: ':count respuestas de :sent encuestas enviadas',
        distribution: 'Cómo calificaron',
        scale: { '1': 'Muy mala', '2': 'Mala', '3': 'Regular', '4': 'Buena', '5': 'Excelente' },
        level: { low: 'Insatisfecho', mid: 'Neutral', high: 'Satisfecho' },
        comments: 'Comentarios',
        filter: 'Filtrar por calificación',
        all: 'Todas',
        score_option: ':score · :label',
        ticket: 'Ticket #:number',
        no_comments: 'Ningún comentario en este periodo.',
        more: 'Ver más comentarios',
        col_score: 'Calificación',
        col_comment: 'Comentario',
        col_ticket: 'Ticket',
        col_date: 'Fecha',
    },
    trend: {
        title: 'Tendencia',
        volume: 'Tickets recibidos por día',
        response: 'Primera respuesta por día (mediana, minutos)',
        table: 'Ver como tabla',
        chart: 'Ver como gráfico',
        col_date: 'Día',
        col_received: 'Recibidos',
        col_resolved: 'Resueltos',
        col_response: 'Primera respuesta',
        chart_label: ':title, del :from al :to',
    },
};

const card = { value: 0, previous: 0, delta: null, unit: 'percent', better: 'up' } as const satisfies Parameters<typeof Index>[0]['report']['cards']['received'];

const report: Parameters<typeof Index>[0]['report'] = {
    range: { from: '2026-10-06', to: '2026-11-04', days: 30 },
    open_now: 7,
    cards: {
        received: { ...card, value: 42, previous: 30, delta: 40 },
        resolved: { ...card, value: 30, previous: 30, delta: 0 },
        first_response: { ...card, value: 300, previous: 600, delta: -50, better: 'down', label: '5 min', sampled: false },
        resolution: { ...card, value: 7200, previous: 3600, delta: 100, better: 'down', label: '2 h', sampled: false },
        csat: { value: 4.2, previous: 3.8, delta: 0.4, unit: 'points', better: 'up', count: 12 },
        ai_resolved: { value: 25, previous: 20, delta: 5, unit: 'points', better: 'up', resolved: 8, by_ai: 2 },
    },
    csat: { sent: 20, distribution: { '1': 0, '2': 1, '3': 1, '4': 4, '5': 6 } },
    ai: { spent_micro: 5500, spent: 'US$ 0,0055', calls: 20, credits: 14 },
    trend: [
        { date: '2026-11-03', received: 3, resolved: 1, first_response: 300 },
        { date: '2026-11-04', received: 5, resolved: 2, first_response: null },
    ],
};

const comments = {
    data: [
        { id: 1, score: 5, comment: 'Excelente atención\ny rápida', answered_label: '3 de nov., 10:30 a. m.', ticket: { id: 11, number: 4, subject: 'Pedido sin llegar' } },
        { id: 2, score: 2, comment: '<img src=x onerror=alert(1)><b>Tardaron</b>', answered_label: '2 de nov., 9:00 a. m.', ticket: null },
    ],
    next_url: null as string | null,
};

function row(rows: HTMLElement[], index: number): HTMLElement {
    const found = rows[index];

    if (found === undefined) throw new Error('No hay esa fila.');

    return found;
}

function renderPage(overrides: Partial<Parameters<typeof Index>[0]> = {}) {
    return render(<Index copy={copy} range_error={null} filters={{ range: '30d', from: '2026-10-06', to: '2026-11-04', score: null }} report={report} comments={comments} {...overrides} />);
}

describe('Index (métricas)', () => {
    beforeEach(() => {
        get.mockReset();
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
                knowledge: 'Base de conocimiento',
                ai: 'Asistente de IA',
                metrics: 'Métricas',
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

    it('muestra cada métrica con su cifra y la navegación marca «Métricas» como la sección actual', () => {
        renderPage();

        for (const [title, value] of [
            ['Abiertos ahora', '7'],
            ['Tickets recibidos', '42'],
            ['Tickets resueltos', '30'],
            ['Primera respuesta', '5 min'],
            ['Tiempo de resolución', '2 h'],
            ['Satisfacción', '4,2 / 5'],
            ['Resueltos solo con la IA', '25 %'],
            ['Costo de la IA', 'US$ 0,0055'],
        ] as const) {
            expect(within(screen.getByRole('region', { name: title })).getByText(value)).toBeTruthy();
        }

        expect(screen.getAllByRole('link', { name: /Métricas/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
    });

    it('cada cambio lleva signo y palabra, y para un tiempo bajar es mejorar', () => {
        renderPage();

        expect(within(screen.getByRole('region', { name: 'Tickets recibidos' })).getByText('+40 % · mejora')).toBeTruthy();
        expect(within(screen.getByRole('region', { name: 'Tickets resueltos' })).getByText('sin cambio')).toBeTruthy();
        expect(within(screen.getByRole('region', { name: 'Primera respuesta' })).getByText('−50 % · mejora')).toBeTruthy();
        expect(within(screen.getByRole('region', { name: 'Tiempo de resolución' })).getByText('+100 % · empeora')).toBeTruthy();
        expect(within(screen.getByRole('region', { name: 'Satisfacción' })).getByText('+0,4 puntos · mejora')).toBeTruthy();
        expect(within(screen.getByRole('region', { name: 'Resueltos solo con la IA' })).getByText('+5 pp · mejora')).toBeTruthy();
    });

    it('dice el periodo y con cuántos días anteriores se compara', () => {
        renderPage();

        expect(screen.getByText(/Del 2026-10-06 al 2026-11-04/)).toBeTruthy();
        expect(screen.getByText(/comparan con los 30 días anteriores/)).toBeTruthy();
    });

    it('el preajuste elegido está marcado y pulsar otro pide ese rango sin recargar la página', async () => {
        const user = userEvent.setup();
        renderPage();

        expect(screen.getByRole('button', { name: '30 días' }).getAttribute('aria-pressed')).toBe('true');
        expect(screen.getByRole('button', { name: 'Hoy' }).getAttribute('aria-pressed')).toBe('false');

        await user.click(screen.getByRole('button', { name: '7 días' }));

        expect(get).toHaveBeenCalledWith('/metrics', { range: '7d' }, expect.objectContaining({ preserveScroll: true, preserveState: true }));
    });

    it('«Personalizado» pide dos fechas y aplicarlas manda el rango', async () => {
        const user = userEvent.setup();
        renderPage();
        expect(screen.queryByLabelText('Desde')).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Personalizado' }));
        await user.clear(screen.getByLabelText('Desde'));
        await user.type(screen.getByLabelText('Desde'), '2026-10-01');
        await user.click(screen.getByRole('button', { name: 'Aplicar' }));

        expect(get).toHaveBeenCalledWith('/metrics', { range: 'custom', from: '2026-10-01', to: '2026-11-04' }, expect.anything());
    });

    it('un rango que no valía se avisa y se muestran los 30 días', () => {
        renderPage({ range_error: 'Ese rango de fechas no vale. Se muestran los últimos 30 días.' });

        expect(screen.getByRole('alert').textContent).toContain('Ese rango de fechas no vale');
    });

    it('sin datos en el periodo lo dice y sugiere otro rango, sin esconder el selector', () => {
        renderPage({
            report: {
                ...report,
                cards: { ...report.cards, received: { ...card }, resolved: { ...card }, csat: { value: null, previous: null, delta: null, unit: 'points', better: 'up', count: 0 } },
            },
        });

        expect(screen.getByText('Sin datos en este periodo.')).toBeTruthy();
        expect(screen.getByText('Prueba con otro rango de fechas.')).toBeTruthy();
        expect(screen.getByRole('button', { name: '7 días' })).toBeTruthy();
    });

    it('avisa cuando las medianas se calcularon con una muestra', () => {
        renderPage({ report: { ...report, cards: { ...report.cards, first_response: { ...report.cards.first_response, sampled: true } } } });

        expect(screen.getByText(/medianas se calculan con los más recientes/)).toBeTruthy();
    });

    it('la satisfacción dice el promedio con palabras y un icono con nombre, y la distribución trae cada cuenta', () => {
        renderPage();

        const section = screen.getByRole('region', { name: 'Satisfacción de los clientes' });
        expect(within(section).getByText('Promedio de 4,2 sobre 5')).toBeTruthy();
        expect(within(section).getByText('12 respuestas de 20 encuestas enviadas')).toBeTruthy();
        expect(within(section).getAllByRole('img', { name: 'Satisfecho' }).length).toBeGreaterThan(0);
        const rows = within(section).getAllByRole('listitem');
        expect(rows).toHaveLength(5);
        expect(within(row(rows, 0)).getByText('5 · Excelente')).toBeTruthy();
        expect(within(row(rows, 0)).getByText('6')).toBeTruthy();
        expect(within(row(rows, 4)).getByText('0')).toBeTruthy();
    });

    it('los comentarios van en una tabla real con su ticket enlazado; el HTML se ve como texto y respeta los saltos de línea', () => {
        const { container } = renderPage();

        const table = screen.getByRole('table', { name: 'Comentarios' });
        expect(within(table).getByRole('columnheader', { name: 'Calificación' })).toBeTruthy();
        expect(within(table).getByRole('link', { name: 'Ticket #4' }).getAttribute('href')).toBe('/tickets/11');
        expect(within(table).getByText(/Excelente atención/).className).toContain('whitespace-pre-wrap');
        expect(within(table).getByText('<img src=x onerror=alert(1)><b>Tardaron</b>')).toBeTruthy();
        expect(table.querySelector('img')).toBeNull();
        expect(container.querySelector('td b')).toBeNull();
    });

    it('filtrar por calificación pide ese puntaje conservando el rango', async () => {
        const user = userEvent.setup();
        renderPage({ filters: { range: 'custom', from: '2026-10-01', to: '2026-10-31', score: null } });

        await user.selectOptions(screen.getByLabelText('Filtrar por calificación'), '5');

        expect(get).toHaveBeenCalledWith('/metrics', { range: 'custom', from: '2026-10-01', to: '2026-10-31', score: '5' }, expect.anything());
    });

    it('quitar el filtro de calificación no lo manda', async () => {
        const user = userEvent.setup();
        renderPage({ filters: { range: '7d', from: '2026-10-29', to: '2026-11-04', score: 5 } });

        await user.selectOptions(screen.getByLabelText('Filtrar por calificación'), '');

        expect(get).toHaveBeenCalledWith('/metrics', { range: '7d' }, expect.anything());
    });

    it('sin comentarios lo dice, y con más páginas ofrece verlos', () => {
        const { unmount } = renderPage({ comments: { data: [], next_url: null } });
        expect(screen.getByText('Ningún comentario en este periodo.')).toBeTruthy();
        unmount();

        renderPage({ comments: { ...comments, next_url: '/metrics?page=2&score=5' } });
        expect(screen.getByRole('link', { name: 'Ver más comentarios' }).getAttribute('href')).toBe('/metrics?page=2&score=5');
    });

    it('cada gráfico de tendencia tiene su tabla equivalente', () => {
        renderPage();

        expect(screen.getAllByRole('button', { name: 'Ver como tabla' })).toHaveLength(2);
        expect(screen.getAllByRole('img', { name: /Tickets recibidos por día|Primera respuesta por día/ }).length).toBe(2);
    });
});
