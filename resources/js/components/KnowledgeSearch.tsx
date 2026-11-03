import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SyntheticEvent } from 'react';
import { Alert } from './Alert';
import { Badge } from './Badge';
import { Button } from './Button';
import { TextField } from './TextField';

export interface KnowledgeSearchCopy {
    try_title: string;
    try_detail: string;
    try_label: string;
    try_submit: string;
    try_searching: string;
    try_none: string;
    try_results: string;
    try_score: string;
    try_scale_note: string;
    try_unavailable: string;
    try_article: string;
    try_document: string;
}

interface SearchResult {
    text: string;
    score: number;
    kind: 'article' | 'document';
    title: string;
    category: string | null;
    url: string;
}

type Outcome = { state: 'idle' } | { state: 'loading' } | { state: 'done'; results: SearchResult[] } | { state: 'error'; message: string };

const isResult = (value: unknown): value is SearchResult =>
    typeof value === 'object' && value !== null && 'text' in value && 'title' in value && 'url' in value && 'score' in value && 'kind' in value;

/**
 * «Probar la búsqueda»: lo que encontraría la IA con una pregunta, solo de lo publicado de la empresa. Pregunta al servidor
 * con la sesión del panel; nada de lo que vuelve se interpreta como HTML (todo se muestra como texto).
 */
export function KnowledgeSearch({ copy }: { copy: KnowledgeSearchCopy }) {
    const [query, setQuery] = useState('');
    const [outcome, setOutcome] = useState<Outcome>({ state: 'idle' });
    const controllerRef = useRef<AbortController | null>(null);

    useEffect(
        () => () => {
            controllerRef.current?.abort();
        },
        [],
    );

    const submit = (event: SyntheticEvent) => {
        event.preventDefault();

        if (query.trim() === '') return;

        controllerRef.current?.abort();
        const controller = new AbortController();
        controllerRef.current = controller;
        setOutcome({ state: 'loading' });

        fetch(`/knowledge/search?q=${encodeURIComponent(query.trim())}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal })
            .then(async (response) => {
                const body: unknown = await response.json().catch(() => null);

                if (response.ok && typeof body === 'object' && body !== null && 'results' in body && Array.isArray(body.results)) {
                    setOutcome({ state: 'done', results: body.results.filter(isResult) });

                    return;
                }

                // Un 422 trae un mensaje específico; cualquier otro fallo se dice con el mensaje fijo de la pantalla.
                const message = response.status === 422 && typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string' ? body.message : copy.try_unavailable;
                setOutcome({ state: 'error', message });
            })
            .catch((error: unknown) => {
                if (error instanceof DOMException && error.name === 'AbortError') return;

                setOutcome({ state: 'error', message: copy.try_unavailable });
            });
    };

    return (
        <section aria-labelledby="knowledge-try" className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4">
            <div className="flex flex-col gap-1">
                <h2 id="knowledge-try" className="font-display text-lg font-semibold">
                    {copy.try_title}
                </h2>
                <p className="text-sm text-text-secondary">{copy.try_detail}</p>
            </div>

            <form noValidate className="flex flex-wrap items-end gap-3" onSubmit={submit}>
                <div className="min-w-[14rem] flex-1">
                    <TextField label={copy.try_label} autoComplete="off" value={query} onChange={(event) => { setQuery(event.target.value); }} />
                </div>
                <Button type="submit" variant="outline" loading={outcome.state === 'loading'} loadingLabel={copy.try_searching} disabled={query.trim() === ''}>
                    {copy.try_submit}
                </Button>
            </form>

            {outcome.state === 'error' && <Alert variant="warning">{outcome.message}</Alert>}

            {outcome.state === 'done' &&
                (outcome.results.length === 0 ? (
                    <p role="status" className="text-text-secondary">
                        {copy.try_none}
                    </p>
                ) : (
                    <>
                    <ul aria-label={copy.try_results} className="flex flex-col divide-y divide-border rounded-md border border-border">
                        {outcome.results.map((result, index) => (
                            <li key={`${result.url}-${String(index)}`} className="flex flex-col gap-2 px-4 py-3">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Link href={result.url} className="font-medium text-text underline underline-offset-2 hover:text-primary">
                                        {result.title}
                                    </Link>
                                    <Badge variant="neutral">{result.kind === 'article' ? copy.try_article : copy.try_document}</Badge>
                                    {result.category !== null && (
                                        <Badge variant="neutral" shape="tag">
                                            {result.category}
                                        </Badge>
                                    )}
                                    <span className="text-sm text-text-secondary">{copy.try_score.replace(':score', result.score.toFixed(2).replace('.', ','))}</span>
                                </div>
                                <p className="whitespace-pre-wrap break-words text-base text-text-secondary">{result.text}</p>
                            </li>
                        ))}
                    </ul>
                    <p className="text-sm text-text-tertiary">{copy.try_scale_note}</p>
                    </>
                ))}
        </section>
    );
}
