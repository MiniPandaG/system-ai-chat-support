import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { activeFilterCount, normalizeSearch } from '../lib/inbox';
import type { Assignee, InboxCounts, InboxFilters, InboxSort, SavedView, TagData, TicketsCopy } from '../ticketTypes';
import { Badge } from './Badge';
import { Button } from './Button';
import { ConfirmDialog } from './ConfirmDialog';
import { InboxFilterPanel } from './InboxFilterPanel';
import { InboxTabs } from './InboxTabs';
import { SelectField } from './SelectField';

interface InboxToolbarProps {
    copy: TicketsCopy;
    filters: InboxFilters;
    counts: InboxCounts;
    views: SavedView[];
    assignees: Assignee[];
    tags: TagData[];
    canSaveView: boolean;
    basePath: string;
    /** Pide la lista con otros filtros; la pantalla decide cómo navegar. */
    onVisit: (next: InboxFilters) => void;
}

const sorts: InboxSort[] = ['recent', 'oldest', 'priority'];
const SEARCH_DELAY_MS = 350;

/**
 * Cabecera de la bandeja (design/pantallas/bandeja-tickets.md): pestañas con contador, búsqueda, panel de filtros,
 * orden, los filtros activos como insignias que se quitan con una «x» y el número de tickets que muestra la lista.
 */
