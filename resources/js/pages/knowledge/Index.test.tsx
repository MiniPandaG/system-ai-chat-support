import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Index from './Index';

let shared: Partial<SharedProps> = {};
const refresh = vi.fn();
const get = vi.fn();

vi.mock('../../realtime/useKnowledgeRefresh', () => ({
    useKnowledgeRefresh: (tenantId: number | null, only: string[]) => {
        refresh(tenantId, only);

        return 'connected';
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        get: (url: string, params: unknown, options: unknown) => {
            get(url, params, options);
        },
    },
    useForm: () => ({ post: vi.fn(), processing: false }),
    usePage: () => ({ props: shared }),
}));

const copy = {
    title: 'Base de conocimiento',
    detail: 'Lo que la IA podrá citar.',
    tabs_label: 'Secciones de la base de conocimiento',
    tab_articles: 'Artículos',
    tab_documents: 'Documentos',
    tab_context: 'Contexto',
    new: 'Nuevo artículo',
    list_label: 'Artículos',
    search_label: 'Buscar por título',
    search_submit: 'Buscar',
    category_label: 'Categoría',
    category_all: 'Todas las categorías',
    status_label: 'Estado',
    status_all: 'Todos los estados',
    status_draft: 'Borrador',
    status_published: 'Publicado',
    clear_filters: 'Quitar filtros',
    empty_title: 'Todavía no hay artículos.',
    empty_detail: 'La IA no puede citar fuentes hasta que haya al menos un artículo publicado.',
    empty_cta: 'Crear el primero',
    empty_readonly: 'Un administrador puede crear el primero.',
    empty_filtered_title: 'Ningún artículo coincide con lo que buscas.',
    empty_filtered_detail: 'Prueba con otra palabra o quita los filtros.',
    count: ':total de :max artículos.',
    updated: 'Actualizado :date',
    by: 'Por :name',
    no_category: 'Sin categoría',
    previous: 'Anterior',
    next: 'Siguiente',
    page: 'Página :current de :last',
    pagination_label: 'Paginación de artículos',
    index_pending: 'Indexando…',
    index_indexed: 'Indexado',
    index_failed: 'Error de indexación',
    try_title: 'Probar la búsqueda',
    try_detail: 'Escribe una pregunta de un cliente.',
    try_label: 'Pregunta de prueba',
    try_submit: 'Probar',
    try_searching: 'Buscando',
    try_none: 'No hay nada publicado que responda a esta pregunta.',
    try_results: 'Resultados de la búsqueda',
    try_score: 'Puntaje :score',
    try_scale_note: 'El puntaje sirve para ordenar, no para saber si responde.',
    try_unavailable: 'La búsqueda no está disponible ahora.',
    try_article: 'Artículo',
    try_document: 'Documento',
};

const publishedRow = { id: 7, title: 'Cómo cambiar la dirección', category: 'Envíos', status: 'published' as const, index_status: 'indexed' as const, updated_at: '2026-11-01T10:00:00Z', author: 'Ana Torres' };
const draftRow = { id: 8, title: 'Política de reembolsos', category: null, status: 'draft' as const, index_status: null, updated_at: '2026-10-30T10:00:00Z', author: null };
const articles = [publishedRow, draftRow];

function renderPage(overrides: Partial<Parameters<typeof Index>[0]> = {}) {
    return render(
        <Index
            copy={copy}
            articles={articles}
            pagination={{ current: 1, last: 1, total: 2, previous: null, next: null }}
            filters={{ category: null, q: null, status: null }}
            categories={['Envíos', 'Pagos']}
            can_manage
            total={2}
            max={500}
            tenant_id={7}
            {...overrides}
        />,
    );
}

