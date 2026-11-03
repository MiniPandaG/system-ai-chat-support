import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { IndexStatusBadge } from '../../components/IndexStatusBadge';
import { Button } from '../../components/Button';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { TextAreaField } from '../../components/TextAreaField';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import { formatBytes } from '../../lib/attachments';
import { useKnowledgeRefresh } from '../../realtime/useKnowledgeRefresh';
import type { SharedProps } from '../../types';
import { statusBadge } from './Documents';
import type { DocumentRow } from './Documents';

interface DocumentCopy {
    back: string;
    title_label: string;
    category_label: string;
    category_help: string;
    body_label: string;
    body_help: string;
    review_notice: string;
    file_info: string;
    save: string;
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
    unsaved: string;
    status_scanning: string;
    status_extracting: string;
    status_review: string;
    status_published: string;
    status_failed: string;
    status_blocked: string;
    index_pending: string;
    index_indexed: string;
    index_failed: string;
    index_pending_help: string;
    index_failed_help: string;
    reindex: string;
    processing_help: string;
    blocked_help: string;
    published_help: string;
    read_only: string;
    updated: string;
}

interface DocumentProps {
    copy: DocumentCopy;
    document: DocumentRow & { body: string | null; published_at: string | null };
    can_edit: boolean;
    limits: { title: number; body: number; category: number };
    tenant_id: number | null;
}

const dateFormat = new Intl.DateTimeFormat('es', { dateStyle: 'medium', timeStyle: 'short' });

/**
 * Un documento de la base de conocimiento. Mientras se procesa solo se ve su estado (la pantalla se actualiza sola); en
 * revisión una persona lee y corrige el texto extraído antes de publicarlo; lo publicado no se edita. Nada se publica
 * sin que alguien lo haya leído.
 */
