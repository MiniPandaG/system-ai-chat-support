import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { miniSpec } from '../../lib/openapi.test';
import type { SharedProps } from '../../types';
import ApiDocs from './ApiDocs';

let shared: Partial<SharedProps> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    useForm: () => ({ post: vi.fn(), processing: false }),
    usePage: () => ({ props: shared }),
}));

const copy = {
    title: 'Documentación de la API',
    detail: 'Todo lo que puedes hacer con tus llaves.',
    keys: 'Ir a las llaves de API',
    base: 'Dirección base',
    try_title: 'Probar con tu llave',
    try_help: 'Escribe una llave para habilitar el botón «Probar».',
    try_key: 'Llave de API',
    try_key_help: 'Solo se usa en esta pantalla.',
    permission: 'Acceso',
    permission_read: 'lectura',
    permission_write: 'escritura',
    permission_admin: 'administración',
    parameters: 'Parámetros',
    body: 'Cuerpo (JSON)',
    responses: 'Respuestas',
    required: 'obligatorio',
    optional: 'opcional',
    path_label: 'En la ruta',
    query_label: 'En la consulta',
    send: 'Probar',
    sending: 'Enviando petición',
    status: 'Respuesta',
    network_error: 'No se pudo conectar con la API.',
    invalid_json: 'El cuerpo no es JSON válido.',
    idempotency_hint: 'Añade una Idempotency-Key.',
    show: 'Mostrar la llave',
    hide: 'Ocultar la llave',
};

function renderDocs() {
    return render(<ApiDocs copy={copy} spec={miniSpec} base="https://app.tikarochat.test/api/v1" />);
}

