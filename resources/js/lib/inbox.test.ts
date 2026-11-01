import { describe, expect, it } from 'vitest';
import { activeFilterCount, inboxParams, inboxQuery, isFiltered, normalizeSearch } from './inbox';

describe('inboxQuery', () => {
    it('sin filtros no deja nada en la dirección', () => {
        expect(inboxQuery({})).toBe('');
        expect(inboxQuery({ scope: 'all', sort: 'recent', q: '' })).toBe('');
    });

    it('escribe solo lo que no es el valor por defecto', () => {
        expect(inboxQuery({ scope: 'mine', sort: 'priority' })).toBe('?scope=mine&sort=priority');
    });

    it('las listas viajan como parámetros de arreglo', () => {
        expect(decodeURIComponent(inboxQuery({ status: ['open', 'closed'], priority: ['urgent'] }))).toBe('?status[]=open&status[]=closed&priority[]=urgent');
    });

    it('la vista guardada va primero y el texto se codifica', () => {
        expect(inboxQuery({ saved: 3, q: 'factura & más' })).toBe('?saved=3&q=factura+%26+m%C3%A1s');
    });

    it('incluye agente, etiqueta y fechas', () => {
        expect(inboxQuery({ agent: 5, tag: 7, from: '2026-10-01', to: '2026-10-31' })).toBe('?agent=5&tag=7&from=2026-10-01&to=2026-10-31');
    });

    it('una vista guardada nula no escribe nada', () => {
        expect(inboxQuery({ saved: null, scope: 'mine' })).toBe('?scope=mine');
    });
});

describe('inboxParams', () => {
    it('devuelve las listas como arreglos reales para que el router las serialice', () => {
        expect(inboxParams({ status: ['open'], q: 'hola' })).toEqual({ status: ['open'], q: 'hola' });
    });

    it('con una vista guardada solo viajan la vista, la búsqueda y el orden', () => {
        expect(inboxParams({ saved: 4, status: ['open'], scope: 'mine', q: 'hola', sort: 'oldest' })).toEqual({ saved: '4', q: 'hola', sort: 'oldest' });
    });

    it('ignora las listas vacías', () => {
        expect(inboxParams({ status: [], priority: [] })).toEqual({});
    });
});

describe('activeFilterCount e isFiltered', () => {
    it('cuenta cada filtro activo y no la búsqueda ni el orden', () => {
        expect(activeFilterCount({ scope: 'mine', status: ['open', 'closed'], agent: 2, q: 'algo', sort: 'oldest' })).toBe(4);
    });

    it('una búsqueda sola también limita la lista', () => {
        expect(isFiltered({ q: 'algo' })).toBe(true);
        expect(isFiltered({ sort: 'oldest' })).toBe(false);
        expect(isFiltered({})).toBe(false);
    });
});

describe('normalizeSearch', () => {
    it('quita espacios de los bordes y repetidos', () => {
        expect(normalizeSearch('  factura   duplicada ')).toBe('factura duplicada');
    });
});
