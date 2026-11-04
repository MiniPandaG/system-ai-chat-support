import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Ai from './Ai';

let shared: Partial<SharedProps> = {};
const { put } = vi.hoisted(() => ({ put: vi.fn() }));
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: { post: vi.fn() },
    useForm: <T extends Record<string, unknown>>(initial: T) => {
        const [data, setData] = useState<T>(initial);

        return {
            data,
            errors: formErrors,
            processing: false,
            isDirty: JSON.stringify(data) !== JSON.stringify(initial),
            setData: (key: string, value: unknown) => {
                setData((current) => ({ ...current, [key]: value }));
            },
            put: (url: string, options: unknown) => {
                put(url, data, options);
            },
        };
    },
    usePage: () => ({ props: shared }),
}));

const copy = {
    title: 'Asistente de IA',
    intro: 'La IA prepara respuestas con la base de conocimiento de tu empresa.',
    channels: 'Canales',
    channels_hint: 'Elige qué hace la IA en cada canal.',
    widget: 'Chat web',
    email: 'Correo',
    modes: { off: 'Apagada', shadow: 'Borrador para revisar', auto: 'Automática' },
    auto_warning: 'En modo automático los clientes reciben respuestas de la IA sin que nadie las revise antes.',
    model: 'Modelos',
    model_label: 'Modelo que redacta las respuestas',
    model_hint: 'El costo es el estimado de una respuesta típica.',
    model_auto: 'El primero disponible',
    summary_label: 'Modelo que resume los casos',
    summary_hint: 'Resume el caso para una persona.',
    summary_same: 'El mismo que redacta las respuestas',
    model_none: 'Esta instalación todavía no tiene ningún modelo de IA configurado.',
    tier_fast: 'Rápido',
    tier_precise: 'Preciso',
    cost: 'Costo estimado por respuesta: :cost',
    region: 'Procesa en: :region',
    rules: 'Reglas de respuesta',
    require: 'Responder solo con la base de conocimiento',
    require_hint: 'Si no encuentra nada parecido, no responde.',
    identify: 'Presentarse como asistente virtual',
    identify_hint: 'El cliente ve «Asistente virtual».',
    show_sources: 'Mostrar las fuentes en la respuesta',
    show_sources_hint: 'Añade «Fuente: …».',
    min_confidence: 'Nivel mínimo de confianza (%)',
    min_confidence_hint: 'Entre :min y 100.',
    max_messages: 'Mensajes máximos de la IA por ticket',
    max_messages_hint: 'Al llegar a este número, el caso pasa a una persona.',
    tone: 'Tono',
    tones: { friendly: 'Cercano', formal: 'Formal', custom: 'Personalizado' },
    tone_custom: 'Instrucciones de tono',
    tone_custom_hint: 'Hasta :max caracteres.',
    topics: 'Temas que pasan a una persona',
    topics_hint: 'Una palabra o frase por línea (hasta :max).',
    schedule: 'Horario de la IA',
    schedule_modes: { always: 'Siempre', within: 'Solo dentro del horario', outside: 'Solo fuera del horario' },
    schedule_hint: 'Fuera de su horario la IA no interviene.',
    schedule_days: 'Días del horario',
    days: { '1': 'Lunes', '2': 'Martes', '3': 'Miércoles', '4': 'Jueves', '5': 'Viernes', '6': 'Sábado', '7': 'Domingo' },
    schedule_start: 'Desde',
    schedule_end: 'Hasta',
    schedule_timezone: 'Zona horaria',
    spend: 'Límites de gasto',
    spend_hint: 'Vacío usa el de la plataforma (diario US$ :daily, mensual US$ :monthly).',
    daily_spend: 'Tope diario (US$)',
    monthly_spend: 'Tope mensual (US$)',
    usage: 'Consumo',
    usage_day: 'Gasto de hoy',
    usage_month: 'Gasto de este mes',
    usage_credits: 'Créditos del plan este mes',
    usage_of: ':used de :limit',
    paused: 'La IA está en pausa: :reason Los tickets pasan a las personas del equipo sin perder mensajes.',
    privacy: 'Antes de enviar el texto del cliente se tapan tarjetas y documentos.',
    save: 'Guardar cambios',
    saved: 'Ajustes de IA guardados.',
};