describe('ApiDocs', () => {
    beforeEach(() => {
        window.localStorage.clear();
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

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('muestra la introducción sin interpretar HTML, la dirección base y los grupos de rutas', () => {
        renderDocs();

        expect(screen.getByRole('heading', { name: /API de prueba/ })).toBeTruthy();
        expect(screen.getByText('API').tagName).toBe('STRONG');
        expect(screen.getByText('código').tagName).toBe('CODE');
        expect(screen.getByText('https://app.tikarochat.test/api/v1')).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Tickets' })).toBeTruthy();
        expect(screen.getByRole('heading', { name: 'Clientes' })).toBeTruthy();
        expect(screen.queryByRole('heading', { name: 'Vacía' })).toBeNull();
    });

    it('cada ruta es un botón que se expande y dice su método, su acceso y su resumen', async () => {
        const user = userEvent.setup();
        renderDocs();

        const create = screen.getByRole('button', { name: /POST.*\/tickets.*Crear un ticket/ });
        expect(create.getAttribute('aria-expanded')).toBe('false');
        expect(create.textContent).toContain('Acceso: escritura');

        await user.click(create);

        expect(create.getAttribute('aria-expanded')).toBe('true');
        expect(screen.getByText('Cuerpo (JSON)', { selector: 'h4' })).toBeTruthy();
        expect(screen.getByText('subject')).toBeTruthy();
        expect(screen.getByText(/201 Creado\./)).toBeTruthy();
    });

    it('lista los parámetros resueltos de una consulta', async () => {
        const user = userEvent.setup();
        renderDocs();

        await user.click(screen.getByRole('button', { name: /GET.*\/tickets.*Listar tickets/ }));

        const list = screen.getByText('Parámetros', { selector: 'h4' }).parentElement;
        if (list === null) {
            throw new Error('Los parámetros no tienen contenedor.');
        }
        expect(within(list).getByText('limit')).toBeTruthy();
        expect(within(list).getByText('status')).toBeTruthy();
        expect(within(list).getByText(/Cuántos\./)).toBeTruthy();
    });

    it('«Probar» está deshabilitado sin llave y con ella llama a la API de esta instalación sin cookies', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: [], next_cursor: null }), { status: 200 }));
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderDocs();
        await user.click(screen.getByRole('button', { name: /GET.*\/tickets.*Listar tickets/ }));

        const send = screen.getByRole<HTMLButtonElement>('button', { name: 'Probar' });
        expect(send.disabled).toBe(true);

        await user.type(screen.getByLabelText('Llave de API'), 'tk_1_secreto');
        await user.type(screen.getByLabelText('En la consulta: status'), 'open');
        await user.click(send);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe('https://app.tikarochat.test/api/v1/tickets?status=open');
        expect(init.method).toBe('GET');
        expect(init.credentials).toBe('omit');
        expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tk_1_secreto');
        expect(init.body).toBeUndefined();
        expect(await screen.findByText('200')).toBeTruthy();
        expect(screen.getByText(/"next_cursor": null/)).toBeTruthy();
    });

    it('una ruta con identificador en la ruta lo codifica y lo pide', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 404 }));
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderDocs();
        await user.click(screen.getByRole('button', { name: /GET.*\/customers\/\{customer\}/ }));
        await user.type(screen.getByLabelText('Llave de API'), 'tk_1_x');
        await user.type(screen.getByLabelText('En la ruta: customer'), '7/../8');

        await user.click(screen.getByRole('button', { name: 'Probar' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        expect((fetchMock.mock.calls[0] as [string])[0]).toBe('https://app.tikarochat.test/api/v1/customers/7%2F..%2F8');
        expect(await screen.findByText('404')).toBeTruthy();
    });

    it('una creación envía el cuerpo como JSON, con el ejemplo ya cargado', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response('{"data":{"id":1}}', { status: 201 }));
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderDocs();
        await user.click(screen.getByRole('button', { name: /POST.*\/tickets/ }));
        await user.type(screen.getByLabelText('Llave de API'), 'tk_1_x');

        await user.click(screen.getByRole('button', { name: 'Probar' }));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(init.method).toBe('POST');
        expect(JSON.parse(init.body as string)).toEqual({ subject: 'Hola', priority: 'normal' });
        expect((init.headers as Record<string, string>)['Content-Type']).toBe('application/json');
    });

    it('un cuerpo que no es JSON no se envía y lo dice', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const user = userEvent.setup();
        renderDocs();
        await user.click(screen.getByRole('button', { name: /POST.*\/tickets/ }));
        await user.type(screen.getByLabelText('Llave de API'), 'tk_1_x');
        const body = screen.getByLabelText('Cuerpo (JSON)');
        await user.clear(body);
        await user.type(body, '{{ no es json');

        await user.click(screen.getByRole('button', { name: 'Probar' }));

        expect(await screen.findByText('El cuerpo no es JSON válido.')).toBeTruthy();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('si la red falla lo dice sin mostrar detalles', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch https://interno')));
        const user = userEvent.setup();
        renderDocs();
        await user.click(screen.getByRole('button', { name: /GET.*\/tickets.*Listar/ }));
        await user.type(screen.getByLabelText('Llave de API'), 'tk_1_x');

        await user.click(screen.getByRole('button', { name: 'Probar' }));

        expect(await screen.findByText('No se pudo conectar con la API.')).toBeTruthy();
        expect(screen.queryByText(/interno/)).toBeNull();
    });

    it('la llave escrita no se guarda en ningún almacenamiento', async () => {
        const user = userEvent.setup();
        renderDocs();

        await user.type(screen.getByLabelText('Llave de API'), 'tk_1_secreto-que-no-se-guarda');

        for (const store of [window.localStorage, window.sessionStorage]) {
            for (let index = 0; index < store.length; index += 1) {
                const name = store.key(index) ?? '';
                expect(`${name}=${store.getItem(name) ?? ''}`).not.toContain('secreto');
            }
        }
    });

    it('el campo de la llave es de contraseña y se puede mostrar', async () => {
        const user = userEvent.setup();
        renderDocs();
        const field = screen.getByLabelText<HTMLInputElement>('Llave de API');

        expect(field.type).toBe('password');
        await user.click(screen.getByRole('button', { name: 'Mostrar la llave' }));

        expect(field.type).toBe('text');
    });
});
