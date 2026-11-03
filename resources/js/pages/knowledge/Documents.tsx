import { Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { IndexStatusBadge } from '../../components/IndexStatusBadge';
import type { IndexState } from '../../components/IndexStatusBadge';
import { Button } from '../../components/Button';
import { EmptyState } from '../../components/EmptyState';
import { FileUpload } from '../../components/FileUpload';
import type { FileUploadCopy } from '../../components/FileUpload';
import { BookIcon } from '../../components/icons';
import { KnowledgeTabs } from '../../components/KnowledgeTabs';
import { PanelLayout } from '../../layouts/PanelLayout';
import { formatBytes } from '../../lib/attachments';
import { useKnowledgeRefresh } from '../../realtime/useKnowledgeRefresh';
import type { SharedProps } from '../../types';

export type DocumentStatus = 'scanning' | 'extracting' | 'review' | 'published' | 'failed' | 'blocked';

export interface DocumentRow {
    id: number;
    title: string;
    original_name: string;
    type: string;
    size_bytes: number;
    pages: number | null;
    category: string | null;
    status: DocumentStatus;
    index_status: IndexState | null;
    failure: string | null;
    updated_at: string;
    author: string | null;
}

interface DocumentsCopy {
    title: string;
    detail: string;
    tabs_label: string;
    tab_articles: string;
    tab_documents: string;
    tab_context: string;
    list_label: string;
    count: string;
    upload_title: string;
    upload: FileUploadCopy;
    readonly_upload: string;
    empty_title: string;
    empty_detail: string;
    updated: string;
    by: string;
    pages: string;
    page: string;
    previous: string;
    next: string;
    pagination_label: string;
    live_off: string;
    refresh: string;
    status_scanning: string;
    status_extracting: string;
    status_review: string;
    status_published: string;
    status_failed: string;
    status_blocked: string;
    index_pending: string;
    index_indexed: string;
    index_failed: string;
}

interface DocumentsProps {
    copy: DocumentsCopy;
    documents: DocumentRow[];
    pagination: { current: number; last: number; total: number; previous: string | null; next: string | null };
    can_manage: boolean;
    total: number;
    max: number;
    max_bytes: number;
    tenant_id: number | null;
}

/** Tipos que el navegador declara para un PDF, un texto y un Markdown (en Windows un .md llega a veces sin tipo). */
const ACCEPTED = ['application/pdf', 'text/plain', 'text/markdown', 'text/x-markdown', ''];

const dateFormat = new Intl.DateTimeFormat('es', { dateStyle: 'medium' });

type Variant = 'success' | 'neutral' | 'primary' | 'warning' | 'danger' | 'info';

/** La insignia del estado de un documento: siempre con texto, nunca solo color. */
export function statusBadge(status: DocumentStatus, copy: Pick<DocumentsCopy, 'status_scanning' | 'status_extracting' | 'status_review' | 'status_published' | 'status_failed' | 'status_blocked'>): { variant: Variant; label: string } {
    const map: Record<DocumentStatus, { variant: Variant; label: string }> = {
        scanning: { variant: 'info', label: copy.status_scanning },
        extracting: { variant: 'info', label: copy.status_extracting },
        review: { variant: 'warning', label: copy.status_review },
        published: { variant: 'success', label: copy.status_published },
        failed: { variant: 'danger', label: copy.status_failed },
        blocked: { variant: 'danger', label: copy.status_blocked },
    };

    return map[status];
}

/**
 * Documentos de la base de conocimiento: subir un PDF, un TXT o un Markdown y ver en qué paso va. El estado se actualiza
 * solo por tiempo real; sin conexión en vivo se avisa y hay un botón «Actualizar».
 */
export default function Documents({ copy, documents, pagination, can_manage: canManage, total, max, max_bytes: maxBytes, tenant_id: tenantId }: DocumentsProps) {
    const { props } = usePage<SharedProps>();
    const [uploading, setUploading] = useState<{ name: string; percent: number } | null>(null);
    const cancelRef = useRef<(() => void) | null>(null);
    const connection = useKnowledgeRefresh(tenantId, ['documents', 'total', 'pagination']);
    const processing = documents.some((document) => document.status === 'scanning' || document.status === 'extracting');

    const upload = (file: File) => {
        setUploading({ name: file.name, percent: 0 });
        router.post(
            '/knowledge/documents',
            { file },
            {
                forceFormData: true,
                preserveScroll: true,
                onCancelToken: (token) => {
                    cancelRef.current = () => {
                        token.cancel();
                    };
                },
                onProgress: (progress) => {
                    if (progress?.percentage !== undefined) setUploading({ name: file.name, percent: Math.round(progress.percentage) });
                },
                onFinish: () => {
                    cancelRef.current = null;
                    setUploading(null);
                },
            },
        );
    };

    return (
        <PanelLayout title={copy.title} section="knowledge" wide>
            <div className="flex flex-col gap-3">
                <p className="text-text-secondary">{copy.detail}</p>
                <KnowledgeTabs current="documents" label={copy.tabs_label} articles={copy.tab_articles} documents={copy.tab_documents} context={copy.tab_context} />
                <span className="text-sm text-text-secondary">{copy.count.replace(':total', String(total)).replace(':max', String(max))}</span>
            </div>

            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}

            {processing && connection !== 'connected' && (
                <Alert variant="info">
                    <span className="flex flex-wrap items-center gap-3">
                        {copy.live_off}
                        <Button
                            variant="outline"
                            onClick={() => {
                                router.reload({ only: ['documents', 'total', 'pagination'] });
                            }}
                        >
                            {copy.refresh}
                        </Button>
                    </span>
                </Alert>
            )}

            {canManage ? (
                <section aria-labelledby="documents-upload" className="flex flex-col gap-3">
                    <h2 id="documents-upload" className="font-display text-lg font-semibold">
                        {copy.upload_title}
                    </h2>
                    <FileUpload
                        copy={{ ...copy.upload, help: copy.upload.help.replace(':max', String(Math.round(maxBytes / 1048576))) }}
                        accept={ACCEPTED}
                        maxBytes={maxBytes}
                        uploading={uploading}
                        error={props.errors.file}
                        onSelect={upload}
                        onCancel={() => {
                            cancelRef.current?.();
                        }}
                    />
                </section>
            ) : (
                <Alert variant="info">{copy.readonly_upload}</Alert>
            )}

            {documents.length === 0 ? (
                <EmptyState icon={<BookIcon />} title={copy.empty_title} detail={copy.empty_detail} />
            ) : (
                <ul aria-label={copy.list_label} className="flex flex-col divide-y divide-border overflow-hidden rounded-md border border-border bg-surface">
                    {documents.map((document) => {
                        const badge = statusBadge(document.status, copy);

                        return (
                            <li key={document.id}>
                                <Link href={`/knowledge/documents/${String(document.id)}`} className="flex min-h-14 flex-col gap-1 px-4 py-3 transition duration-fast ease-standard hover:bg-surface-hover">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium text-text">{document.title}</span>
                                        <Badge variant={badge.variant}>{badge.label}</Badge>
                                        <IndexStatusBadge status={document.index_status} pending={copy.index_pending} indexed={copy.index_indexed} failed={copy.index_failed} />
                                    </span>
                                    {document.failure !== null && <span className="text-sm text-danger">{document.failure}</span>}
                                    <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-text-secondary">
                                        <span className="break-all font-mono">{document.original_name}</span>
                                        <span>{formatBytes(document.size_bytes)}</span>
                                        {document.pages !== null && <span>{copy.pages.replace(':count', String(document.pages))}</span>}
                                        <span>{copy.updated.replace(':date', dateFormat.format(new Date(document.updated_at)))}</span>
                                        {document.author !== null && <span>{copy.by.replace(':name', document.author)}</span>}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}

            {pagination.last > 1 && (
                <nav aria-label={copy.pagination_label} className="flex items-center justify-between gap-3">
                    {pagination.previous === null ? (
                        <span className="text-sm text-text-disabled">{copy.previous}</span>
                    ) : (
                        <Link href={pagination.previous} className="text-base text-text underline underline-offset-2 hover:text-primary">
                            {copy.previous}
                        </Link>
                    )}
                    <span className="text-sm text-text-secondary">{copy.page.replace(':current', String(pagination.current)).replace(':last', String(pagination.last))}</span>
                    {pagination.next === null ? (
                        <span className="text-sm text-text-disabled">{copy.next}</span>
                    ) : (
                        <Link href={pagination.next} className="text-base text-text underline underline-offset-2 hover:text-primary">
                            {copy.next}
                        </Link>
                    )}
                </nav>
            )}
        </PanelLayout>
    );
}
