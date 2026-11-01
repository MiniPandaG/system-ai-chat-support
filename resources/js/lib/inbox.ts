import type { InboxFilters } from '../ticketTypes';

/** Los parámetros de la dirección para unos filtros; solo lo que tiene valor, igual que el servidor los normaliza. */
export function inboxParams(filters: InboxFilters): Record<string, string | string[]> {
    const params: Record<string, string | string[]> = {};

    // Con una vista guardada, sus filtros los pone el servidor: solo se envían la vista, la búsqueda y el orden.
    if (filters.saved != null) {
        params.saved = String(filters.saved);
        if (filters.q !== undefined && filters.q !== '') params.q = filters.q;
        if (filters.sort !== undefined && filters.sort !== 'recent') params.sort = filters.sort;

        return params;
    }
    if (filters.scope !== undefined && filters.scope !== 'all') params.scope = filters.scope;
    if (filters.status !== undefined && filters.status.length > 0) params.status = filters.status;
    if (filters.priority !== undefined && filters.priority.length > 0) params.priority = filters.priority;
    if (filters.agent !== undefined) params.agent = String(filters.agent);
    if (filters.tag !== undefined) params.tag = String(filters.tag);
    if (filters.from !== undefined) params.from = filters.from;
    if (filters.to !== undefined) params.to = filters.to;
    if (filters.q !== undefined && filters.q !== '') params.q = filters.q;
    if (filters.sort !== undefined && filters.sort !== 'recent') params.sort = filters.sort;

    return params;
}

/** La cadena de consulta (con «?» al principio, o vacía) para enlaces y para pedir más tramos. */
export function inboxQuery(filters: InboxFilters): string {
    const search = new URLSearchParams();

    for (const [name, value] of Object.entries(inboxParams(filters))) {
        for (const item of Array.isArray(value) ? value : [value]) search.append(Array.isArray(value) ? `${name}[]` : name, item);
    }

    const text = search.toString();

    return text === '' ? '' : `?${text}`;
}

/** Cuántos filtros avanzados hay activos (el orden y la búsqueda no cuentan: tienen su propio campo). */
export function activeFilterCount(filters: InboxFilters): number {
    return (
        (filters.scope !== undefined && filters.scope !== 'all' ? 1 : 0) +
        (filters.status?.length ?? 0) +
        (filters.priority?.length ?? 0) +
        (filters.agent === undefined ? 0 : 1) +
        (filters.tag === undefined ? 0 : 1) +
        (filters.from === undefined ? 0 : 1) +
        (filters.to === undefined ? 0 : 1)
    );
}

/** Hay algo que limita la lista, incluida la búsqueda. */
export function isFiltered(filters: InboxFilters): boolean {
    return activeFilterCount(filters) > 0 || (filters.q !== undefined && filters.q !== '');
}

/** Espacios repetidos y de los bordes fuera, como hace el servidor antes de buscar. */
export function normalizeSearch(text: string): string {
    return text.replace(/\s+/g, ' ').trim();
}
