import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { IndexStatusBadge } from '../../components/IndexStatusBadge';
import type { IndexState } from '../../components/IndexStatusBadge';
import { Button } from '../../components/Button';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { MarkdownView } from '../../components/MarkdownView';
import { TextAreaField } from '../../components/TextAreaField';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import { useKnowledgeRefresh } from '../../realtime/useKnowledgeRefresh';
import type { SharedProps } from '../../types';

interface ArticleData {
    id: number;
    title: string;
    body: string;
    category: string | null;
    status: 'draft' | 'published';
    index_status: IndexState | null;
    updated_at: string;
    published_at: string | null;
}

interface ArticleCopy {
    back: string;
    new_title: string;
    title_label: string;
    category_label: string;
    category_help: string;
    body_label: string;
    body_help: string;
    tab_write: string;
    tab_preview: string;
    tabs_label: string;
    preview_empty: string;
    save_draft: string;
    save_changes: string;
    saving: string;
    publish: string;
    publishing: string;
    unpublish: string;
    unpublishing: string;
    delete: string;
    delete_title: string;
    delete_detail: string;
    delete_confirm: string;
    deleting: string;
    cancel: string;
    autosaved: string;
    unsaved: string;
    status_draft: string;
    status_published: string;
    status_draft_help: string;
    status_published_help: string;
    updated: string;
    read_only: string;
    index_pending: string;
    index_indexed: string;
    index_failed: string;
    index_pending_help: string;
    index_failed_help: string;
    reindex: string;
}

interface ArticleProps {
    copy: ArticleCopy;
    article: ArticleData | null;
    can_edit: boolean;
    categories: string[];
    limits: { title: number; body: number; category: number };
    tenant_id: number | null;
}

const AUTOSAVE_MS = 10_000;
const dateFormat = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * Un artículo (design/pantallas/base-conocimiento.md). Quien puede editar ve un editor de Markdown con vista previa;
 * quien solo puede leer ve el artículo ya formateado. Un borrador se autoguarda cada 10 segundos; un artículo publicado
 * se guarda con el botón, porque lo que ahí cambia ya es lo que la IA puede citar.
 */
