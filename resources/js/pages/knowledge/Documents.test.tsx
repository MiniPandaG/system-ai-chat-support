import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Documents from './Documents';
import type { DocumentRow } from './Documents';

let shared: Partial<SharedProps> = {};
let connection = 'connected';
const post = vi.fn();
const reload = vi.fn();
const refresh = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        post: (url: string, body: unknown, options: unknown) => {
            post(url, body, options);
        },
        reload: (options: unknown) => {
            reload(options);
        },
    },
    useForm: () => ({ post: vi.fn(), processing: false }),
    usePage: () => ({ props: shared }),
}));

vi.mock('../../realtime/useKnowledgeRefresh', () => ({
    useKnowledgeRefresh: (tenantId: number | null, only: string[]) => {
        refresh(tenantId, only);

        return connection;
    },
}));

const copy = {
    title: 'Documentos',
    detail: 'Sube PDF, TXT o Markdown.',
    tabs_label: 'Secciones de la base de conocimiento',
    tab_articles: 'Artículos',
    tab_documents: 'Documentos',
    tab_context: 'Contexto',
    list_label: 'Documentos',
    count: ':total de :max documentos.',
    upload_title: 'Subir un documento',
    upload: {
        drop: 'Arrastra un documento o',
        select: 'selecciona uno',
        help: 'PDF, TXT o Markdown, máximo :max MB y 150 páginas.',
        uploading: 'Subiendo :name…',
        progress: ':percent % subido',
        cancel: 'Cancelar la subida',
        too_large: 'Este documento pesa :size MB; el máximo es :max MB.',
        wrong_type: 'Solo se admiten documentos PDF, TXT o Markdown.',
    },
    readonly_upload: 'Solo un administrador puede subir documentos.',
    empty_title: 'Todavía no hay documentos.',
    empty_detail: 'Sube un PDF o un texto con la información de tu negocio.',
    updated: 'Actualizado :date',
    by: 'Por :name',
    pages: ':count páginas',
    page: 'Página :current de :last',
    previous: 'Anterior',
    next: 'Siguiente',
    pagination_label: 'Paginación de documentos',
    live_off: 'Sin conexión en vivo: el estado de los documentos se actualiza al recargar.',
    refresh: 'Actualizar',
    status_scanning: 'Revisando con el antivirus…',
    status_extracting: 'Leyendo el texto…',
    status_review: 'En revisión',
    status_published: 'Publicado',
    status_failed: 'No se pudo procesar',
    status_blocked: 'Bloqueado por el antivirus',
    index_pending: 'Indexando…',
    index_indexed: 'Indexado',
    index_failed: 'Error de indexación',
};

function row(overrides: Partial<DocumentRow> = {}): DocumentRow {
    return { id: 1, title: 'Manual de envíos', original_name: 'manual.pdf', type: 'pdf', size_bytes: 2097152, pages: 12, category: null, status: 'review', index_status: null, failure: null, updated_at: '2026-11-01T10:00:00Z', author: 'Ana Torres', ...overrides };
}

function fileInput(container: HTMLElement): HTMLInputElement {
    const input = container.querySelector<HTMLInputElement>('input[type="file"]');
    if (input === null) throw new Error('Falta el campo de archivo.');

    return input;
}

function renderPage(overrides: Partial<Parameters<typeof Documents>[0]> = {}) {
    return render(
        <Documents
            copy={copy}
            documents={[row()]}
            pagination={{ current: 1, last: 1, total: 1, previous: null, next: null }}
            can_manage
            total={1}
            max={100}
            max_bytes={26214400}
            tenant_id={7}
            {...overrides}
        />,
    );
}

