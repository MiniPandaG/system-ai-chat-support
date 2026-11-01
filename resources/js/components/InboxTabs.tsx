import { Link } from '@inertiajs/react';
import { inboxQuery } from '../lib/inbox';
import type { InboxCopy, InboxCounts, InboxFilters, InboxScope, SavedView } from '../ticketTypes';

interface InboxTabsProps {
    copy: InboxCopy;
    filters: InboxFilters;
    counts: InboxCounts;
    views: SavedView[];
    /** Ruta sobre la que se navega (la bandeja o el ticket abierto), para no cerrar el ticket al cambiar de vista. */
    basePath: string;
}

const scopes: InboxScope[] = ['all', 'mine', 'unassigned'];

function tabClasses(active: boolean): string {
    return `inline-flex min-h-11 shrink-0 items-center gap-2 whitespace-nowrap border-b-2 px-3 text-base transition duration-fast ease-standard ${
        active ? 'border-primary font-semibold text-text' : 'border-transparent font-medium text-text-secondary hover:text-text'
    }`;
}

function Count({ value, active }: { value: number; active: boolean }) {
    return <span className={`text-xs ${active ? 'text-primary' : 'text-text-tertiary'}`}>{value.toLocaleString('es')}</span>;
}

/**
 * Filtros rápidos y vistas guardadas con su contador. Son enlaces: cambiar de vista cambia la dirección, así que se
 * puede volver atrás y compartir el enlace. La vista activa lleva aria-current.
 */
export function InboxTabs({ copy, filters, counts, views, basePath }: InboxTabsProps) {
    const savedId = filters.saved ?? null;
    const scope = filters.scope ?? 'all';

    return (
        <nav aria-label={copy.tabs_label} className="border-b border-border">
            <ul className="flex overflow-x-auto px-2">
                {scopes.map((key) => {
                    const active = savedId === null && scope === key;

                    return (
                        <li key={key}>
                            <Link href={`${basePath}${inboxQuery(key === 'all' ? {} : { scope: key })}`} aria-current={active ? 'page' : undefined} className={tabClasses(active)}>
                                {copy.scope[key]}
                                <Count value={counts[key]} active={active} />
                            </Link>
                        </li>
                    );
                })}
                {views.map((view) => {
                    const active = savedId === view.id;

                    return (
                        <li key={`view-${String(view.id)}`}>
                            <Link href={`${basePath}${inboxQuery({ saved: view.id })}`} aria-current={active ? 'page' : undefined} title={view.shared ? copy.shared : undefined} className={tabClasses(active)}>
                                {view.name}
                                {view.shared && <span className="sr-only"> ({copy.shared})</span>}
                                <Count value={view.count} active={active} />
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
