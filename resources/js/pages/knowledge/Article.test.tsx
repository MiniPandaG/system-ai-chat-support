import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Article from './Article';

let shared: Partial<SharedProps> = {};
const refresh = vi.fn();
const post = vi.fn();
const put = vi.fn();
const routerPost = vi.fn();
const routerDelete = vi.fn();
let formErrors: Record<string, string> = {};
let succeed = true;

vi.mock('../../realtime/useKnowledgeRefresh', () => ({
    useKnowledgeRefresh: (tenantId: number | null, only: string[]) => {
        refresh(tenantId, only);

        return 'connected';
    },
}));

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

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
            const [data, setData] = useState(initial);
            const [defaults, setDefaults] = useState(initial);

            return {
                data,
                errors: formErrors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
                setData: (key: 'title' | 'body' | 'category', value: string) => {
                    setData((current) => ({ ...current, [key]: value }));
                },
                setDefaults: () => {
                    setDefaults(data);
                },
                post: (url: string, options: { onSuccess?: () => void }) => {
                    post(url, options);
                    if (succeed) options.onSuccess?.();
                },
                put: (url: string, options: { onSuccess?: () => void }) => {
                    put(url, options);
                    if (succeed) options.onSuccess?.();
                },
            };
        },
        usePage: () => ({ props: shared }),
    };
});

const copy = {
    back: 'Volver a la base de conocimiento',
    new_title: 'Nuevo artículo',
    title_label: 'Título',
    category_label: 'Categoría',
    category_help: 'Opcional.',
    body_label: 'Contenido',
    body_help: 'Se escribe en Markdown.',
    tab_write: 'Escribir',
    tab_preview: 'Vista previa',
    tabs_label: 'Modo del contenido',
    preview_empty: 'Todavía no hay nada que mostrar.',
    save_draft: 'Guardar borrador',
    save_changes: 'Guardar cambios',
    saving: 'Guardando',
    publish: 'Publicar',
    publishing: 'Publicando',
    unpublish: 'Volver a borrador',
    unpublishing: 'Pasando a borrador',
    delete: 'Eliminar',
    delete_title: '¿Eliminar «:title»?',
    delete_detail: 'La IA ya no podrá citarlo.',
    delete_confirm: 'Eliminar artículo',
    deleting: 'Eliminando artículo',
    cancel: 'Cancelar',
    autosaved: 'Guardado hace un momento',
    unsaved: 'Cambios sin guardar',
    status_draft: 'Borrador',
    status_published: 'Publicado',
    status_draft_help: 'Un borrador no lo usa la IA.',
    status_published_help: 'Un artículo publicado es parte de lo que la IA podrá citar.',
    updated: 'Actualizado :date',
    read_only: 'Solo un administrador puede editar los artículos.',
    index_pending: 'Indexando…',
    index_indexed: 'Indexado',
    index_failed: 'Error de indexación',
    index_pending_help: 'Se está preparando para que la búsqueda lo encuentre.',
    index_failed_help: 'No se pudo indexar.',
    reindex: 'Reintentar la indexación',
};

type ArticleData = NonNullable<Parameters<typeof Article>[0]['article']>;

const draft: ArticleData = { id: 7, title: 'Cambio de dirección', body: 'Entra a **Pedidos**.', category: 'Envíos', status: 'draft', index_status: null, updated_at: '2026-11-01T10:00:00Z', published_at: null };
const published: ArticleData = { ...draft, status: 'published', index_status: 'indexed', published_at: '2026-11-01T11:00:00Z' };
const limits = { title: 160, body: 50000, category: 40 };

function renderPage(overrides: Partial<Parameters<typeof Article>[0]> = {}) {
    return render(<Article copy={copy} article={draft} can_edit categories={['Envíos', 'Pagos']} limits={limits} tenant_id={7} {...overrides} />);
}

