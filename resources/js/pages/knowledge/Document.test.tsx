import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Document from './Document';

let shared: Partial<SharedProps> = {};
const put = vi.fn();
const routerPost = vi.fn();
const routerDelete = vi.fn();
const refresh = vi.fn();
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const { useState: useStateReact } = await import('react');

    return {
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        router: {
            post: (url: string, body: unknown, options: unknown) => {
                routerPost(url, body, options);
            },
            delete: (url: string, options: unknown) => {
                routerDelete(url, options);
            },
        },
        useForm: (initial: { title: string; body: string; category: string }) => {
            const [data, setAll] = useStateReact(initial);
            const [defaults, setDefaults] = useStateReact(initial);

            return {
                data,
                errors: formErrors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
                setData: (keyOrAll: string | typeof initial, value?: string) => {
                    if (typeof keyOrAll === 'string') setAll((current) => ({ ...current, [keyOrAll]: value ?? '' }));
                    else setAll(keyOrAll);
                },
                setDefaults: () => {
                    setDefaults(data);
                },
                put: (url: string, options: { onSuccess?: () => void }) => {
                    put(url, options);
                    options.onSuccess?.();
                },
            };
        },
        usePage: () => ({ props: shared }),
    };
});

vi.mock('../../realtime/useKnowledgeRefresh', () => ({
    useKnowledgeRefresh: (tenantId: number | null, only: string[]) => {
        refresh(tenantId, only);

        return 'connected';
    },
}));

const copy = {
    back: 'Volver a los documentos',
    title_label: 'Título',
    category_label: 'Categoría',
    category_help: 'Opcional.',
    body_label: 'Texto extraído',
    body_help: 'Esto es lo que se leyó del documento.',
    review_notice: 'Revisa el texto antes de publicar.',
    file_info: ':name · :size · :pages',
    save: 'Guardar cambios',
    saving: 'Guardando',
    publish: 'Publicar',
    publishing: 'Publicando',
    unpublish: 'Volver a revisión',
    unpublishing: 'Pasando a revisión',
    delete: 'Eliminar',
    delete_title: '¿Eliminar «:title»?',
    delete_detail: 'La IA ya no podrá citarlo.',
    delete_confirm: 'Eliminar documento',
    deleting: 'Eliminando documento',
    cancel: 'Cancelar',
    unsaved: 'Cambios sin guardar',
    status_scanning: 'Revisando con el antivirus…',
    status_extracting: 'Leyendo el texto…',
    status_review: 'En revisión',
    status_published: 'Publicado',
    status_failed: 'No se pudo procesar',
    status_blocked: 'Bloqueado por el antivirus',
    index_pending: 'Indexando…',
    index_indexed: 'Indexado',
    index_failed: 'Error de indexación',
    index_pending_help: 'Se está preparando para que la búsqueda lo encuentre.',
    index_failed_help: 'No se pudo indexar.',
    reindex: 'Reintentar la indexación',
    processing_help: 'Estamos preparando el documento.',
    blocked_help: 'El antivirus encontró una amenaza en este archivo y lo eliminó.',
    published_help: 'El texto publicado ya no se edita.',
    read_only: 'Solo un administrador puede editar los documentos.',
    updated: 'Actualizado :date',
};

type DocumentData = Parameters<typeof Document>[0]['document'];

const base: DocumentData = {
    id: 5,
    title: 'Manual de envíos',
    original_name: 'manual.pdf',
    type: 'pdf',
    size_bytes: 2097152,
    pages: 3,
    category: 'Envíos',
    status: 'review',
    index_status: null,
    failure: null,
    updated_at: '2026-11-01T10:00:00Z',
    author: 'Ana',
    body: 'Los envíos tardan de 3 a 5 días.',
    published_at: null,
};
const limits = { title: 160, body: 300000, category: 40 };

function renderPage(document: Partial<DocumentData> = {}, canEdit = true) {
    return render(<Document copy={copy} document={{ ...base, ...document }} can_edit={canEdit} limits={limits} tenant_id={7} />);
}

