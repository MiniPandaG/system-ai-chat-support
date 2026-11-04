import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { agentMessage, conversation, customerMessage, PARENT_ORIGIN, WIDGET_KEY, widgetConfig } from '../test-fixtures/widget';
import { tokenKey } from './useChat';
import { Widget } from './Widget';

vi.mock('../realtime/hooks', () => ({ useConnectionState: () => 'connected', useChannel: vi.fn() }));

const TOKEN = '1.' + 'a'.repeat(43);

type Handler = (url: string, init: RequestInit) => Response | Promise<Response>;

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/** Un servidor de mentira: cada ruta responde lo que la prueba diga y todas las llamadas quedan registradas. */
function server(routes: Record<string, Handler>) {
    const calls: { url: string; init: RequestInit }[] = [];
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, init: RequestInit) => {
            calls.push({ url, init });
            const route = routes[`${init.method ?? 'GET'} ${url}`];

            return route === undefined ? Promise.reject(new TypeError('Failed to fetch')) : Promise.resolve(route(url, init));
        }),
    );

    return calls;
}

function bodyOf(call: { init: RequestInit }): Record<string, unknown> {
    return JSON.parse(call.init.body as string) as Record<string, unknown>;
}

function setOnline(value: boolean): void {
    Object.defineProperty(navigator, 'onLine', { value, configurable: true });
    window.dispatchEvent(new Event(value ? 'online' : 'offline'));
}

async function openWindow(user: ReturnType<typeof userEvent.setup>) {
    await user.click(screen.getByRole('button', { name: /Abrir el chat de soporte/ }));
}