export default function Article({ copy, article, can_edit: canEdit, categories, limits, tenant_id: tenantId }: ArticleProps) {
    const { props } = usePage<SharedProps>();
    const form = useForm({ title: article?.title ?? '', body: article?.body ?? '', category: article?.category ?? '' });
    const [mode, setMode] = useState<'write' | 'preview'>('write');
    const [deleting, setDeleting] = useState(false);
    const [busy, setBusy] = useState<'publish' | 'unpublish' | 'delete' | null>(null);
    const [autosaved, setAutosaved] = useState(false);
    const tabsId = useId();
    const listId = useId();
    const writeTabRef = useRef<HTMLButtonElement>(null);
    const previewTabRef = useRef<HTMLButtonElement>(null);
    const isDraft = article === null || article.status === 'draft';
    const url = article === null ? '/knowledge/articles' : `/knowledge/articles/${String(article.id)}`;
    const dirty = form.isDirty;
    // Mientras lo publicado se indexa, la pantalla se actualiza sola cuando termina.
    useKnowledgeRefresh(article?.index_status === 'pending' ? tenantId : null, ['article']);

    // Autoguardado solo de borradores que ya existen y tienen título: crear el primero es una acción explícita.
    useEffect(() => {
        if (!canEdit || article === null || !isDraft || !dirty || form.processing || form.data.title.trim() === '') return;

        const timer = window.setTimeout(() => {
            form.put(url, {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    form.setDefaults();
                    setAutosaved(true);
                },
            });
        }, AUTOSAVE_MS);

        return () => {
            window.clearTimeout(timer);
        };
        // El temporizador se reinicia con cada cambio del contenido: es lo que lo convierte en «10 s sin escribir».
        // eslint-disable-next-line @eslint-react/exhaustive-deps, react-hooks/exhaustive-deps
    }, [canEdit, article?.id, isDraft, dirty, form.data.title, form.data.body, form.data.category]);

    // Salir con cambios sin guardar pide confirmación al navegador (design/patrones/formularios.md).
    useEffect(() => {
        if (!dirty) return;

        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };
        window.addEventListener('beforeunload', warn);

        return () => {
            window.removeEventListener('beforeunload', warn);
        };
    }, [dirty]);

    const save = () => {
        setAutosaved(false);
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
            },
        };

        if (article === null) form.post(url, options);
        else form.put(url, options);
    };

    const act = (kind: 'publish' | 'unpublish') => {
        if (article === null) return;

        const run = () => {
            setBusy(kind);
            router.post(`/knowledge/articles/${String(article.id)}/${kind}`, {}, { preserveScroll: true, onFinish: () => { setBusy(null); } });
        };

        if (dirty) {
            form.put(url, {
                preserveScroll: true,
                onSuccess: () => {
                    form.setDefaults();
                    run();
                },
            });
        } else {
            run();
        }
    };

    const remove = () => {
        if (article === null) return;

        setBusy('delete');
        router.delete(`/knowledge/articles/${String(article.id)}`, { onFinish: () => { setBusy(null); setDeleting(false); } });
    };

    const onTabKey = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;

        event.preventDefault();
        const next = mode === 'write' ? 'preview' : 'write';
        setMode(next);
        (next === 'write' ? writeTabRef : previewTabRef).current?.focus();
    };

    const status = article === null ? 'draft' : article.status;
    const heading = article === null ? copy.new_title : article.title;
    const flash = props.flash.status;
    const statusBadge = (
        <>
            <Badge variant={status === 'published' ? 'success' : 'neutral'}>{status === 'published' ? copy.status_published : copy.status_draft}</Badge>
            <IndexStatusBadge status={article?.index_status ?? null} pending={copy.index_pending} indexed={copy.index_indexed} failed={copy.index_failed} />
        </>
    );
    const indexHelp = article?.index_status === 'pending' ? copy.index_pending_help : article?.index_status === 'failed' ? copy.index_failed_help : null;

    return (
        <PanelLayout title={heading} section="knowledge">
            <div className="mx-auto flex w-full max-w-[45rem] flex-col gap-6">
                <Link href="/knowledge" className="text-sm text-text-secondary underline underline-offset-2 hover:text-text">
                    {copy.back}
                </Link>

                {flash !== null && <Alert variant="success">{flash}</Alert>}

                {!canEdit && article !== null ? (
                    <article className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <div className="flex flex-wrap items-center gap-3 text-sm text-text-secondary">
                                {statusBadge}
                                {article.category !== null && (
                                    <Badge variant="neutral" shape="tag">
                                        {article.category}
                                    </Badge>
                                )}
                                <span>{copy.updated.replace(':date', dateFormat.format(new Date(article.updated_at)))}</span>
                            </div>
                        </div>
                        <Alert variant="info">{copy.read_only}</Alert>
                        <MarkdownView source={article.body} />
                    </article>
                ) : (
                    <form
                        noValidate
                        className="flex flex-col gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <div className="flex flex-wrap items-center gap-3">
                            {statusBadge}
                        </div>
                        <p className="text-sm text-text-secondary">{status === 'published' ? copy.status_published_help : copy.status_draft_help}</p>
                        {indexHelp !== null && <Alert variant={article?.index_status === 'failed' ? 'warning' : 'info'}>{indexHelp}</Alert>}

                        <TextField
                            label={copy.title_label}
                            autoComplete="off"
                            maxLength={limits.title}
                            value={form.data.title}
                            error={form.errors.title}
                            onChange={(event) => {
                                setAutosaved(false);
                                form.setData('title', event.target.value);
                            }}
                        />
                        <TextField
                            label={copy.category_label}
                            help={copy.category_help}
                            autoComplete="off"
                            list={listId}
                            maxLength={limits.category}
                            value={form.data.category}
                            error={form.errors.category}
                            onChange={(event) => {
                                setAutosaved(false);
                                form.setData('category', event.target.value);
                            }}
                        />
                        <datalist id={listId}>
                            {categories.map((category) => (
                                <option key={category} value={category} />
                            ))}
                        </datalist>

                        <div className="flex flex-col gap-2">
                            <div role="tablist" aria-label={copy.tabs_label} className="flex gap-1 border-b border-border">
                                <button
                                    ref={writeTabRef}
                                    id={`${tabsId}-write`}
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === 'write'}
                                    aria-controls={`${tabsId}-panel`}
                                    tabIndex={mode === 'write' ? 0 : -1}
                                    onClick={() => { setMode('write'); }}
                                    onKeyDown={onTabKey}
                                    className={`h-10 border-b-2 px-4 text-base font-medium ${mode === 'write' ? 'border-primary text-text' : 'border-transparent text-text-secondary hover:text-text'}`}
                                >
                                    {copy.tab_write}
                                </button>
                                <button
                                    ref={previewTabRef}
                                    id={`${tabsId}-preview`}
                                    type="button"
                                    role="tab"
                                    aria-selected={mode === 'preview'}
                                    aria-controls={`${tabsId}-panel`}
                                    tabIndex={mode === 'preview' ? 0 : -1}
                                    onClick={() => { setMode('preview'); }}
                                    onKeyDown={onTabKey}
                                    className={`h-10 border-b-2 px-4 text-base font-medium ${mode === 'preview' ? 'border-primary text-text' : 'border-transparent text-text-secondary hover:text-text'}`}
                                >
                                    {copy.tab_preview}
                                </button>
                            </div>
                            <div id={`${tabsId}-panel`} role="tabpanel" aria-labelledby={`${tabsId}-${mode}`}>
                                {mode === 'write' ? (
                                    <TextAreaField
                                        label={copy.body_label}
                                        help={copy.body_help}
                                        rows={16}
                                        maxLength={limits.body}
                                        spellCheck
                                        value={form.data.body}
                                        error={form.errors.body}
                                        onChange={(event) => {
                                            setAutosaved(false);
                                            form.setData('body', event.target.value);
                                        }}
                                    />
                                ) : (
                                    <div className="min-h-40 rounded-sm border border-border bg-surface p-4">
                                        {form.data.body.trim() === '' ? <p className="text-text-secondary">{copy.preview_empty}</p> : <MarkdownView source={form.data.body} />}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            {status === 'published' ? (
                                <Button type="submit" loading={form.processing && busy === null} loadingLabel={copy.saving} disabled={!dirty || form.data.title.trim() === ''}>
                                    {copy.save_changes}
                                </Button>
                            ) : (
                                <>
                                    <Button type="submit" variant="outline" loading={form.processing && busy === null} loadingLabel={copy.saving} disabled={form.data.title.trim() === '' || (article !== null && !dirty)}>
                                        {copy.save_draft}
                                    </Button>
                                    {article !== null && (
                                        <Button
                                            loading={busy === 'publish'}
                                            loadingLabel={copy.publishing}
                                            disabled={form.data.title.trim() === '' || form.data.body.trim() === ''}
                                            onClick={() => { act('publish'); }}
                                        >
                                            {copy.publish}
                                        </Button>
                                    )}
                                </>
                            )}
                            {article !== null && status === 'published' && (
                                <Button variant="outline" loading={busy === 'unpublish'} loadingLabel={copy.unpublishing} onClick={() => { act('unpublish'); }}>
                                    {copy.unpublish}
                                </Button>
                            )}
                            {article !== null && article.index_status === 'failed' && (
                                <Button variant="outline" onClick={() => { router.post(`/knowledge/articles/${String(article.id)}/reindex`, {}, { preserveScroll: true }); }}>
                                    {copy.reindex}
                                </Button>
                            )}
                            {article !== null && (
                                <Button variant="text" onClick={() => { setDeleting(true); }}>
                                    {copy.delete}
                                </Button>
                            )}
                            <p role="status" className={`ml-auto text-sm ${dirty ? 'text-warning' : 'text-text-tertiary'}`}>
                                {dirty ? copy.unsaved : autosaved ? copy.autosaved : ''}
                            </p>
                        </div>
                    </form>
                )}
            </div>

            {canEdit && (
                <ConfirmDialog
                open={deleting}
                title={copy.delete_title.replace(':title', article?.title ?? '')}
                detail={copy.delete_detail}
                cancelLabel={copy.cancel}
                confirmLabel={copy.delete_confirm}
                confirmingLabel={copy.deleting}
                loading={busy === 'delete'}
                onConfirm={remove}
                onCancel={() => { setDeleting(false); }}
                />
            )}
        </PanelLayout>
    );
}