const models = [
    { id: 'deepseek-fast', label: 'DeepSeek Flash', tier: 'fast', provider: 'DeepSeek', region: 'China', cost: 'US$ 0,0004' },
    { id: 'claude-precise', label: 'Claude Sonnet', tier: 'precise', provider: 'Anthropic', region: 'Estados Unidos', cost: 'US$ 0,0085' },
];

const settings: Parameters<typeof Ai>[0]['settings'] = {
    reply_model: null,
    summary_model: null,
    widget_mode: 'off',
    email_mode: 'off',
    identify_as_ai: true,
    require_knowledge: true,
    min_confidence: 70,
    show_sources: true,
    tone: 'friendly',
    tone_custom: '',
    schedule_mode: 'always',
    schedule_days: [1, 2, 3, 4, 5],
    schedule_start: '09:00',
    schedule_end: '18:00',
    schedule_timezone: 'America/Bogota',
    escalate_topics: [],
    max_messages: 5,
    daily_spend: '',
    monthly_spend: '',
};

const defaults = { daily_spend: '2', monthly_spend: '30', max_daily_spend: '50', max_monthly_spend: '500', min_confidence: 30, max_topics: 20, topic_length: 40, tone_length: 500 };
const usage = {
    paused: null,
    day: { spent: 'US$ 0,12', limit: 'US$ 2,00', percent: 6 },
    month: { spent: 'US$ 1,50', limit: 'US$ 30,00', percent: 5 },
    credits: { used: 40, limit: 50, percent: 80 },
};

function renderPage(overrides: Partial<Parameters<typeof Ai>[0]> = {}) {
    return render(<Ai copy={copy} settings={{ ...settings }} defaults={defaults} timezones={['America/Bogota', 'America/Lima']} models={models} usage={usage} {...overrides} />);
}

