import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Handler = (payload?: unknown) => void;

interface FakeChannel {
    name: string;
    subscribed: boolean;
    handlers: Map<string, Set<Handler>>;
    bind: (event: string, handler: Handler) => void;
    unbind: (event: string, handler: Handler) => void;
    emit: (event: string) => void;
}

const created: { key: string; options: Record<string, unknown>; connection: { state: string; handlers: Handler[] }; channels: Map<string, FakeChannel>; disconnect: ReturnType<typeof vi.fn>; unsubscribe: ReturnType<typeof vi.fn> }[] = [];

vi.mock('pusher-js', () => ({
    default: class FakePusher {
        connection = { state: 'initialized', handlers: [] as Handler[], bind: (_event: string, handler: Handler) => this.connection.handlers.push(handler) };
        channels = new Map<string, FakeChannel>();
        disconnect = vi.fn();
        unsubscribe = vi.fn((name: string) => this.channels.delete(name));

        constructor(
            public key: string,
            public options: Record<string, unknown>,
        ) {
            created.push(this);
        }

        subscribe(name: string): FakeChannel {
            const existing = this.channels.get(name);
            if (existing !== undefined) return existing;

            const channel: FakeChannel = {
                name,
                subscribed: false,
                handlers: new Map(),
                bind: (event, handler) => {
                    const set = channel.handlers.get(event) ?? new Set<Handler>();
                    set.add(handler);
                    channel.handlers.set(event, set);
                },
                unbind: (event, handler) => {
                    channel.handlers.get(event)?.delete(handler);
                },
                emit: (event) => {
                    channel.handlers.get(event)?.forEach((handler) => {
                        handler();
                    });
                },
            };
            this.channels.set(name, channel);

            return channel;
        }
    },
}));

async function load() {
    vi.resetModules();

    return import('./client');
}

function lastPusher() {
    const pusher = created.at(-1);
    if (pusher === undefined) throw new Error('No se creó la conexión.');

    return pusher;
}

function changeState(current: string): void {
    lastPusher().connection.handlers.forEach((handler) => {
        handler({ current });
    });
}