describe('Index (base de conocimiento)', () => {
    beforeEach(() => {
        refresh.mockReset();
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
                knowledge: 'Base de conocimiento', ai: 'Asistente de IA',
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
                can: { tickets: true, team: true, branding: true, plan: true, widget: true, api_keys: true, knowledge: true, ai: true },
            },
        };
    });

    it('lista los artículos con su estado, categoría y autor, cada uno como un enlace', () => {
        renderPage();

        const link = screen.getByRole('link', { name: /Cómo cambiar la dirección/ });
        expect(link.getAttribute('href')).toBe('/knowledge/articles/7');
        expect(link.textContent).toContain('Publicado');
        expect(link.textContent).toContain('Envíos');
        expect(link.textContent).toContain('Por Ana Torres');
        const draft = screen.getByRole('link', { name: /Política de reembolsos/ });
        expect(draft.textContent).toContain('Borrador');
        expect(draft.textContent).toContain('Sin categoría');
        expect(screen.getByText('2 de 500 artículos.')).toBeTruthy();
    });

    it('un título con HTML se ve como texto', () => {
        const { container } = renderPage({ articles: [{ ...publishedRow, title: '<img src=x onerror=alert(1)>' }] });

        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(screen.getByText('<img src=x onerror=alert(1)>')).toBeTruthy();
    });

    it('lo publicado muestra su estado de indexación y un borrador no', () => {
        renderPage();

        expect(screen.getByRole('link', { name: /Cómo cambiar la dirección/ }).textContent).toContain('Indexado');
        expect(screen.getByRole('link', { name: /Política de reembolsos/ }).textContent).not.toMatch(/Index/);
    });

    it('indexando y con error se dicen con palabras', () => {
        renderPage({ articles: [{ ...publishedRow, id: 1, title: 'Uno', index_status: 'pending' }, { ...publishedRow, id: 2, title: 'Dos', index_status: 'failed' }] });

        expect(screen.getByText('Indexando…')).toBeTruthy();
        expect(screen.getByText('Error de indexación')).toBeTruthy();
    });

    it('se suscribe al tiempo real de la empresa y pide solo lo que muestra', () => {
        renderPage();

        expect(refresh).toHaveBeenCalledWith(7, ['articles', 'pagination', 'total']);
    });

    it('incluye el panel para probar la búsqueda', () => {
        renderPage();

        expect(screen.getByRole('heading', { name: 'Probar la búsqueda' })).toBeTruthy();
        expect(screen.getByLabelText('Pregunta de prueba')).toBeTruthy();
    });

    it('el administrador ve «Nuevo artículo»; quien solo lee no', () => {
        const { unmount } = renderPage();
        expect(screen.getByRole('link', { name: 'Nuevo artículo' }).getAttribute('href')).toBe('/knowledge/articles/create');
        unmount();

        renderPage({ can_manage: false });
        expect(screen.queryByRole('link', { name: 'Nuevo artículo' })).toBeNull();
    });

    it('la navegación marca «Base de conocimiento» como la sección actual', () => {
        renderPage();

        expect(screen.getAllByRole('link', { name: /Base de conocimiento/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
    });

    it('sin artículos explica qué hacer y ofrece crear el primero', () => {
        renderPage({ articles: [], total: 0 });

        expect(screen.getByText('Todavía no hay artículos.')).toBeTruthy();
        expect(screen.getByText('La IA no puede citar fuentes hasta que haya al menos un artículo publicado.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Crear el primero' })).toBeTruthy();
    });

    it('sin artículos y sin permiso para escribir lo dice y no ofrece crear', () => {
        renderPage({ articles: [], total: 0, can_manage: false });

        expect(screen.getByText(/Un administrador puede crear el primero\./)).toBeTruthy();
        expect(screen.queryByRole('link', { name: 'Crear el primero' })).toBeNull();
    });

    it('con filtros y sin resultados ofrece quitarlos', async () => {
        const user = userEvent.setup();
        renderPage({ articles: [], filters: { category: 'Pagos', q: null, status: null } });

        expect(screen.getByText('Ningún artículo coincide con lo que buscas.')).toBeTruthy();
        const [clear] = screen.getAllByRole('button', { name: 'Quitar filtros' });
        if (clear === undefined) throw new Error('Falta el botón para quitar los filtros.');
        await user.click(clear);

        expect(get).toHaveBeenCalledWith('/knowledge', {}, expect.objectContaining({ replace: true }));
    });

    it('elegir una categoría o un estado filtra en el servidor y conserva lo demás', async () => {
        const user = userEvent.setup();
        renderPage({ filters: { category: null, q: 'envío', status: null } });

        await user.selectOptions(screen.getByLabelText('Categoría'), 'Pagos');
        expect(get).toHaveBeenLastCalledWith('/knowledge', { category: 'Pagos', q: 'envío' }, expect.objectContaining({ preserveState: true }));

        await user.selectOptions(screen.getByLabelText('Estado'), 'draft');
        expect(get).toHaveBeenLastCalledWith('/knowledge', { status: 'draft', q: 'envío' }, expect.objectContaining({ preserveState: true }));
    });

    it('buscar por título envía el texto sin espacios sobrantes', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Buscar por título'), '  reembolso  ');
        await user.click(screen.getByRole('button', { name: 'Buscar' }));

        expect(get).toHaveBeenCalledWith('/knowledge', { q: 'reembolso' }, expect.objectContaining({ replace: true }));
    });

    it('con varias páginas muestra la paginación y desactiva «Anterior» en la primera', () => {
        renderPage({ pagination: { current: 1, last: 3, total: 60, previous: null, next: '/knowledge?page=2' } });

        const nav = screen.getByRole('navigation', { name: 'Paginación de artículos' });
        expect(nav.textContent).toContain('Página 1 de 3');
        expect(screen.getByRole('link', { name: 'Siguiente' }).getAttribute('href')).toBe('/knowledge?page=2');
        expect(screen.queryByRole('link', { name: 'Anterior' })).toBeNull();
    });

    it('con una sola página no hay paginación', () => {
        renderPage();

        expect(screen.queryByRole('navigation', { name: 'Paginación de artículos' })).toBeNull();
    });
});