describe('Ai (ajustes del asistente)', () => {
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
                live_on: 'En vivo',
                live_connecting: 'Conectando…',
                live_off: 'Sin conexión en vivo',
                team: 'Equipo',
                branding: 'Marca',
                widget: 'Chat web',
                api_keys: 'Llaves de API',
                knowledge: 'Base de conocimiento',
                ai: 'Asistente de IA', metrics: 'Métricas',
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

    it('muestra los dos canales apagados de fábrica y las reglas con sus explicaciones', () => {
        renderPage();

        expect(screen.getByRole('group', { name: 'Chat web' })).toBeTruthy();
        expect(screen.getAllByLabelText('Apagada').every((radio) => (radio as HTMLInputElement).checked)).toBe(true);
        expect(screen.getByText('Si no encuentra nada parecido, no responde.')).toBeTruthy();
        expect(screen.getByText('Antes de enviar el texto del cliente se tapan tarjetas y documentos.')).toBeTruthy();
    });

    it('la navegación marca «Asistente de IA» como la sección actual', () => {
        renderPage();

        expect(screen.getAllByRole('link', { name: /Asistente de IA/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
    });

    it('muestra el consumo del día, del mes y de los créditos como barras con texto', () => {
        renderPage();

        expect(screen.getByRole('progressbar', { name: 'Gasto de hoy: US$ 0,12 de US$ 2,00' })).toBeTruthy();
        expect(screen.getByRole('progressbar', { name: 'Gasto de este mes: US$ 1,50 de US$ 30,00' })).toBeTruthy();
        expect(screen.getByRole('progressbar', { name: 'Créditos del plan este mes: 40 de 50' })).toBeTruthy();
        expect(screen.queryByText(/La IA está en pausa/)).toBeNull();
    });

    it('si la IA está en pausa lo dice con el motivo y que los tickets pasan a las personas', () => {
        renderPage({ usage: { ...usage, paused: 'Se alcanzó el límite de gasto de IA.' } });

        expect(screen.getByRole('alert').textContent).toContain('La IA está en pausa: Se alcanzó el límite de gasto de IA.');
        expect(screen.getByRole('alert').textContent).toContain('sin perder mensajes');
    });

    it('un plan sin créditos de IA no muestra esa barra', () => {
        renderPage({ usage: { ...usage, credits: null } });

        expect(screen.queryByText(/Créditos del plan/)).toBeNull();
    });

    it('al elegir un modelo muestra su costo estimado, su nivel y dónde procesa', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.selectOptions(screen.getByLabelText('Modelo que redacta las respuestas'), 'claude-precise');

        expect(screen.getByText('Preciso')).toBeTruthy();
        expect(screen.getByText('Costo estimado por respuesta: US$ 0,0085')).toBeTruthy();
        expect(screen.getByText('Procesa en: Estados Unidos')).toBeTruthy();
    });

    it('elegir el modo automático avisa que nadie revisará las respuestas', async () => {
        const user = userEvent.setup();
        renderPage();
        expect(screen.queryByText(/sin que nadie las revise/)).toBeNull();

        for (const radio of screen.getAllByLabelText('Automática').slice(0, 1)) await user.click(radio);

        expect(screen.getByRole('alert').textContent).toContain('sin que nadie las revise antes');
    });

    it('el horario solo muestra días, horas y zona cuando no es «Siempre»', async () => {
        const user = userEvent.setup();
        renderPage();
        expect(screen.queryByLabelText('Desde')).toBeNull();

        await user.click(screen.getByLabelText('Solo dentro del horario'));

        expect(screen.getByLabelText('Desde')).toBeTruthy();
        expect(screen.getByLabelText('Hasta')).toBeTruthy();
        expect(screen.getByLabelText('Zona horaria')).toBeTruthy();
        expect(screen.getByRole<HTMLInputElement>('checkbox', { name: 'Lunes' }).checked).toBe(true);
        expect(screen.getByRole<HTMLInputElement>('checkbox', { name: 'Domingo' }).checked).toBe(false);
    });

    it('el tono personalizado pide sus instrucciones solo cuando se elige', async () => {
        const user = userEvent.setup();
        renderPage();
        expect(screen.queryByLabelText('Instrucciones de tono')).toBeNull();

        await user.click(screen.getByLabelText('Personalizado'));

        expect(screen.getByLabelText('Instrucciones de tono')).toBeTruthy();
    });

    it('el botón de guardar espera a que haya cambios y luego manda todo lo elegido', async () => {
        const user = userEvent.setup();
        renderPage();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar cambios' }).disabled).toBe(true);

        // Es el del chat web: el primero de los dos grupos.
        for (const radio of screen.getAllByLabelText('Borrador para revisar').slice(0, 1)) await user.click(radio);
        await user.selectOptions(screen.getByLabelText('Modelo que redacta las respuestas'), 'deepseek-fast');
        await user.click(screen.getByLabelText('Presentarse como asistente virtual'));
        await user.type(screen.getByLabelText('Temas que pasan a una persona'), 'reembolso{Enter}demanda legal');
        await user.type(screen.getByLabelText('Tope diario (US$)'), '1.5');
        await user.click(screen.getByRole('button', { name: 'Guardar cambios' }));

        expect(put).toHaveBeenCalledWith(
            '/settings/ai',
            expect.objectContaining({
                reply_model: 'deepseek-fast',
                widget_mode: 'shadow',
                email_mode: 'off',
                identify_as_ai: false,
                require_knowledge: true,
                min_confidence: 70,
                escalate_topics: ['reembolso', 'demanda legal'],
                daily_spend: '1.5',
                schedule_days: [1, 2, 3, 4, 5],
            }),
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('el motivo del servidor queda junto al campo', () => {
        formErrors = { widget_mode: 'Elige una opción de la lista.', reply_model: 'Elige un modelo de la lista.', min_confidence: 'Escribe un número entre 30 y 100.', daily_spend: 'El máximo es US$ 50.', 'escalate_topics.0': 'Cada tema puede tener hasta 40 caracteres.' };
        renderPage();

        expect(screen.getByText('Elige una opción de la lista.')).toBeTruthy();
        expect(screen.getByText('Elige un modelo de la lista.')).toBeTruthy();
        expect(screen.getByText('Escribe un número entre 30 y 100.')).toBeTruthy();
        expect(screen.getByText('El máximo es US$ 50.')).toBeTruthy();
        expect(screen.getByText('Cada tema puede tener hasta 40 caracteres.')).toBeTruthy();
    });

    it('sin modelos disponibles lo explica y no ofrece una lista vacía', () => {
        renderPage({ models: [] });

        expect(screen.getByRole('alert').textContent).toContain('todavía no tiene ningún modelo');
        expect(screen.queryByLabelText('Modelo que redacta las respuestas')).toBeNull();
    });
});