export default function Document({ copy, document, can_edit: canEdit, limits, tenant_id: tenantId }: DocumentProps) {
    const { props } = usePage<SharedProps>();
    const form = useForm({ title: document.title, body: document.body ?? '', category: document.category ?? '' });
    const [deleting, setDeleting] = useState(false);
    const [busy, setBusy] = useState<'publish' | 'unpublish' | 'delete' | null>(null);
    const url = `/knowledge/documents/${String(document.id)}`;
    const badge = statusBadge(document.status, copy);
    const processing = document.status === 'scanning' || document.status === 'extracting';
    const editing = canEdit && document.status === 'review';
    const dirty = form.isDirty;
    useKnowledgeRefresh(processing || document.index_status === 'pending' ? tenantId : null, ['document']);

    // Cuando el texto llega (la lectura terminó), el formulario se llena con él; antes estaba vacío.
    useEffect(() => {
        if (!dirty) {
            form.setData({ title: document.title, body: document.body ?? '', category: document.category ?? '' });
            form.setDefaults();
        }
        // Solo cuando el servidor cambia el documento; `dirty` y `form` cambian con cada letra y no deben repetirlo.
        // eslint-disable-next-line @eslint-react/exhaustive-deps, react-hooks/exhaustive-deps
    }, [document.status, document.title, document.body, document.category]);

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

    const save = (then?: () => void) => {
        form.put(url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                then?.();
            },
        });
    };

    const act = (kind: 'publish' | 'unpublish') => {
        const run = () => {
            setBusy(kind);
            router.post(`${url}/${kind}`, {}, { preserveScroll: true, onFinish: () => { setBusy(null); } });
        };

        if (kind === 'publish' && dirty) save(run);
        else run();
    };

    const remove = () => {
        setBusy('delete');
        router.delete(url, { onFinish: () => { setBusy(null); setDeleting(false); } });
    };

    const pages = document.pages === null ? '' : `${String(document.pages)} p.`;
    const info = copy.file_info.replace(':name', document.original_name).replace(':size', formatBytes(document.size_bytes)).replace(':pages', pages).replace(/ · $/, '');
    const flash = props.flash.status;

    return (
        <PanelLayout title={document.title} section="knowledge">
            <div className="mx-auto flex w-full max-w-[45rem] flex-col gap-6">
                <Link href="/knowledge/documents" className="text-sm text-text-secondary underline underline-offset-2 hover:text-text">
                    {copy.back}
                </Link>

                {flash !== null && <Alert variant="success">{flash}</Alert>}

                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <Badge variant={badge.variant}>{badge.label}</Badge>
                        <IndexStatusBadge status={document.index_status} pending={copy.index_pending} indexed={copy.index_indexed} failed={copy.index_failed} />
                        <span className="break-all font-mono text-sm text-text-secondary">{info}</span>
                    </div>
                    <p className="text-sm text-text-secondary">{copy.updated.replace(':date', dateFormat.format(new Date(document.updated_at)))}</p>
                </div>

                {processing && <Alert variant="info">{copy.processing_help}</Alert>}
                {document.status === 'failed' && document.failure !== null && <Alert variant="danger">{document.failure}</Alert>}
                {document.status === 'blocked' && <Alert variant="danger">{copy.blocked_help}</Alert>}
                {document.status === 'review' && <Alert variant="warning">{copy.review_notice}</Alert>}
                {document.status === 'published' && <Alert variant="info">{copy.published_help}</Alert>}
                {document.index_status === 'pending' && <Alert variant="info">{copy.index_pending_help}</Alert>}
                {document.index_status === 'failed' && <Alert variant="warning">{copy.index_failed_help}</Alert>}
                {!canEdit && <Alert variant="info">{copy.read_only}</Alert>}

                {editing ? (
                    <form
                        noValidate
                        className="flex flex-col gap-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            save();
                        }}
                    >
                        <TextField label={copy.title_label} autoComplete="off" maxLength={limits.title} value={form.data.title} error={form.errors.title} onChange={(event) => { form.setData('title', event.target.value); }} />
                        <TextField label={copy.category_label} help={copy.category_help} autoComplete="off" maxLength={limits.category} value={form.data.category} error={form.errors.category} onChange={(event) => { form.setData('category', event.target.value); }} />
                        <TextAreaField label={copy.body_label} help={copy.body_help} rows={20} maxLength={limits.body} value={form.data.body} error={form.errors.body} onChange={(event) => { form.setData('body', event.target.value); }} />
                        <div className="flex flex-wrap items-center gap-3">
                            <Button type="submit" variant="outline" loading={form.processing && busy === null} loadingLabel={copy.saving} disabled={!dirty || form.data.title.trim() === ''}>
                                {copy.save}
                            </Button>
                            <Button loading={busy === 'publish'} loadingLabel={copy.publishing} disabled={form.data.title.trim() === '' || form.data.body.trim() === ''} onClick={() => { act('publish'); }}>
                                {copy.publish}
                            </Button>
                            <p role="status" className="ml-auto text-sm text-warning">
                                {dirty ? copy.unsaved : ''}
                            </p>
                        </div>
                    </form>
                ) : (
                    document.body !== null && (
                        <section className="flex flex-col gap-2" aria-label={copy.body_label}>
                            <h2 className="font-display text-lg font-semibold">{copy.body_label}</h2>
                            <pre className="whitespace-pre-wrap break-words rounded-sm border border-border bg-surface p-4 font-body text-base text-text">{document.body}</pre>
                        </section>
                    )
                )}

                {canEdit && (
                    <div className="flex flex-wrap items-center gap-3">
                        {document.status === 'published' && (
                            <Button variant="outline" loading={busy === 'unpublish'} loadingLabel={copy.unpublishing} onClick={() => { act('unpublish'); }}>
                                {copy.unpublish}
                            </Button>
                        )}
                        {document.status === 'published' && document.index_status === 'failed' && (
                            <Button variant="outline" onClick={() => { router.post(`${url}/reindex`, {}, { preserveScroll: true }); }}>
                                {copy.reindex}
                            </Button>
                        )}
                        <Button variant="text" onClick={() => { setDeleting(true); }}>
                            {copy.delete}
                        </Button>
                    </div>
                )}
            </div>

            {canEdit && (
                <ConfirmDialog
                open={deleting}
                title={copy.delete_title.replace(':title', document.title)}
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
