import { useEffect, useState } from 'react';
import type { NewTicketCopy } from '../ticketTypes';
import { Button } from './Button';
import { TextField } from './TextField';

export interface CustomerOption {
    id: number;
    name: string;
    email: string | null;
}

interface CustomerPickerProps {
    copy: NewTicketCopy;
    selected: CustomerOption | null;
    error?: string | undefined;
    onSelect: (customer: CustomerOption | null) => void;
}

const MIN_LENGTH = 2;

/**
 * Buscador de clientes existentes: escribe al menos dos letras y elige de una lista corta. Los resultados son botones
 * reales y un texto anuncia cuántos hay.
 */
export function CustomerPicker({ copy, selected, error, onSelect }: CustomerPickerProps) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<{ term: string; customers: CustomerOption[] } | null>(null);

    useEffect(() => {
        const query = term.trim();
        if (query.length < MIN_LENGTH) return;

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/customers/search?q=${encodeURIComponent(query)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? (response.json() as Promise<{ data: CustomerOption[] }>) : { data: [] }))
                .then((body) => {
                    setResults({ term: query, customers: body.data });
                })
                .catch(() => {
                    // Una búsqueda cancelada por seguir escribiendo no es un error; si falla de verdad, la lista queda como estaba.
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    if (selected !== null) {
        return (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-surface-sunken p-3">
                <p>
                    <span className="block text-xs text-text-tertiary">{copy.customer_selected}</span>
                    <span className="font-medium">{selected.name}</span>
                    {selected.email !== null && <span className="block text-sm text-text-secondary">{selected.email}</span>}
                </p>
                <Button
                    variant="outline"
                    onClick={() => {
                        onSelect(null);
                    }}
                >
                    {copy.customer_change}
                </Button>
            </div>
        );
    }

    const query = term.trim();
    // Los resultados solo valen para el término con el que se pidieron.
    const current = results !== null && results.term === query && query.length >= MIN_LENGTH ? results.customers : null;

    return (
        <div className="flex flex-col gap-3">
            <TextField
                label={copy.customer_search}
                help={copy.customer_search_hint}
                error={error}
                type="search"
                value={term}
                autoComplete="off"
                onChange={(event) => {
                    setTerm(event.target.value);
                }}
            />
            <div role="status" className="text-sm text-text-secondary">
                {current !== null && current.length === 1 && copy.customer_result_one}
                {current !== null && current.length > 1 && copy.customer_results.replace(':count', String(current.length))}
                {current !== null && current.length === 0 && copy.customer_none.replace(':term', query)}
            </div>
            {current !== null && current.length > 0 && (
                <ul className="flex flex-col overflow-hidden rounded-md border border-border">
                    {current.map((customer) => (
                        <li key={customer.id} className="border-b border-border last:border-b-0">
                            <button
                                type="button"
                                className="flex w-full flex-col items-start px-3 py-2 text-left hover:bg-surface-hover"
                                onClick={() => {
                                    onSelect(customer);
                                }}
                            >
                                <span className="font-medium">{customer.name}</span>
                                {customer.email !== null && <span className="text-sm text-text-secondary">{customer.email}</span>}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