describe('Document (revisión de un documento)', () => {
    beforeEach(() => {
        put.mockReset();
        routerPost.mockReset();
        routerDelete.mockReset();
        refresh.mockReset();
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
                knowledge: 'Base de conocimiento', ai: 'Asistente de IA', metrics: 'Métricas',
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
                can: { tickets: true, team: true, branding: true, plan: true, widget: true, api_keys: true, knowledge: true, ai: true, metrics: true },
            },
        };
    });

    it('muestra el estado, el archivo con su peso y sus páginas, y el aviso de que hay que revisar', () => {
        renderPage();

        expect(screen.getByRole('heading', { level: 1, name: 'Manual de envíos' })).toBeTruthy();
        expect(screen.getByText('En revisión', { selector: 'span' })).toBeTruthy();
        expect(screen.getByText('manual.pdf · 2,0 MB · 3 p.')).toBeTruthy();
        expect(screen.getByText('Revisa el texto antes de publicar.')).toBeTruthy();
    });

    it('en revisión el administrador edita el texto y no puede guardar sin cambios', async () => {
        const user = userEvent.setup();
        renderPage();

        const save = screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar cambios' });
        expect(save.disabled).toBe(true);
        expect(screen.getByLabelText<HTMLTextAreaElement>('Texto extraído').value).toBe('Los envíos tardan de 3 a 5 días.');

        await user.type(screen.getByLabelText('Texto extraído'), ' Corregido.');
        expect(screen.getByText('Cambios sin guardar')).toBeTruthy();
        await user.click(save);

        expect(put).toHaveBeenCalledWith('/knowledge/documents/5', expect.objectContaining({ preserveScroll: true }));
    });

    it('publicar pide la publicación a la ruta del documento', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Publicar' }));

        expect(routerPost).toHaveBeenCalledWith('/knowledge/documents/5/publish', {}, expect.objectContaining({ preserveScroll: true }));
        expect(put).not.toHaveBeenCalled();
    });

    it('publicar con cambios sin guardar los guarda primero', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Título'), '!');
        await user.click(screen.getByRole('button', { name: 'Publicar' }));

        expect(put).toHaveBeenCalledWith('/knowledge/documents/5', expect.anything());
        expect(routerPost).toHaveBeenCalledWith('/knowledge/documents/5/publish', {}, expect.anything());
    });

    it('no se puede publicar sin texto ni sin título', async () => {
        const user = userEvent.setup();
        renderPage({ body: '' });

        const publish = screen.getByRole<HTMLButtonElement>('button', { name: 'Publicar' });
        expect(publish.disabled).toBe(true);
        await user.type(screen.getByLabelText('Texto extraído'), 'Algo');
        expect(publish.disabled).toBe(false);
        await user.clear(screen.getByLabelText('Título'));
        expect(publish.disabled).toBe(true);
    });

    it('los errores del servidor aparecen junto a cada campo', () => {
        formErrors = { title: 'Escribe un título.', body: 'El texto es demasiado largo.', category: 'La categoría es demasiado larga.' };
        renderPage();

        expect(screen.getByText('Escribe un título.')).toBeTruthy();
        expect(screen.getByText('El texto es demasiado largo.')).toBeTruthy();
        expect(screen.getByText('La categoría es demasiado larga.')).toBeTruthy();
    });

    it('mientras se procesa solo muestra el estado, sin formulario, y se suscribe al tiempo real', () => {
        renderPage({ status: 'scanning', body: null });

        expect(screen.getByText('Estamos preparando el documento.')).toBeTruthy();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(refresh).toHaveBeenCalledWith(7, ['document']);
    });

    it('si no se está procesando no se suscribe al tiempo real', () => {
        renderPage({ status: 'review' });

        expect(refresh).toHaveBeenCalledWith(null, ['document']);
    });

    it('un documento fallido muestra el motivo', () => {
        renderPage({ status: 'failed', body: null, failure: 'Este PDF está protegido con contraseña.' });

        expect(screen.getByText('Este PDF está protegido con contraseña.')).toBeTruthy();
        expect(screen.queryByRole('textbox')).toBeNull();
    });

    it('un documento bloqueado explica que no se leyó nada', () => {
        renderPage({ status: 'blocked', body: null });

        expect(screen.getByText('El antivirus encontró una amenaza en este archivo y lo eliminó.')).toBeTruthy();
        expect(screen.getByText('Bloqueado por el antivirus', { selector: 'span' })).toBeTruthy();
    });

    it('lo publicado e indexado lo dice', () => {
        renderPage({ status: 'published', index_status: 'indexed' });

        expect(screen.getByText('Indexado')).toBeTruthy();
        expect(refresh).toHaveBeenCalledWith(null, ['document']);
    });

    it('mientras se indexa lo explica y se suscribe al tiempo real', () => {
        renderPage({ status: 'published', index_status: 'pending' });

        expect(screen.getByText('Se está preparando para que la búsqueda lo encuentre.')).toBeTruthy();
        expect(refresh).toHaveBeenCalledWith(7, ['document']);
    });

    it('con error de indexación el administrador puede reintentarla', async () => {
        const user = userEvent.setup();
        renderPage({ status: 'published', index_status: 'failed' });

        await user.click(screen.getByRole('button', { name: 'Reintentar la indexación' }));

        expect(routerPost).toHaveBeenCalledWith('/knowledge/documents/5/reindex', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('quien solo lee no ve el botón para reintentar la indexación', () => {
        renderPage({ status: 'published', index_status: 'failed' }, false);

        expect(screen.queryByRole('button', { name: 'Reintentar la indexación' })).toBeNull();
    });

    it('si falló la indexación lo dice con una advertencia', () => {
        renderPage({ status: 'published', index_status: 'failed' });

        expect(screen.getByText('No se pudo indexar.')).toBeTruthy();
    });

    it('lo publicado se ve como texto, sin campos, y ofrece volver a revisión', async () => {
        const user = userEvent.setup();
        renderPage({ status: 'published' });

        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.getByText('Los envíos tardan de 3 a 5 días.')).toBeTruthy();
        expect(screen.getByText('El texto publicado ya no se edita.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Publicar' })).toBeNull();
        await user.click(screen.getByRole('button', { name: 'Volver a revisión' }));

        expect(routerPost).toHaveBeenCalledWith('/knowledge/documents/5/unpublish', {}, expect.anything());
    });

    it('el texto del documento con HTML se ve como texto', () => {
        const { container } = renderPage({ status: 'published', body: '<img src=x onerror=alert(1)> <script>alert(1)</script>' });

        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(container.querySelector('script')).toBeNull();
        expect(screen.getByText('<img src=x onerror=alert(1)> <script>alert(1)</script>')).toBeTruthy();
    });

    it('lo publicado se encabeza como «Texto extraído» y no repite el título de la página', () => {
        renderPage({ status: 'published' }, false);

        expect(screen.getAllByRole('heading', { name: 'Manual de envíos' })).toHaveLength(1);
        expect(screen.getByRole('heading', { level: 2, name: 'Texto extraído' })).toBeTruthy();
        expect(screen.queryByRole('alertdialog', { hidden: true })).toBeNull();
    });

    it('el peso de un archivo pequeño se dice en kilobytes', () => {
        renderPage({ size_bytes: 5120 });

        expect(screen.getByText('manual.pdf · 5 KB · 3 p.')).toBeTruthy();
    });

    it('quien solo lee ve el texto sin campos ni botones de escritura', () => {
        renderPage({}, false);

        expect(screen.getByText('Solo un administrador puede editar los documentos.')).toBeTruthy();
        expect(screen.getByText('Los envíos tardan de 3 a 5 días.')).toBeTruthy();
        expect(screen.queryByLabelText('Título')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Publicar' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Eliminar' })).toBeNull();
    });

    it('eliminar pide confirmación con el título y borra la ruta del documento', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Eliminar' }));
        const dialog = screen.getByRole('alertdialog', { hidden: true });
        expect(within(dialog).getByText('¿Eliminar «Manual de envíos»?')).toBeTruthy();
        expect(routerDelete).not.toHaveBeenCalled();

        await user.click(within(dialog).getByRole('button', { name: 'Eliminar documento', hidden: true }));

        expect(routerDelete).toHaveBeenCalledWith('/knowledge/documents/5', expect.anything());
    });

    it('se puede eliminar un documento fallido', () => {
        renderPage({ status: 'failed', body: null, failure: 'No se pudo.' });

        expect(screen.getByRole('button', { name: 'Eliminar' })).toBeTruthy();
    });

    it('vuelve a la lista de documentos', () => {
        renderPage();

        expect(screen.getByRole('link', { name: 'Volver a los documentos' }).getAttribute('href')).toBe('/knowledge/documents');
    });

    it('cuando llega el texto de la lectura el formulario se llena con él', () => {
        const { rerender } = renderPage({ status: 'extracting', body: null });

        rerender(<Document copy={copy} document={{ ...base, status: 'review', body: 'Texto recién leído.' }} can_edit limits={limits} tenant_id={7} />);

        expect(screen.getByLabelText<HTMLTextAreaElement>('Texto extraído').value).toBe('Texto recién leído.');
    });
});