describe('Widget', () => {
    beforeEach(() => {
        window.localStorage.clear();
        Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    describe('abrir y cerrar', () => {
        it('empieza como un botón flotante con nombre accesible y sin ventana', () => {
            server({});
            render(<Widget config={widgetConfig} />);

            expect(screen.getByRole('button', { name: 'Abrir el chat de soporte' })).toBeTruthy();
            expect(screen.queryByRole('dialog')).toBeNull();
        });

        it('al abrirse muestra la bienvenida con el nombre de la empresa y el foco en «Iniciar conversación»', async () => {
            server({});
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);

            await openWindow(user);

            expect(screen.getByRole('dialog', { name: 'Chat de soporte de Tienda Ejemplo' })).toBeTruthy();
            expect(screen.getByRole('heading', { name: 'Tienda Ejemplo' })).toBeTruthy();
            expect(screen.getByText('¿En qué podemos ayudarte?')).toBeTruthy();
            expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Iniciar conversación' }));
        });

        it('Esc la cierra y el foco vuelve al botón', async () => {
            server({});
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await user.keyboard('{Escape}');

            expect(screen.queryByRole('dialog')).toBeNull();
            expect(document.activeElement).toBe(screen.getByRole('button', { name: /Abrir el chat de soporte/ }));
        });

        it('el botón de cerrar de la cabecera también la cierra', async () => {
            server({});
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await user.click(screen.getByRole('button', { name: 'Cerrar el chat' }));

            expect(screen.queryByRole('dialog')).toBeNull();
        });

        it('iniciar la conversación lleva el foco al campo del mensaje', async () => {
            server({});
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            expect(document.activeElement).toBe(screen.getByLabelText('Tu mensaje'));
            expect(screen.getByText('Cuéntanos qué necesitas y un agente te responderá aquí.')).toBeTruthy();
        });
    });

    describe('enviar el primer mensaje', () => {
        it('lo muestra enseguida como «Enviando…», lo manda con la llave y sin token, y guarda el token que recibe', async () => {
            let release: (response: Response) => void = () => undefined;
            const calls = server({
                'POST /widget/api/messages': () => new Response(null),
                'GET /widget/api/conversation': () => json(conversation([customerMessage({ body: 'Mi pedido no llegó', client_id: 'x' })])),
            });
            vi.stubGlobal(
                'fetch',
                vi.fn((url: string, init: RequestInit) => {
                    calls.push({ url, init });
                    if (init.method === 'POST') {
                        return new Promise<Response>((resolve) => {
                            release = resolve;
                        });
                    }

                    return Promise.resolve(json(conversation([customerMessage({ body: 'Mi pedido no llegó', client_id: bodyOf(calls[0] as { init: RequestInit }).client_id as string })])));
                }),
            );
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            await user.type(screen.getByLabelText('Tu mensaje'), 'Mi pedido no llegó');
            await user.click(screen.getByRole('button', { name: 'Enviar' }));

            const pending = screen.getByText('Mi pedido no llegó').closest('article');
            expect(pending?.getAttribute('aria-busy')).toBe('true');
            expect(screen.getByText(/Enviando…/)).toBeTruthy();
            expect(screen.getByLabelText<HTMLTextAreaElement>('Tu mensaje').value).toBe('');

            const sent = bodyOf(calls[0] as { init: RequestInit });
            expect(sent.key).toBe(WIDGET_KEY);
            expect(sent.body).toBe('Mi pedido no llegó');
            expect(sent.client_id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
            const headers = (calls[0] as { init: RequestInit }).init.headers as Record<string, string>;
            expect(headers.Authorization).toBeUndefined();
            expect((calls[0] as { init: RequestInit }).init.credentials).toBe('omit');

            await act(async () => {
                release(json({ token: TOKEN, message: customerMessage() }, 201));
                await Promise.resolve();
            });

            await waitFor(() => {
                expect(screen.getByText('Mi pedido no llegó').closest('article')?.getAttribute('aria-busy')).toBeNull();
            });
            expect(window.localStorage.getItem(tokenKey(WIDGET_KEY))).toBe(TOKEN);
            expect(screen.queryByText(/Enviando…/)).toBeNull();
        });

        it('Enter envía y Mayús + Enter hace un salto de línea', async () => {
            const calls = server({ 'POST /widget/api/messages': () => json({ token: null, message: customerMessage() }) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            await user.type(screen.getByLabelText('Tu mensaje'), 'línea uno{Shift>}{Enter}{/Shift}línea dos{Enter}');

            await waitFor(() => {
                expect(calls.length).toBeGreaterThan(0);
            });
            expect(bodyOf(calls[0] as { init: RequestInit }).body).toBe('línea uno\nlínea dos');
        });

        it('no envía un mensaje vacío ni de solo espacios', async () => {
            const calls = server({});
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            const send = screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar' });
            expect(send.disabled).toBe(true);
            await user.type(screen.getByLabelText('Tu mensaje'), '   ');
            expect(send.disabled).toBe(true);
            await user.keyboard('{Enter}');

            expect(calls).toHaveLength(0);
        });

        it('avisa al acercarse al tope de caracteres y no deja enviar pasado el tope', async () => {
            server({});
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            const field = screen.getByLabelText('Tu mensaje');
            await user.click(field);
            await user.paste('a'.repeat(1800));
            expect(screen.getByText('Te quedan 200 caracteres.')).toBeTruthy();

            await user.paste('a'.repeat(201));
            expect(screen.getByRole('alert').textContent).toContain('El máximo es de 2000 caracteres');
            expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Enviar' }).disabled).toBe(true);
        });
    });

    describe('cuando algo falla', () => {
        it('sin conexión el mensaje queda en espera, se avisa y se envía solo al volver internet sin duplicarse', async () => {
            let online = false;
            const calls = server({});
            vi.stubGlobal(
                'fetch',
                vi.fn((url: string, init: RequestInit) => {
                    calls.push({ url, init });
                    if (!online) return Promise.reject(new TypeError('Failed to fetch'));

                    return Promise.resolve(init.method === 'POST' ? json({ token: TOKEN, message: customerMessage() }, 201) : json(conversation()));
                }),
            );
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            act(() => {
                setOnline(false);
            });
            expect(screen.getByText('Sin conexión. Los mensajes se enviarán cuando vuelvas a tener internet.')).toBeTruthy();

            await user.type(screen.getByLabelText('Tu mensaje'), 'Hola desde el metro{Enter}');
            expect(screen.getByText('Se enviará al volver la conexión.')).toBeTruthy();
            expect(calls).toHaveLength(0);

            online = true;
            await act(async () => {
                setOnline(true);
                await Promise.resolve();
            });

            await waitFor(() => {
                expect(screen.queryByText('Se enviará al volver la conexión.')).toBeNull();
            });
            const posts = calls.filter((call) => call.init.method === 'POST');
            expect(posts).toHaveLength(1);
            expect(screen.queryByText('Sin conexión. Los mensajes se enviarán cuando vuelvas a tener internet.')).toBeNull();
        });

        it('un mensaje en espera sobrevive a cerrar la página y se envía al volver', async () => {
            window.localStorage.setItem(
                `geckochat:${WIDGET_KEY}:outbox`,
                JSON.stringify([{ clientId: '22222222-2222-4222-8222-222222222222', body: 'Quedó pendiente', createdAt: '2026-11-01T15:00:00.000Z', status: 'sending', error: null }]),
            );
            const calls = server({ 'POST /widget/api/messages': () => json({ token: TOKEN, message: customerMessage({ client_id: '22222222-2222-4222-8222-222222222222' }) }, 201), 'GET /widget/api/conversation': () => json(conversation()) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await waitFor(() => {
                expect(calls.some((call) => call.init.method === 'POST')).toBe(true);
            });
            expect(bodyOf(calls.find((call) => call.init.method === 'POST') as { init: RequestInit }).client_id).toBe('22222222-2222-4222-8222-222222222222');
            expect(window.localStorage.getItem(`geckochat:${WIDGET_KEY}:outbox`)).toBeNull();
        });

        it('si el servidor lo rechaza muestra «No se envió.» con el motivo y «Reintentar» lo reenvía con el mismo identificador', async () => {
            let attempt = 0;
            const calls = server({
                'POST /widget/api/messages': () => {
                    attempt += 1;

                    return attempt === 1 ? json({ message: 'El chat no está disponible en este momento. Inténtalo de nuevo más tarde.' }, 503) : json({ token: TOKEN, message: customerMessage() }, 201);
                },
                'GET /widget/api/conversation': () => json(conversation()),
            });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            await user.click(screen.getByRole('button', { name: 'Iniciar conversación' }));

            await user.type(screen.getByLabelText('Tu mensaje'), 'Necesito ayuda{Enter}');

            await screen.findByText(/No se envió\./);
            expect(screen.getByText(/El chat no está disponible en este momento/)).toBeTruthy();

            await user.click(screen.getByRole('button', { name: 'Reintentar' }));

            await waitFor(() => {
                expect(screen.queryByText(/No se envió\./)).toBeNull();
            });
            const posts = calls.filter((call) => call.init.method === 'POST');
            expect(posts).toHaveLength(2);
            expect(bodyOf(posts[1] as { init: RequestInit }).client_id).toBe(bodyOf(posts[0] as { init: RequestInit }).client_id);
        });

        it('una sesión caducada no pierde el mensaje: abre una conversación nueva con el mismo texto', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            let first = true;
            const calls = server({
                'GET /widget/api/conversation': () => json(conversation()),
                'POST /widget/api/messages': (_url, init) => {
                    if (first) {
                        first = false;

                        return json({}, 401);
                    }
                    expect((init.headers as Record<string, string>).Authorization).toBeUndefined();

                    return json({ token: '1.' + 'b'.repeat(43), message: customerMessage() }, 201);
                },
            });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await user.type(await screen.findByLabelText('Tu mensaje'), 'Sigo aquí{Enter}');

            await waitFor(() => {
                expect(window.localStorage.getItem(tokenKey(WIDGET_KEY))).toBe('1.' + 'b'.repeat(43));
            });
            const posts = calls.filter((call) => call.init.method === 'POST');
            expect(posts).toHaveLength(2);
            expect(bodyOf(posts[1] as { init: RequestInit }).key).toBe(WIDGET_KEY);
            expect(bodyOf(posts[1] as { init: RequestInit }).body).toBe('Sigo aquí');
        });

        it('si la conversación no carga lo dice y deja reintentar', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            let ok = false;
            server({ 'GET /widget/api/conversation': () => (ok ? json(conversation()) : json({ message: 'x' }, 500)) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByText('No se pudo cargar la conversación.');
            ok = true;
            await user.click(screen.getByRole('button', { name: 'Reintentar' }));

            await screen.findByText('Hola, necesito ayuda');
            expect(screen.queryByText('No se pudo cargar la conversación.')).toBeNull();
        });

        it('un token caducado al abrir deja la bienvenida, sin mostrar un error', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json({}, 401) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByRole('button', { name: 'Iniciar conversación' });
            expect(window.localStorage.getItem(tokenKey(WIDGET_KEY))).toBeNull();
        });
    });

    describe('la conversación', () => {
        it('con una conversación en marcha abre directo en ella: mensajes del cliente y del equipo con su autor y hora', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation([customerMessage(), agentMessage()])) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByText('Con gusto te ayudo');

            expect(screen.queryByRole('button', { name: 'Iniciar conversación' })).toBeNull();
            const log = screen.getByRole('log', { name: 'Conversación con Tienda Ejemplo' });
            const articles = log.querySelectorAll('article');
            expect(articles).toHaveLength(2);
            expect(articles[0]?.getAttribute('aria-label')).toContain('Tú');
            expect(articles[1]?.getAttribute('aria-label')).toContain('Laura');
            expect(log.getAttribute('aria-live')).toBe('polite');
        });

        it('el texto del mensaje se muestra como texto, nunca como HTML', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation([agentMessage({ body: '<img src=x onerror=alert(1)> <b>hola</b>' })])) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByText('<img src=x onerror=alert(1)> <b>hola</b>');

            expect(document.querySelector('img[src="x"]')).toBeNull();
            expect(document.querySelector('b')).toBeNull();
        });

        it('cuando el ticket terminó lo dice y deja escribir', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation([customerMessage()], { ticket: { status: 'resolved', ended: true } })) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByText('Esta conversación terminó. Si escribes de nuevo, abrimos una nueva.');
            expect(screen.getByLabelText('Tu mensaje')).toBeTruthy();
        });
    });

    describe('mensajes sin leer', () => {
        it('con respuestas del equipo mientras está cerrado, el botón lo dice y la insignia se muestra', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation([customerMessage(), agentMessage({ id: 5 }), agentMessage({ id: 6, body: 'Otra' })])) });
            render(<Widget config={widgetConfig} />);

            const launcher = await screen.findByRole('button', { name: 'Abrir el chat de soporte. Mensajes nuevos: 2' });

            expect(launcher.textContent).toBe('2');
        });

        it('al abrirla y volver a cerrarla ya no quedan sin leer, ni después de recargar', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation([customerMessage(), agentMessage({ id: 5 })])) });
            const user = userEvent.setup();
            const { unmount } = render(<Widget config={widgetConfig} />);
            await user.click(await screen.findByRole('button', { name: /Mensajes nuevos: 1/ }));
            await screen.findByText('Con gusto te ayudo');

            await user.keyboard('{Escape}');

            expect(screen.getByRole('button', { name: 'Abrir el chat de soporte' })).toBeTruthy();

            unmount();
            render(<Widget config={widgetConfig} />);
            await waitFor(() => {
                expect(screen.getByRole('button', { name: 'Abrir el chat de soporte' })).toBeTruthy();
            });
        });
    });

    describe('dejar el correo', () => {
        it('se ofrece después del primer mensaje, es opcional y se puede descartar', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation()) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByText('¿Quieres dejar tu correo?');
            await user.click(screen.getByRole('button', { name: 'Ahora no' }));

            expect(screen.queryByText('¿Quieres dejar tu correo?')).toBeNull();
        });

        it('no se ofrece si ya dejó su correo', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({ 'GET /widget/api/conversation': () => json(conversation([customerMessage()], { contact: { has_email: true } })) });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await screen.findByText('Hola, necesito ayuda');

            expect(screen.queryByText('¿Quieres dejar tu correo?')).toBeNull();
        });

        it('lo guarda con el token y confirma', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            const calls = server({
                'GET /widget/api/conversation': () => json(conversation()),
                'POST /widget/api/contact': () => json({ saved: true }),
            });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await user.type(await screen.findByLabelText('Tu correo'), 'marisol@cliente.com');
            await user.click(screen.getByRole('button', { name: 'Guardar correo' }));

            await screen.findByText('Guardamos tu correo en la conversación.');
            const call = calls.find((item) => item.url === '/widget/api/contact') as { init: RequestInit };
            expect(bodyOf(call)).toEqual({ email: 'marisol@cliente.com' });
            expect((call.init.headers as Record<string, string>).Authorization).toBe(`Bearer ${TOKEN}`);
            expect(screen.queryByLabelText('Tu correo')).toBeNull();
        });

        it('un correo rechazado muestra el motivo junto al campo y conserva lo escrito', async () => {
            window.localStorage.setItem(tokenKey(WIDGET_KEY), TOKEN);
            server({
                'GET /widget/api/conversation': () => json(conversation()),
                'POST /widget/api/contact': () => json({ message: 'x', errors: { email: ['Escribe un correo válido, por ejemplo nombre@empresa.com.'] } }, 422),
            });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);

            await user.type(await screen.findByLabelText('Tu correo'), 'mal');
            await user.click(screen.getByRole('button', { name: 'Guardar correo' }));

            const message = await screen.findByText('Escribe un correo válido, por ejemplo nombre@empresa.com.');
            const field = screen.getByLabelText<HTMLInputElement>('Tu correo');
            expect(field.value).toBe('mal');
            expect(field.getAttribute('aria-describedby')).toContain(message.id);
        });
    });

    describe('la página que lo incrusta', () => {
        it('le cuenta su estado solo al sitio permitido', async () => {
            server({});
            const post = vi.fn();
            Object.defineProperty(window, 'parent', { value: { postMessage: post }, configurable: true });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);

            expect(post).toHaveBeenCalledWith({ source: 'geckochat-widget', type: 'state', open: false, unread: 0, corner: 'right' }, PARENT_ORIGIN);

            await openWindow(user);

            expect(post).toHaveBeenLastCalledWith(expect.objectContaining({ open: true }), PARENT_ORIGIN);
            Object.defineProperty(window, 'parent', { value: window, configurable: true });
        });

        it('solo obedece el diseño que manda su ventana padre desde el sitio permitido', async () => {
            server({});
            const fake = { postMessage: vi.fn() };
            Object.defineProperty(window, 'parent', { value: fake, configurable: true });
            const user = userEvent.setup();
            render(<Widget config={widgetConfig} />);
            await openWindow(user);
            const dialog = screen.getByRole('dialog');
            expect(dialog.className).toContain('rounded-lg');

            const layout = { source: 'geckochat-loader', type: 'layout', fullscreen: true };
            act(() => {
                window.dispatchEvent(new MessageEvent('message', { data: layout, origin: 'https://malicioso.example', source: fake as unknown as MessageEventSource }));
                window.dispatchEvent(new MessageEvent('message', { data: layout, origin: PARENT_ORIGIN, source: window }));
            });
            expect(screen.getByRole('dialog').className).toContain('rounded-lg');

            act(() => {
                window.dispatchEvent(new MessageEvent('message', { data: layout, origin: PARENT_ORIGIN, source: fake as unknown as MessageEventSource }));
            });
            expect(screen.getByRole('dialog').className).not.toContain('rounded-lg');
            Object.defineProperty(window, 'parent', { value: window, configurable: true });
        });
    });

    describe('el tema', () => {
        it('usa el modo claro por defecto y el que la empresa fije', () => {
            server({});
            const { unmount } = render(<Widget config={widgetConfig} />);
            expect(document.documentElement.dataset.theme).toBe('claro');
            unmount();

            render(<Widget config={{ ...widgetConfig, mode: 'oscuro' }} />);
            expect(document.documentElement.dataset.theme).toBe('oscuro');
        });
    });
});
