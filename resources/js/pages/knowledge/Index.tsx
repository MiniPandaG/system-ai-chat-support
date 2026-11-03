import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { IndexStatusBadge } from '../../components/IndexStatusBadge';
import type { IndexState } from '../../components/IndexStatusBadge';
import { KnowledgeSearch } from '../../components/KnowledgeSearch';
import type { KnowledgeSearchCopy } from '../../components/KnowledgeSearch';
import { Button } from '../../components/Button';
import { EmptyState } from '../../components/EmptyState';
import { KnowledgeTabs } from '../../components/KnowledgeTabs';
import { BookIcon } from '../../components/icons';
import { SelectField } from '../../components/SelectField';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import { useKnowledgeRefresh } from '../../realtime/useKnowledgeRefresh';
import type { SharedProps } from '../../types';

interface ArticleRow {
    id: number;
    title: string;
    category: string | null;
    status: 'draft' | 'published';
    index_status: IndexState | null;
    updated_at: string;
    author: string | null;
}

interface IndexCopy extends KnowledgeSearchCopy {
    title: string;
    detail: string;
    tabs_label: string;
    tab_articles: string;
    tab_documents: string;
    tab_context: string;
    new: string;
    list_label: string;
    search_label: string;
    search_submit: string;
    category_label: string;
    category_all: string;
    status_label: string;
    status_all: string;
    status_draft: string;
    status_published: string;
    clear_filters: string;
    empty_title: string;
    empty_detail: string;
    empty_cta: string;
    empty_readonly: string;
    empty_filtered_title: string;
    empty_filtered_detail: string;
    count: string;
    updated: string;
    by: string;
    no_category: string;
    previous: string;
    next: string;
    page: string;
    pagination_label: string;
    index_pending: string;
    index_indexed: string;
    index_failed: string;
}

interface IndexProps {
    copy: IndexCopy;
    articles: ArticleRow[];
    pagination: { current: number; last: number; total: number; previous: string | null; next: string | null };
    filters: { category: string | null; q: string | null; status: string | null };
    categories: string[];
    can_manage: boolean;
    total: number;
    max: number;
    tenant_id: number | null;
}

const dateFormat = new Intl.DateTimeFormat('es', { dateStyle: 'medium' });

/**
 * Lista de la base de conocimiento (design/pantallas/base-conocimiento.md): artículos con su categoría y su estado, filtro
 * por categoría y estado, búsqueda por título y paginación. Cada fila es un enlace al artículo.
 */
export default function Index({ copy, articles, pagination, filters, categories, can_manage: canManage, total, max, tenant_id: tenantId }: IndexProps) {
    const { props } = usePage<SharedProps>();
    useKnowledgeRefresh(tenantId, ['articles', 'pagination', 'total']);
    const [query, setQuery] = useState(filters.q ?? '');
    const filtered = filters.category !== null || filters.q !== null || filters.status !== null;

    const apply = (next: { category?: string | null; status?: string | null; q?: string | null }) => {
        const merged = { category: filters.category, status: filters.status, q: filters.q, ...next };
        const params: Record<string, string> = {};

        if (merged.category !== null && merged.category !== '') params.category = merged.category;
        if (merged.status !== null && merged.status !== '') params.status = merged.status;
        if (merged.q !== null && merged.q.trim() !== '') params.q = merged.q.trim();

        router.get('/knowledge', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const clear = () => {
        setQuery('');
        router.get('/knowledge', {}, { preserveScroll: true, replace: true });
    };

    return (
        <PanelLayout title={copy.title} section="knowledge" wide>
            <div className="flex flex-col gap-3">
                <p className="text-text-secondary">{copy.detail}</p>
                <KnowledgeTabs current="articles" label={copy.tabs_label} articles={copy.tab_articles} documents={copy.tab_documents} context={copy.tab_context} />
                <div className="flex flex-wrap items-center gap-4">
                    {canManage && (
                        <Link
                            href="/knowledge/articles/create"
                            className="inline-flex h-10 items-center rounded-sm bg-primary px-4 text-base font-medium text-primary-foreground transition duration-fast ease-standard hover:bg-primary-hover"
                        >
                            {copy.new}
                        </Link>
                    )}
                    <span className="text-sm text-text-secondary">{copy.count.replace(':total', String(total)).replace(':max', String(max))}</span>
                </div>
            </div>

            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}

            <form
                role="search"
                className="flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    apply({ q: query });
                }}
            >
                <div className="min-w-[14rem] flex-1">
                    <TextField label={copy.search_label} type="search" autoComplete="off" value={query} onChange={(event) => { setQuery(event.target.value); }} />
                </div>
                <Button type="submit" variant="outline">
                    {copy.search_submit}
                </Button>
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.category_label}
                        visibleLabel
                        options={[{ value: '', label: copy.category_all }, ...categories.map((category) => ({ value: category, label: category }))]}
                        value={filters.category ?? ''}
                        onChange={(event) => {
                            apply({ category: event.target.value });
                        }}
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.status_label}
                        visibleLabel
                        options={[
                            { value: '', label: copy.status_all },
                            { value: 'published', label: copy.status_published },
                            { value: 'draft', label: copy.status_draft },
                        ]}
                        value={filters.status ?? ''}
                        onChange={(event) => {
                            apply({ status: event.target.value });
                        }}
                    />
                </div>
                {filtered && (
                    <Button variant="text" onClick={clear}>
                        {copy.clear_filters}
                    </Button>
                )}
            </form>

            <KnowledgeSearch copy={copy} />

            {articles.length === 0 ? (
                filtered ? (
                    <EmptyState icon={<BookIcon />} title={copy.empty_filtered_title} detail={copy.empty_filtered_detail}>
                        <Button variant="outline" onClick={clear}>
                            {copy.clear_filters}
                        </Button>
                    </EmptyState>
                ) : (
                    <EmptyState icon={<BookIcon />} title={copy.empty_title} detail={canManage ? copy.empty_detail : `${copy.empty_detail} ${copy.empty_readonly}`}>
                        {canManage && (
                            <Link href="/knowledge/articles/create" className="inline-flex h-10 items-center rounded-sm bg-primary px-4 text-base font-medium text-primary-foreground hover:bg-primary-hover">
                                {copy.empty_cta}
                            </Link>
                        )}
                    </EmptyState>
                )
            ) : (
                <ul aria-label={copy.list_label} className="flex flex-col divide-y divide-border overflow-hidden rounded-md border border-border bg-surface">
                    {articles.map((article) => (
                        <li key={article.id}>
                            <Link href={`/knowledge/articles/${String(article.id)}`} className="flex min-h-14 flex-col gap-1 px-4 py-3 transition duration-fast ease-standard hover:bg-surface-hover">
                                <span className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium text-text">{article.title}</span>
                                    <Badge variant={article.status === 'published' ? 'success' : 'neutral'}>{article.status === 'published' ? copy.status_published : copy.status_draft}</Badge>
                                    <IndexStatusBadge status={article.index_status} pending={copy.index_pending} indexed={copy.index_indexed} failed={copy.index_failed} />
                                </span>
                                <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-text-secondary">
                                    <Badge variant="neutral" shape="tag">
                                        {article.category ?? copy.no_category}
                                    </Badge>
                                    <span>{copy.updated.replace(':date', dateFormat.format(new Date(article.updated_at)))}</span>
                                    {article.author !== null && <span>{copy.by.replace(':name', article.author)}</span>}
                                </span>
                            </Link>
                        </li>
                    ))}
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