export function InboxToolbar({ copy, filters, counts, views, assignees, tags, canSaveView, basePath, onVisit }: InboxToolbarProps) {
    const text = copy.inbox;
    const panelId = useId();
    const searchId = useId();
    const hintId = useId();
    const toggleId = useId();
    const panelOpenedRef = useRef(false);
    const [open, setOpen] = useState(false);
    const [term, setTerm] = useState(filters.q ?? '');
    const [seenQuery, setSeenQuery] = useState(filters.q ?? '');
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const timerRef = useRef<number | undefined>(undefined);
    const savedView = views.find((view) => view.id === filters.saved) ?? null;
    const filterCount = activeFilterCount(filters);

    // Si el servidor devuelve otra búsqueda (por ejemplo al quitar los filtros), el campo la sigue; lo que la persona
    // escribe no se pisa mientras coincida, ignorando espacios repetidos.
    if ((filters.q ?? '') !== seenQuery) {
        setSeenQuery(filters.q ?? '');
        if (normalizeSearch(term) !== (filters.q ?? '')) setTerm(filters.q ?? '');
    }

    // Al abrir el panel el foco pasa a su primer control y al cerrarlo vuelve al botón «Filtros».
    useEffect(() => {
        if (open) {
            panelOpenedRef.current = true;
            document.getElementById(panelId)?.querySelector<HTMLElement>('input, select')?.focus();
        } else if (panelOpenedRef.current) {
            panelOpenedRef.current = false;
            document.getElementById(toggleId)?.focus();
        }
    }, [open, panelId, toggleId]);

    useEffect(
        () => () => {
            window.clearTimeout(timerRef.current);
        },
        [],
    );

    const search = (value: string) => {
        setTerm(value);
        window.clearTimeout(timerRef.current);
        timerRef.current = window.setTimeout(() => {
            const normalized = normalizeSearch(value);
            // Un texto de un solo carácter no busca nada, salvo una cifra, que es un número de ticket.
            if (normalized.length === 1 && !/^\d$/.test(normalized)) return;
            if (normalized === (filters.q ?? '')) return;
            onVisit({ ...filters, ...(normalized === '' ? { q: undefined } : { q: normalized }) });
        }, SEARCH_DELAY_MS);
    };

    // Ajustar un filtro deja la vista guardada: la lista pasa a ser una combinación nueva, con lo que ya tenía.
    const change = (changes: Partial<InboxFilters>) => {
        onVisit({ ...filters, ...changes, saved: null });
    };

    const clear = () => {
        window.clearTimeout(timerRef.current);
        setTerm('');
        onVisit({});
    };

    const chips: { key: string; label: string; remove: () => void }[] = [];
    if (filters.scope !== undefined && filters.scope !== 'all') chips.push({ key: 'scope', label: text.scope[filters.scope], remove: () => { change({ scope: undefined }); } });
    for (const status of filters.status ?? []) chips.push({ key: `status-${status}`, label: copy.status[status], remove: () => { change({ status: (filters.status ?? []).filter((item) => item !== status) }); } });
    for (const priority of filters.priority ?? []) chips.push({ key: `priority-${priority}`, label: copy.priority[priority], remove: () => { change({ priority: (filters.priority ?? []).filter((item) => item !== priority) }); } });
    if (filters.agent !== undefined) chips.push({ key: 'agent', label: assignees.find((person) => person.id === filters.agent)?.name ?? text.agent, remove: () => { change({ agent: undefined }); } });
    if (filters.tag !== undefined) chips.push({ key: 'tag', label: tags.find((tag) => tag.id === filters.tag)?.name ?? text.tag, remove: () => { change({ tag: undefined }); } });
    if (filters.from !== undefined) chips.push({ key: 'from', label: `${text.from}: ${filters.from}`, remove: () => { change({ from: undefined }); } });
    if (filters.to !== undefined) chips.push({ key: 'to', label: `${text.to}: ${filters.to}`, remove: () => { change({ to: undefined }); } });

    return (
        <div className="flex flex-col">
            <InboxTabs copy={text} filters={filters} counts={counts} views={views} basePath={basePath} />

            <div className="flex flex-col gap-2 border-b border-border px-4 py-3">
                <div className="flex flex-col gap-1">
                    <label htmlFor={searchId} className="sr-only">
                        {text.search_label}
                    </label>
                    <input
                        id={searchId}
                        type="search"
                        value={term}
                        placeholder={text.search_placeholder}
                        autoComplete="off"
                        maxLength={100}
                        aria-describedby={hintId}
                        onChange={(event) => {
                            search(event.target.value);
                        }}
                        className="h-10 w-full rounded-sm border border-border bg-surface-sunken px-3 font-body text-base text-text outline-none placeholder:text-text-tertiary hover:border-border-strong focus:border-2 focus:border-primary focus:bg-surface"
                    />
                    <span id={hintId} className="sr-only">
                        {text.search_hint}
                    </span>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button
                        id={toggleId}
                        variant="outline"
                        aria-expanded={open}
                        aria-controls={panelId}
                        onClick={() => {
                            setOpen((value) => !value);
                        }}
                    >
                        {filterCount > 0 ? text.filters_active.replace(':count', String(filterCount)) : text.filters}
                    </Button>
                    <div className="flex flex-col gap-1">
                        <SelectField
                            label={text.sort_label}
                            options={sorts.map((sort) => ({ value: sort, label: text.sort[sort] }))}
                            value={filters.sort ?? 'recent'}
                            onChange={(event) => {
                                onVisit({ ...filters, sort: event.target.value === 'recent' ? undefined : (event.target.value as InboxSort) });
                            }}
                        />
                    </div>
                </div>
            </div>

            {open && <InboxFilterPanel id={panelId} copy={copy} filters={filters} assignees={assignees} tags={tags} canSaveView={canSaveView} onChange={change} onClear={clear} />}

            {chips.length > 0 && (
                <ul aria-label={text.filters} className="flex flex-wrap gap-2 border-b border-border px-4 py-3">
                    {chips.map((chip) => (
                        <li key={chip.key}>
                            <Badge variant="primary" shape="tag" removeLabel={text.remove_filter.replace(':name', chip.label)} onRemove={chip.remove}>
                                {chip.label}
                            </Badge>
                        </li>
                    ))}
                </ul>
            )}

            {savedView?.deletable === true && (
                <div className="flex justify-end border-b border-border px-4 py-1">
                    <Button
                        variant="text"
                        onClick={() => {
                            setConfirmingDelete(true);
                        }}
                    >
                        {text.delete.button}
                    </Button>
                </div>
            )}

            {savedView !== null && (
                <ConfirmDialog
                    open={confirmingDelete}
                    title={text.delete.title.replace(':name', savedView.name)}
                    detail={text.delete.detail}
                    cancelLabel={text.delete.cancel}
                    confirmLabel={text.delete.confirm}
                    confirmingLabel={text.save.submitting}
                    onCancel={() => {
                        setConfirmingDelete(false);
                    }}
                    onConfirm={() => {
                        router.delete(`/tickets/views/${String(savedView.id)}`, {
                            onFinish: () => {
                                setConfirmingDelete(false);
                            },
                        });
                    }}
                />
            )}
        </div>
    );
}