describe('Documents (documentos de la base de conocimiento)', () => {
    beforeEach(() => {
        post.mockReset();
        reload.mockReset();
        refresh.mockReset();
        connection = 'connected';
        shared = {
            errors: {},
            flash: { status: null, recovery_codes: null },
            panel: {
                nav_label: 'Navegación principal',
                account: 'Mi cuenta',
                security: 'Seguridad',
                inbox: 'Bandeja',
                inbox_unassigned: ':count sin asignar',
                knowledge: 'Base de conocimiento',
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
                can: { tickets: true, team: true, branding: true, plan: true, widget: true, api_keys: true, knowledge: true },
            },
        };
    });

    it('lista cada documento con su estado, su archivo, su peso, sus páginas y su autor, como un enlace', () => {
        renderPage();

        const link = screen.getByRole('link', { name: /Manual de envíos/ });
        expect(link.getAttribute('href')).toBe('/knowledge/documents/1');
        expect(link.textContent).toContain('En revisión');
        expect(link.textContent).toContain('manual.pdf');
        expect(link.textContent).toContain('2,0 MB');
        expect(link.textContent).toContain('12 páginas');
        expect(link.textContent).toContain('Por Ana Torres');
        expect(screen.getByText('1 de 100 documentos.')).toBeTruthy();
    });

    it('dice cada estado con palabras y, si falló, el motivo', () => {
        renderPage({
            documents: [
                row({ id: 1, title: 'A', status: 'scanning' }),
                row({ id: 2, title: 'B', status: 'extracting' }),
                row({ id: 3, title: 'C', status: 'published' }),
                row({ id: 4, title: 'D', status: 'blocked' }),
                row({ id: 5, title: 'E', status: 'failed', failure: 'Este PDF está protegido con contraseña.' }),
            ],
        });

        expect(screen.getByText('Revisando con el antivirus…')).toBeTruthy();
        expect(screen.getByText('Leyendo el texto…')).toBeTruthy();
        expect(screen.getByText('Publicado')).toBeTruthy();
        expect(screen.getByText('Bloqueado por el antivirus')).toBeTruthy();
        expect(screen.getByText('No se pudo procesar')).toBeTruthy();
        expect(screen.getByText('Este PDF está protegido con contraseña.')).toBeTruthy();
    });

    it('el peso de un archivo pequeño se dice en bytes o kilobytes, no como 0,0 MB', () => {
        renderPage({ documents: [row({ id: 1, title: 'A', size_bytes: 98 }), row({ id: 2, title: 'B', size_bytes: 5120 })] });

        expect(screen.getByText('98 B')).toBeTruthy();
        expect(screen.getByText('5 KB')).toBeTruthy();
        expect(screen.queryByText('0,0 MB')).toBeNull();
    });

    it('lo publicado muestra su estado de indexación', () => {
        renderPage({ documents: [row({ id: 1, title: 'A', status: 'published', index_status: 'indexed' }), row({ id: 2, title: 'B', status: 'published', index_status: 'pending' }), row({ id: 3, title: 'C', status: 'published', index_status: 'failed' })] });

        expect(screen.getByText('Indexado')).toBeTruthy();
        expect(screen.getByText('Indexando…')).toBeTruthy();
        expect(screen.getByText('Error de indexación')).toBeTruthy();
    });

    it('un nombre de archivo con HTML se ve como texto', () => {
        const { container } = renderPage({ documents: [row({ original_name: '<img src=x onerror=alert(1)>.pdf' })] });

        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(screen.getByText('<img src=x onerror=alert(1)>.pdf')).toBeTruthy();
    });

    it('se suscribe al tiempo real de la empresa y pide solo lo que muestra', () => {
        renderPage();

        expect(refresh).toHaveBeenCalledWith(7, ['documents', 'total', 'pagination']);
    });

    it('con algo en proceso y sin conexión en vivo avisa y ofrece «Actualizar»', async () => {
        connection = 'unavailable';
        const user = userEvent.setup();
        renderPage({ documents: [row({ status: 'scanning' })] });

        expect(screen.getByText(/Sin conexión en vivo: el estado de los documentos/)).toBeTruthy();
        await user.click(screen.getByRole('button', { name: 'Actualizar' }));

        expect(reload).toHaveBeenCalledWith({ only: ['documents', 'total', 'pagination'] });
    });

    it('sin nada en proceso, o con conexión, no muestra el aviso', () => {
        connection = 'unavailable';
        const { unmount } = renderPage({ documents: [row({ status: 'review' })] });
        expect(screen.queryByRole('button', { name: 'Actualizar' })).toBeNull();
        unmount();

        connection = 'connected';
        renderPage({ documents: [row({ status: 'scanning' })] });
        expect(screen.queryByRole('button', { name: 'Actualizar' })).toBeNull();
    });

    it('el administrador ve la zona de subida con los formatos y el peso a la vista', () => {
        renderPage();

        expect(screen.getByRole('heading', { name: 'Subir un documento' })).toBeTruthy();
        expect(screen.getByText('PDF, TXT o Markdown, máximo 25 MB y 150 páginas.')).toBeTruthy();
        expect(screen.queryByText('Solo un administrador puede subir documentos.')).toBeNull();
    });

    it('quien solo lee no ve la zona de subida', () => {
        renderPage({ can_manage: false });

        expect(screen.queryByRole('heading', { name: 'Subir un documento' })).toBeNull();
        expect(screen.getByText('Solo un administrador puede subir documentos.')).toBeTruthy();
    });

    it('elegir un PDF lo sube como formulario', async () => {
        const user = userEvent.setup();
        const { container } = renderPage();
        const file = new File(['%PDF-1.4'], 'guia.pdf', { type: 'application/pdf' });

        await user.upload(fileInput(container), file);

        expect(post).toHaveBeenCalledWith('/knowledge/documents', { file }, expect.objectContaining({ forceFormData: true, preserveScroll: true }));
    });

    it('un Markdown sin tipo declarado (como lo manda Windows) se acepta y el servidor decide', async () => {
        const user = userEvent.setup();
        const { container } = renderPage();
        const file = new File(['# Hola'], 'guia.md', { type: '' });

        await user.upload(fileInput(container), file);

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('un archivo de otro tipo se rechaza en el navegador, sin subirlo', async () => {
        const user = userEvent.setup({ applyAccept: false });
        const { container } = renderPage();

        await user.upload(fileInput(container), new File(['MZ'], 'virus.exe', { type: 'application/x-msdownload' }));

        expect(post).not.toHaveBeenCalled();
        expect(screen.getByText('Solo se admiten documentos PDF, TXT o Markdown.')).toBeTruthy();
    });

    it('un archivo demasiado grande se rechaza en el navegador diciendo cuánto pesa y cuánto se admite', async () => {
        const user = userEvent.setup();
        const { container } = renderPage({ max_bytes: 1048576 });
        const file = new File(['x'], 'enorme.pdf', { type: 'application/pdf' });
        Object.defineProperty(file, 'size', { value: 5 * 1048576 });

        await user.upload(fileInput(container), file);

        expect(post).not.toHaveBeenCalled();
        expect(screen.getByText('Este documento pesa 5,0 MB; el máximo es 1,0 MB.')).toBeTruthy();
    });

    it('el error que devuelve el servidor aparece junto a la zona de subida', () => {
        shared = { ...shared, errors: { file: 'La extensión del archivo no corresponde a lo que contiene.' } };
        renderPage();

        expect(screen.getByText('La extensión del archivo no corresponde a lo que contiene.')).toBeTruthy();
    });

    it('sin documentos explica qué hacer', () => {
        renderPage({ documents: [], total: 0 });

        expect(screen.getByText('Todavía no hay documentos.')).toBeTruthy();
    });

    it('la navegación marca «Base de conocimiento» y la pestaña «Documentos»', () => {
        renderPage();

        expect(screen.getAllByRole('link', { name: /Base de conocimiento/ }).some((link) => link.getAttribute('aria-current') === 'page')).toBe(true);
        expect(screen.getByRole('link', { name: 'Documentos' }).getAttribute('aria-current')).toBe('page');
    });

    it('con varias páginas muestra la paginación', () => {
        renderPage({ pagination: { current: 2, last: 3, total: 60, previous: '/knowledge/documents?page=1', next: '/knowledge/documents?page=3' } });

        expect(screen.getByRole('navigation', { name: 'Paginación de documentos' }).textContent).toContain('Página 2 de 3');
        expect(screen.getByRole('link', { name: 'Anterior' }).getAttribute('href')).toBe('/knowledge/documents?page=1');
    });
});
