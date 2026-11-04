import type { Conversation, WidgetMessage } from './types';

/** No se pudo hablar con el servidor (sin conexión, corte): el mensaje puede reintentarse sin riesgo. */
export class NetworkError extends Error {}

/** El token de la sesión ya no sirve: caducó, el widget se apagó o nunca fue válido. */
export class SessionExpiredError extends Error {}

/** El servidor respondió y rechazó la petición; el mensaje ya viene en español y es el que se muestra. */
export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
    }
}

interface ErrorBody {
    message?: unknown;
    errors?: unknown;
}

/**
 * El primer mensaje de error de validación, si el servidor mandó alguno; si no, su mensaje general. Vacío si no hay
 * ninguno: quien lo muestra usa entonces el texto genérico de la interfaz.
 */
function messageOf(body: ErrorBody): string {
    if (typeof body.errors === 'object' && body.errors !== null) {
        for (const list of Object.values(body.errors)) {
            if (Array.isArray(list) && typeof list[0] === 'string') return list[0];
        }
    }

    return typeof body.message === 'string' ? body.message : '';
}

async function request<T>(path: string, init: { method: 'GET' | 'POST'; token: string | null; body?: unknown }): Promise<T> {
    let response: Response;

    try {
        response = await fetch(`/widget/api/${path}`, {
            method: init.method,
            // Sin cookies: la identidad del visitante es el token, así que ningún sitio ajeno puede aprovechar una sesión.
            credentials: 'omit',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                ...(init.body === undefined ? {} : { 'Content-Type': 'application/json' }),
                ...(init.token === null ? {} : { Authorization: `Bearer ${init.token}` }),
            },
            ...(init.body === undefined ? {} : { body: JSON.stringify(init.body) }),
        });
    } catch {
        throw new NetworkError('network');
    }

    if (response.status === 401) throw new SessionExpiredError('expired');

    // Un corte del servidor o del proxy (502, 503 sin cuerpo) se trata como falta de conexión: no es un rechazo del mensaje.
    if (response.status === 502 || response.status === 504) throw new NetworkError('gateway');

    if (!response.ok) {
        const body = (await response.json().catch(() => ({}))) as ErrorBody;

        throw new ApiError(messageOf(body), response.status);
    }

    return (await response.json()) as T;
}

export interface SendInput {
    clientId: string;
    body: string;
}

export interface Sent {
    /** Solo cuando la sesión nace con este mensaje. */
    token: string | null;
    message: WidgetMessage;
}

/** Envía un mensaje. Sin token abre la conversación (la llave dice a qué empresa). */
export function sendMessage(key: string, token: string | null, input: SendInput): Promise<Sent> {
    return request<Sent>('messages', {
        method: 'POST',
        token,
        body: { client_id: input.clientId, body: input.body, ...(token === null ? { key } : {}) },
    });
}

export function fetchConversation(token: string): Promise<Conversation> {
    return request<Conversation>('conversation', { method: 'GET', token });
}

export function saveEmail(token: string, email: string): Promise<{ saved: boolean }> {
    return request<{ saved: boolean }>('contact', { method: 'POST', token, body: { email } });
}

export function answerSurvey(token: string, score: number, comment: string): Promise<{ answered: boolean }> {
    return request<{ answered: boolean }>('survey', { method: 'POST', token, body: { score, comment } });
}