describe('conexión en vivo', () => {
    beforeEach(() => {
        created.length = 0;
        vi.stubEnv('VITE_REVERB_APP_KEY', 'llave-publica');
    });

    afterEach(() => {
        vi.unstubAllEnvs();
        document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    it('sin llave configurada queda «no disponible» y no abre nada', async () => {
        vi.stubEnv('VITE_REVERB_APP_KEY', '');
        const client = await load();

        client.connect();

        expect(client.getConnectionState()).toBe('unavailable');
        expect(created).toHaveLength(0);
    });

    it('se conecta al mismo sitio, solo por WebSocket y sin enviar estadísticas', async () => {
        const client = await load();

        client.connect();

        const pusher = lastPusher();
        expect(pusher.key).toBe('llave-publica');
        expect(pusher.options).toMatchObject({ wsHost: window.location.hostname, forceTLS: false, enabledTransports: ['ws', 'wss'], disableStats: true });
    });

    it('autoriza cada canal en el servidor, con la sesión y el token de CSRF', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';
        const client = await load();

        client.connect();

        const auth = lastPusher().options.channelAuthorization as { endpoint: string; transport: string; headersProvider: () => Record<string, string> };
        expect(auth.endpoint).toBe('/broadcasting/auth');
        expect(auth.transport).toBe('ajax');
        expect(auth.headersProvider()).toEqual({ 'X-XSRF-TOKEN': 'abc==', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' });
    });

    it('sin la cookie de CSRF envía el encabezado vacío en vez de romper', async () => {
        const client = await load();

        client.connect();

        const auth = lastPusher().options.channelAuthorization as { headersProvider: () => Record<string, string> };
        expect(auth.headersProvider()['X-XSRF-TOKEN']).toBe('');
    });

    it('conectar dos veces no abre una segunda conexión', async () => {
        const client = await load();

        client.connect();
        client.connect();

        expect(created).toHaveLength(1);
    });

    it('traduce el estado de la conexión y avisa solo cuando cambia', async () => {
        const client = await load();
        const listener = vi.fn();
        client.onConnectionState(listener);
        client.connect();
        expect(client.getConnectionState()).toBe('connecting');

        const seen: string[] = [];
        for (const current of ['connected', 'connected', 'unavailable', 'connecting', 'failed', 'disconnected']) {
            changeState(current);
            seen.push(client.getConnectionState());
        }

        expect(seen).toEqual(['connected', 'connected', 'unavailable', 'connecting', 'unavailable', 'disconnected']);
        // connecting, connected, unavailable, connecting, unavailable, disconnected: seis cambios, sin repetir el segundo «connected».
        expect(listener).toHaveBeenCalledTimes(6);
    });

    it('quien deja de escuchar el estado no recibe más avisos', async () => {
        const client = await load();
        const listener = vi.fn();
        const stop = client.onConnectionState(listener);
        client.connect();
        listener.mockClear();

        stop();
        changeState('connected');

        expect(listener).not.toHaveBeenCalled();
    });

    it('al desconectar cierra la conexión, olvida los canales y la siguiente conexión es nueva', async () => {
        const client = await load();
        client.connect();
        const first = lastPusher();
        client.listen('tenant.1.inbox', () => undefined);

        client.disconnect();

        expect(first.disconnect).toHaveBeenCalledTimes(1);
        expect(client.getConnectionState()).toBe('disconnected');
        client.connect();
        expect(created).toHaveLength(2);
        expect(lastPusher().channels.size).toBe(0);
    });
});

describe('canales', () => {
    beforeEach(() => {
        created.length = 0;
        vi.stubEnv('VITE_REVERB_APP_KEY', 'llave-publica');
    });

    afterEach(() => {
        vi.unstubAllEnvs();
    });

    it('sin conexión abierta no suscribe a nada y queda pendiente', async () => {
        const client = await load();
        const states: string[] = [];

        const stop = client.listen('tenant.1.inbox', (state) => states.push(state));
        stop();

        expect(states).toEqual(['pending']);
        expect(created).toHaveLength(0);
    });

    it('entra a un canal privado con su prefijo y avisa cuando el servidor lo autoriza', async () => {
        const client = await load();
        client.connect();
        const states: string[] = [];

        client.listen('tenant.7.inbox', (state) => states.push(state));
        const channel = lastPusher().channels.get('private-tenant.7.inbox');
        channel?.emit('pusher:subscription_succeeded');

        expect(channel).toBeDefined();
        expect(states).toEqual(['pending', 'subscribed']);
    });

    it('si el servidor no lo autoriza, avisa «denegado»', async () => {
        const client = await load();
        client.connect();
        const states: string[] = [];

        client.listen('tenant.7.tickets.9', (state) => states.push(state));
        lastPusher().channels.get('private-tenant.7.tickets.9')?.emit('pusher:subscription_error');

        expect(states).toEqual(['pending', 'denied']);
    });

    it('un canal ya autorizado se reporta como suscrito al instante', async () => {
        const client = await load();
        client.connect();
        client.listen('tenant.7.inbox', () => undefined);
        const channel = lastPusher().channels.get('private-tenant.7.inbox');
        if (channel !== undefined) channel.subscribed = true;
        const states: string[] = [];

        client.listen('tenant.7.inbox', (state) => states.push(state));

        expect(states).toEqual(['subscribed']);
    });

    it('varios usuarios del mismo canal lo comparten y se cierra cuando se va el último', async () => {
        const client = await load();
        client.connect();
        const pusher = lastPusher();

        const stopFirst = client.listen('tenant.7.inbox', () => undefined);
        const stopSecond = client.listen('tenant.7.inbox', () => undefined);
        expect(pusher.channels.size).toBe(1);

        stopFirst();
        expect(pusher.unsubscribe).not.toHaveBeenCalled();

        stopSecond();
        expect(pusher.unsubscribe).toHaveBeenCalledWith('private-tenant.7.inbox');
    });

    it('quien deja de escuchar no recibe más resultados', async () => {
        const client = await load();
        client.connect();
        const states: string[] = [];

        const stop = client.listen('tenant.7.inbox', (state) => states.push(state));
        const channel = lastPusher().channels.get('private-tenant.7.inbox');
        stop();
        channel?.emit('pusher:subscription_succeeded');

        expect(states).toEqual(['pending']);
    });

    it('entrega los eventos pedidos del canal y deja de entregarlos al salir', async () => {
        const client = await load();
        client.connect();
        const received: string[] = [];

        const stop = client.listen('tenant.7.inbox', () => undefined, {
            'inbox.changed': () => received.push('bandeja'),
        });
        const channel = lastPusher().channels.get('private-tenant.7.inbox');
        channel?.emit('inbox.changed');
        channel?.emit('otro.evento');
        stop();
        channel?.emit('inbox.changed');

        expect(received).toEqual(['bandeja']);
    });

    it('canales distintos no se mezclan', async () => {
        const client = await load();
        client.connect();

        client.listen('tenant.7.inbox', () => undefined);
        client.listen('tenant.7.tickets.3', () => undefined);

        expect([...lastPusher().channels.keys()].sort()).toEqual(['private-tenant.7.inbox', 'private-tenant.7.tickets.3']);
    });
});
