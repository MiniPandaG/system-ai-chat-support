import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { conversation, customerMessage, WIDGET_KEY, widgetConfig } from '../test-fixtures/widget';
import { tokenKey } from './useChat';
import { Widget } from './Widget';

vi.mock('../realtime/hooks', () => ({ useConnectionState: () => 'connected', useChannel: vi.fn() }));

const TOKEN = '1.' + 'a'.repeat(43);

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

const ended = conversation([customerMessage()], { ticket: { status: 'resolved', ended: true }, survey: { pending: true } });

/** Un servidor de mentira: la conversación y la ruta de la encuesta, con las llamadas registradas. */
function server(options: { conversation?: unknown; survey?: () => Response } = {}) {
    const calls: { url: string; init: RequestInit }[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, init: RequestInit) => {
            calls.push({ url, init });

            if (url === '/widget/api/conversation') return Promise.resolve(json(options.conversation ?? ended));
            if (url === '/widget/api/survey') return Promise.resolve(options.survey?.() ?? json({ answered: true }));

            return Promise.reject(new TypeError('Failed to fetch'));
        }),
    );

    return calls;
}

async function openWithConversation(user: ReturnType<typeof userEvent.setup>) {
    window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
    render(<Widget config={widgetConfig} />);
    await user.click(screen.getByRole('button', { name: /Abrir el chat de soporte/ }));
}

describe('Widget: encuesta de satisfacción', () => {
    beforeEach(() => {
        window.localStorage.clear();
        Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('cuando la conversación terminó y hay una encuesta por contestar, la ofrece con cinco calificaciones y un comentario opcional', async () => {
        server();
        const user = userEvent.setup();
        await openWithConversation(user);

        await screen.findByText('¿Cómo te fue con la atención?');
        expect(screen.getAllByRole('radio')).toHaveLength(5);
        expect(screen.getByRole('radio', { name: '1 · Muy mala' })).toBeTruthy();
        expect(screen.getByRole('radio', { name: '5 · Excelente' })).toBeTruthy();
        expect(screen.getByLabelText('Comentario (opcional)')).toBeTruthy();
    });

    it('no se ofrece mientras la conversación sigue abierta ni cuando no hay encuesta pendiente', async () => {
        server({ conversation: conversation([customerMessage()], { survey: { pending: true } }) });
        const user = userEvent.setup();
        await openWithConversation(user);
        await screen.findByRole('log');
        expect(screen.queryByText('¿Cómo te fue con la atención?')).toBeNull();
    });

    it('sin calificación no se envía y dice por qué', async () => {
        const calls = server();
        const user = userEvent.setup();
        await openWithConversation(user);
        await screen.findByText('¿Cómo te fue con la atención?');

        await user.click(screen.getByRole('button', { name: 'Enviar opinión' }));

        expect(screen.getByRole('alert').textContent).toBe('Elige una calificación del 1 al 5.');
        expect(calls.some((call) => call.url === '/widget/api/survey')).toBe(false);
    });

    it('envía la calificación y el comentario con el token de la sesión, agradece y se va', async () => {
        const calls = server();
        const user = userEvent.setup();
        await openWithConversation(user);
        await screen.findByText('¿Cómo te fue con la atención?');

        await user.click(screen.getByRole('radio', { name: '4 · Buena' }));
        await user.type(screen.getByLabelText('Comentario (opcional)'), '  Me ayudaron rápido  ');
        await user.click(screen.getByRole('button', { name: 'Enviar opinión' }));

        await screen.findByText('Gracias por tu opinión.');
        const call = calls.find((item) => item.url === '/widget/api/survey');
        expect(call?.init.method).toBe('POST');
        expect((call?.init.headers as Record<string, string>).Authorization).toBe(`Bearer ${TOKEN}`);
        expect(JSON.parse(call?.init.body as string)).toEqual({ score: 4, comment: 'Me ayudaron rápido' });
        expect(screen.queryByText('¿Cómo te fue con la atención?')).toBeNull();
    });

    it('si el servidor la rechaza, el mensaje queda en la tarjeta y se puede reintentar', async () => {
        server({ survey: () => json({ message: 'Elige una calificación del 1 al 5.', errors: { score: ['Elige una calificación del 1 al 5.'] } }, 422) });
        const user = userEvent.setup();
        await openWithConversation(user);
        await screen.findByText('¿Cómo te fue con la atención?');

        await user.click(screen.getByRole('radio', { name: '2 · Mala' }));
        await user.click(screen.getByRole('button', { name: 'Enviar opinión' }));

        await waitFor(() => {
            expect(screen.getByRole('alert').textContent).toContain('Elige una calificación del 1 al 5.');
        });
        expect(screen.getByRole('button', { name: 'Enviar opinión' })).toBeTruthy();
    });

    it('«Ahora no» la quita sin enviar nada', async () => {
        const calls = server();
        const user = userEvent.setup();
        await openWithConversation(user);
        await screen.findByText('¿Cómo te fue con la atención?');

        await user.click(screen.getByRole('button', { name: 'Omitir encuesta' }));

        expect(screen.queryByText('¿Cómo te fue con la atención?')).toBeNull();
        expect(calls.some((call) => call.url === '/widget/api/survey')).toBe(false);
    });
});
