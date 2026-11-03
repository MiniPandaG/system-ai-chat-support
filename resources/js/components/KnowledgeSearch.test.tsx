import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { KnowledgeSearch } from './KnowledgeSearch';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

const copy = {
    try_title: 'Probar la búsqueda',
    try_detail: 'Escribe una pregunta de un cliente.',
    try_label: 'Pregunta de prueba',
    try_submit: 'Probar',
    try_searching: 'Buscando',
    try_none: 'No hay nada publicado que responda a esta pregunta.',
    try_results: 'Resultados de la búsqueda',
    try_score: 'Puntaje :score',
    try_scale_note: 'El puntaje sirve para ordenar, no para saber si responde.',
    try_unavailable: 'La búsqueda no está disponible ahora.',
    try_article: 'Artículo',
    try_document: 'Documento',
};

const result = { text: 'Los envíos tardan tres días.', score: 0.82, kind: 'article', title: 'Plazos de envío', category: 'Envíos', url: '/knowledge/articles/7' };

function mockFetch(response: Response | Promise<Response>) {
    const fetchMock = vi.fn().mockResolvedValue(response);
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

async function ask(question: string) {
    const user = userEvent.setup();
    render(<KnowledgeSearch copy={copy} />);
    await user.type(screen.getByLabelText('Pregunta de prueba'), question);
    await user.click(screen.getByRole('button', { name: 'Probar' }));
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('KnowledgeSearch', () => {
    it('no deja buscar sin pregunta', () => {
        render(<KnowledgeSearch copy={copy} />);

        expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Probar' }).disabled).toBe(true);
    });

    it('pregunta al servidor con la sesión del panel y la pregunta codificada, sin guardar nada', async () => {
        const fetchMock = mockFetch(new Response(JSON.stringify({ results: [] }), { status: 200 }));

        await ask('  ¿cuánto tarda & cuesta?  ');

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe(`/knowledge/search?q=${encodeURIComponent('¿cuánto tarda & cuesta?')}`);
        expect(init.credentials).toBe('same-origin');
        expect((init.headers as Record<string, string>).Accept).toBe('application/json');
    });

    it('muestra cada resultado con su fuente, su tipo, su categoría y su coincidencia', async () => {
        mockFetch(new Response(JSON.stringify({ results: [result, { ...result, kind: 'document', title: 'Manual', category: null, score: 0.4, url: '/knowledge/documents/3' }] }), { status: 200 }));

        await ask('envíos');

        const list = await screen.findByRole('list', { name: 'Resultados de la búsqueda' });
        expect(list.textContent).toContain('Plazos de envío');
        expect(list.textContent).toContain('Puntaje 0,82');
        expect(screen.getByText('El puntaje sirve para ordenar, no para saber si responde.')).toBeTruthy();
        expect(list.textContent).toContain('Los envíos tardan tres días.');
        expect(screen.getByRole('link', { name: 'Plazos de envío' }).getAttribute('href')).toBe('/knowledge/articles/7');
        expect(screen.getByRole('link', { name: 'Manual' }).getAttribute('href')).toBe('/knowledge/documents/3');
        expect(screen.getByText('Artículo')).toBeTruthy();
        expect(screen.getByText('Documento')).toBeTruthy();
    });

    it('lo que vuelve se muestra como texto, nunca como HTML', async () => {
        mockFetch(new Response(JSON.stringify({ results: [{ ...result, title: '<img src=x onerror=alert(1)>', text: '<script>alert(1)</script>' }] }), { status: 200 }));

        await ask('envíos');

        await screen.findByRole('list', { name: 'Resultados de la búsqueda' });
        expect(document.querySelector('img[src="x"]')).toBeNull();
        expect(document.querySelector('script')).toBeNull();
        expect(screen.getByText('<script>alert(1)</script>')).toBeTruthy();
    });

    it('sin resultados lo dice', async () => {
        mockFetch(new Response(JSON.stringify({ results: [] }), { status: 200 }));

        await ask('nada');

        expect(await screen.findByText('No hay nada publicado que responda a esta pregunta.')).toBeTruthy();
    });

    it('ignora los resultados que no tienen la forma esperada', async () => {
        mockFetch(new Response(JSON.stringify({ results: [result, { titulo: 'raro' }, null, 'texto'] }), { status: 200 }));

        await ask('envíos');

        const list = await screen.findByRole('list', { name: 'Resultados de la búsqueda' });
        expect(list.querySelectorAll('li')).toHaveLength(1);
    });

    it('un 422 muestra el mensaje específico del servidor', async () => {
        mockFetch(new Response(JSON.stringify({ message: 'La pregunta es demasiado larga. El máximo es de 300 caracteres.' }), { status: 422 }));

        await ask('x');

        expect(await screen.findByText('La pregunta es demasiado larga. El máximo es de 300 caracteres.')).toBeTruthy();
    });

    it('un 503, una respuesta rara o un fallo de red se dicen con el mensaje fijo, sin detalles', async () => {
        for (const response of [new Response('Traza interna', { status: 503 }), new Response('no es json', { status: 200 }), new Response(JSON.stringify({ message: 'detalle interno' }), { status: 500 })]) {
            mockFetch(response);
            const { unmount } = render(<KnowledgeSearch copy={copy} />);
            const user = userEvent.setup();
            await user.type(screen.getByLabelText('Pregunta de prueba'), 'envíos');
            await user.click(screen.getByRole('button', { name: 'Probar' }));

            expect(await screen.findByText('La búsqueda no está disponible ahora.')).toBeTruthy();
            expect(screen.queryByText('detalle interno')).toBeNull();
            unmount();
        }

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch http://interno')));
        await ask('envíos');
        expect(await screen.findByText('La búsqueda no está disponible ahora.')).toBeTruthy();
        expect(screen.queryByText(/interno/)).toBeNull();
    });

    it('al desmontar cancela la búsqueda en curso', async () => {
        let signal: AbortSignal | undefined;
        vi.stubGlobal(
            'fetch',
            vi.fn((_url: string, init: RequestInit) => {
                signal = init.signal ?? undefined;

                return new Promise<Response>(() => undefined);
            }),
        );
        const user = userEvent.setup();
        const { unmount } = render(<KnowledgeSearch copy={copy} />);
        await user.type(screen.getByLabelText('Pregunta de prueba'), 'envíos');
        await user.click(screen.getByRole('button', { name: 'Probar' }));

        unmount();

        expect(signal?.aborted).toBe(true);
    });
});
