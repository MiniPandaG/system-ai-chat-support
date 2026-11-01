import Pusher from 'pusher-js';
import type { Channel } from 'pusher-js';

/** Cómo está la conexión en vivo. «unavailable»: sin llave de Reverb o sin poder conectar. */
export type ConnectionState = 'connecting' | 'connected' | 'disconnected' | 'unavailable';

/** Cómo va la suscripción a un canal: «denied» cuando el servidor no autoriza entrar. */
export type SubscriptionState = 'pending' | 'subscribed' | 'denied';

interface Subscription {
    channel: Channel;
    users: number;
}

const AUTH_ENDPOINT = '/broadcasting/auth';

let pusher: Pusher | null = null;
let state: ConnectionState = 'disconnected';
const stateListeners = new Set<() => void>();
const subscriptions = new Map<string, Subscription>();

function setState(next: ConnectionState): void {
    if (next === state) return;

    state = next;
    stateListeners.forEach((listener) => {
        listener();
    });
}

/** El valor de la cookie de CSRF que Laravel entrega; se reenvía en cada autorización de canal. */
export function xsrfToken(): string {
    const entry = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));

    return entry === undefined ? '' : decodeURIComponent(entry.slice('XSRF-TOKEN='.length));
}

function mapState(current: string): ConnectionState {
    switch (current) {
        case 'connected':
            return 'connected';
        case 'connecting':
        case 'initialized':
            return 'connecting';
        case 'unavailable':
        case 'failed':
            return 'unavailable';
        default:
            return 'disconnected';
    }
}

/**
 * Abre la conexión en vivo, una sola vez por pestaña. Se conecta al mismo sitio por /app (nginx lo lleva a Reverb) y
 * cada canal se autoriza contra el servidor con la sesión del panel. Sin llave configurada queda «no disponible» y la
 * aplicación sigue funcionando sin tiempo real.
 */
export function connect(): void {
    if (pusher !== null) return;

    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (key === undefined || key === '') {
        setState('unavailable');

        return;
    }

    const secure = window.location.protocol === 'https:';
    const port = Number(window.location.port === '' ? (secure ? 443 : 80) : window.location.port);

    pusher = new Pusher(key, {
        cluster: 'mt1',
        wsHost: window.location.hostname,
        wsPort: port,
        wssPort: port,
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        channelAuthorization: {
            transport: 'ajax',
            endpoint: AUTH_ENDPOINT,
            headersProvider: () => ({ 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }),
        },
    });

    setState(mapState(pusher.connection.state));
    pusher.connection.bind('state_change', (change: { current: string }) => {
        setState(mapState(change.current));
    });
}

/** Cierra la conexión y olvida los canales (por ejemplo, al cerrar sesión: la siguiente persona no hereda nada). */
export function disconnect(): void {
    subscriptions.clear();
    pusher?.disconnect();
    pusher = null;
    setState('disconnected');
}

export function getConnectionState(): ConnectionState {
    return state;
}

export function onConnectionState(listener: () => void): () => void {
    stateListeners.add(listener);

    return () => {
        stateListeners.delete(listener);
    };
}

/** Los avisos que se esperan de un canal: nombre del evento y qué hacer. Los avisos solo traen identificadores. */
export type ChannelEvents = Record<string, (data: unknown) => void>;

/**
 * Se suscribe a un canal privado (el nombre va sin el prefijo «private-»), avisa del resultado de la autorización y
 * entrega los eventos pedidos. Varias personas de la pantalla pueden pedir el mismo canal: se comparte y se cierra
 * cuando ya nadie lo usa.
 */
export function listen(name: string, onSubscription: (state: SubscriptionState) => void, events: ChannelEvents = {}): () => void {
    if (pusher === null) {
        onSubscription('pending');

        return () => undefined;
    }

    const existing = subscriptions.get(name);
    const subscription = existing ?? { channel: pusher.subscribe(`private-${name}`), users: 0 };
    subscription.users += 1;
    subscriptions.set(name, subscription);

    const channel = subscription.channel;
    const succeeded = () => {
        onSubscription('subscribed');
    };
    const failed = () => {
        onSubscription('denied');
    };
    channel.bind('pusher:subscription_succeeded', succeeded);
    channel.bind('pusher:subscription_error', failed);
    for (const [event, handler] of Object.entries(events)) {
        channel.bind(event, handler);
    }
    onSubscription(channel.subscribed ? 'subscribed' : 'pending');

    return () => {
        channel.unbind('pusher:subscription_succeeded', succeeded);
        channel.unbind('pusher:subscription_error', failed);
        for (const [event, handler] of Object.entries(events)) {
            channel.unbind(event, handler);
        }
        subscription.users -= 1;

        if (subscription.users <= 0 && pusher !== null) {
            subscriptions.delete(name);
            pusher.unsubscribe(`private-${name}`);
        }
    };
}