describe('Article (editor de la base de conocimiento)', () => {
    beforeEach(() => {
        refresh.mockReset();
        post.mockReset();
        put.mockReset();
        routerPost.mockReset();
        routerDelete.mockReset();
        formErrors = {};
        succeed = true;
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

    afterEach(() => {
        vi.useRealTimers();
    });

    it('un artículo nuevo pide título, no ofrece publicar ni eliminar y crea con un envío explícito', async () => {
        const user = userEvent.setup();
        renderPage({ article: null });

        expect(screen.getByRole('heading', { level: 1, name: 'Nuevo artículo' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Publicar' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Eliminar' })).toBeNull();
        const save = screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar borrador' });
        expect(save.disabled).toBe(true);

        await user.type(screen.getByLabelText('Título'), 'Política de envíos');
        await user.click(save);

        expect(post).toHaveBeenCalledWith('/knowledge/articles', expect.objectContaining({ preserveScroll: true }));
    });

    it('un borrador existente muestra su estado y las acciones de borrador', () => {
        renderPage();

        expect(screen.getByRole('heading', { level: 1, name: 'Cambio de dirección' })).toBeTruthy();
        expect(screen.getByText('Borrador', { selector: 'span' })).toBeTruthy();
        expect(screen.getByText('Un borrador no lo usa la IA.')).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Publicar' })).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Eliminar' })).toBeTruthy();
    });

    it('un artículo publicado e indexado lo dice y no pide actualizar solo', () => {
        renderPage({ article: published });

        expect(screen.getByText('Indexado')).toBeTruthy();
        expect(refresh).toHaveBeenCalledWith(null, ['article']);
    });

    it('mientras se indexa lo explica y se suscribe al tiempo real para actualizarse', () => {
        renderPage({ article: { ...published, index_status: 'pending' } });

        expect(screen.getByText('Indexando…')).toBeTruthy();
        expect(screen.getByText('Se está preparando para que la búsqueda lo encuentre.')).toBeTruthy();
        expect(refresh).toHaveBeenCalledWith(7, ['article']);
    });

    it('si falló la indexación lo dice con una advertencia', () => {
        renderPage({ article: { ...published, index_status: 'failed' } });

        expect(screen.getByText('Error de indexación')).toBeTruthy();
        expect(screen.getByText('No se pudo indexar.')).toBeTruthy();
    });

    it('con error de indexación el administrador puede reintentarla', async () => {
        const user = userEvent.setup();
        renderPage({ article: { ...published, index_status: 'failed' } });

        await user.click(screen.getByRole('button', { name: 'Reintentar la indexación' }));

        expect(routerPost).toHaveBeenCalledWith('/knowledge/articles/7/reindex', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('sin error de indexación no ofrece reintentarla', () => {
        renderPage({ article: published });

        expect(screen.queryByRole('button', { name: 'Reintentar la indexación' })).toBeNull();
    });

    it('un borrador no muestra estado de indexación', () => {
        renderPage();

        expect(screen.queryByText('Indexado')).toBeNull();
        expect(screen.queryByText('Indexando…')).toBeNull();
    });

    it('un artículo publicado ofrece guardar cambios y volver a borrador, no publicar', () => {
        renderPage({ article: published });

        expect(screen.getByText('Un artículo publicado es parte de lo que la IA podrá citar.')).toBeTruthy();
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Guardar cambios' }).disabled).toBe(true);
        expect(screen.getByRole('button', { name: 'Volver a borrador' })).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Publicar' })).toBeNull();
    });

    it('publicar pide la publicación a la ruta del artículo', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Publicar' }));

        expect(routerPost).toHaveBeenCalledWith('/knowledge/articles/7/publish', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('publicar con cambios sin guardar los guarda primero', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Título'), ' nuevo');
        await user.click(screen.getByRole('button', { name: 'Publicar' }));

        expect(put).toHaveBeenCalledWith('/knowledge/articles/7', expect.anything());
        expect(routerPost).toHaveBeenCalledWith('/knowledge/articles/7/publish', {}, expect.anything());
    });

    it('si guardar falla antes de publicar, no publica', async () => {
        succeed = false;
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Título'), ' nuevo');
        await user.click(screen.getByRole('button', { name: 'Publicar' }));

        expect(put).toHaveBeenCalled();
        expect(routerPost).not.toHaveBeenCalled();
    });

    it('no se puede publicar sin contenido', async () => {
        const user = userEvent.setup();
        renderPage({ article: { ...draft, body: '' } });

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Publicar' }).disabled).toBe(true);
        await user.type(screen.getByLabelText('Contenido'), 'Algo');
        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Publicar' }).disabled).toBe(false);
    });

    it('volver a borrador pide el cambio de estado', async () => {
        const user = userEvent.setup();
        renderPage({ article: published });

        await user.click(screen.getByRole('button', { name: 'Volver a borrador' }));

        expect(routerPost).toHaveBeenCalledWith('/knowledge/articles/7/unpublish', {}, expect.anything());
    });

    it('eliminar pide confirmación con el título y borra la ruta del artículo', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.click(screen.getByRole('button', { name: 'Eliminar' }));
        const dialog = screen.getByRole('alertdialog', { hidden: true });
        expect(within(dialog).getByText('¿Eliminar «Cambio de dirección»?')).toBeTruthy();
        expect(routerDelete).not.toHaveBeenCalled();

        await user.click(within(dialog).getByRole('button', { name: 'Eliminar artículo', hidden: true }));

        expect(routerDelete).toHaveBeenCalledWith('/knowledge/articles/7', expect.anything());
    });

    it('la vista previa da formato al contenido y no ejecuta HTML', async () => {
        const user = userEvent.setup();
        const { container } = renderPage({ article: { ...draft, body: 'Texto **fuerte** <script>window.pwn = 1</script>' } });

        await user.click(screen.getByRole('tab', { name: 'Vista previa' }));

        expect(screen.getByText('fuerte').tagName).toBe('STRONG');
        expect(container.querySelector('script')).toBeNull();
        expect(screen.getByRole('tab', { name: 'Vista previa' }).getAttribute('aria-selected')).toBe('true');
    });

    it('la vista previa sin contenido lo dice', async () => {
        const user = userEvent.setup();
        renderPage({ article: { ...draft, body: '' } });

        await user.click(screen.getByRole('tab', { name: 'Vista previa' }));

        expect(screen.getByText('Todavía no hay nada que mostrar.')).toBeTruthy();
    });

    it('las flechas cambian entre «Escribir» y «Vista previa» y mueven el foco', async () => {
        const user = userEvent.setup();
        renderPage();
        const write = screen.getByRole('tab', { name: 'Escribir' });

        write.focus();
        await user.keyboard('{ArrowRight}');
        expect(screen.getByRole('tab', { name: 'Vista previa' }).getAttribute('aria-selected')).toBe('true');
        expect(document.activeElement).toBe(screen.getByRole('tab', { name: 'Vista previa' }));

        await user.keyboard('{ArrowLeft}');
        expect(screen.getByRole('tab', { name: 'Escribir' }).getAttribute('aria-selected')).toBe('true');
    });

    it('con cambios avisa «Cambios sin guardar»', async () => {
        const user = userEvent.setup();
        renderPage();

        await user.type(screen.getByLabelText('Título'), '!');

        expect(screen.getByText('Cambios sin guardar')).toBeTruthy();
    });

    it('los errores del servidor aparecen junto a cada campo', () => {
        formErrors = { title: 'Escribe un título para el artículo.', body: 'El contenido es demasiado largo.', category: 'La categoría es demasiado larga.' };
        renderPage();

        expect(screen.getByText('Escribe un título para el artículo.')).toBeTruthy();
        expect(screen.getByText('El contenido es demasiado largo.')).toBeTruthy();
        expect(screen.getByText('La categoría es demasiado larga.')).toBeTruthy();
    });

    it('sugiere las categorías que ya existen', () => {
        const { container } = renderPage();

        expect(Array.from(container.querySelectorAll('datalist option')).map((option) => option.getAttribute('value'))).toEqual(['Envíos', 'Pagos']);
    });

    it('un borrador se autoguarda a los 10 segundos sin escribir y avisa', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage();

        await user.type(screen.getByLabelText('Título'), '!');
        expect(put).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(9_000);
        });
        expect(put).not.toHaveBeenCalled();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1_500);
        });

        expect(put).toHaveBeenCalledTimes(1);
        expect(screen.getByText('Guardado hace un momento')).toBeTruthy();
    });

    it('seguir escribiendo reinicia la espera del autoguardado', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage();

        await user.type(screen.getByLabelText('Título'), 'a');
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8_000);
        });
        await user.type(screen.getByLabelText('Título'), 'b');
        await act(async () => {
            await vi.advanceTimersByTimeAsync(8_000);
        });
        expect(put).not.toHaveBeenCalled();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2_500);
        });
        expect(put).toHaveBeenCalledTimes(1);
    });

    it('un artículo publicado no se autoguarda', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ article: published });

        await user.type(screen.getByLabelText('Título'), '!');
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30_000);
        });

        expect(put).not.toHaveBeenCalled();
    });

    it('un artículo nuevo no se autoguarda', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ article: null });

        await user.type(screen.getByLabelText('Título'), 'Algo');
        await act(async () => {
            await vi.advanceTimersByTimeAsync(30_000);
        });

        expect(post).not.toHaveBeenCalled();
        expect(put).not.toHaveBeenCalled();
    });

    it('quien solo lee ve el artículo ya formateado, sin campos ni botones de escritura', () => {
        renderPage({ can_edit: false, article: { ...draft, body: '# Pasos\n\nEntra a **Pedidos**.' } });

        expect(screen.getByRole('heading', { level: 1, name: 'Cambio de dirección' })).toBeTruthy();
        expect(screen.getByRole('heading', { level: 2, name: 'Pasos' })).toBeTruthy();
        expect(screen.getByText('Solo un administrador puede editar los artículos.')).toBeTruthy();
        expect(screen.queryByLabelText('Título')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Eliminar' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Publicar' })).toBeNull();
    });

    it('quien solo lee no tiene ni siquiera el diálogo de eliminar en la página', () => {
        renderPage({ can_edit: false });

        expect(screen.queryByRole('alertdialog', { hidden: true })).toBeNull();
    });

    it('un título con HTML se ve como texto', () => {
        const { container } = renderPage({ can_edit: false, article: { ...draft, title: '<img src=x onerror=alert(1)>' } });

        expect(container.querySelector('img[src="x"]')).toBeNull();
        expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('<img src=x onerror=alert(1)>');
    });
});
