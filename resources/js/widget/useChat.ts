import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useChannel, useConnectionState } from '../realtime/hooks';
import { answerSurvey as sendSurvey, ApiError, fetchConversation, NetworkError, saveEmail, sendMessage, SessionExpiredError } from './api';
import { readStored, removeStored, writeStored } from './storage';
import type { Conversation, OutgoingMessage, WidgetMessage } from './types';

/** Cada cuánto se vuelve a pedir la conversación si no hay conexión en vivo, y cada cuánto se reintenta lo pendiente. */
const FALLBACK_POLL_MS = 15000;
const RETRY_QUEUED_MS = 8000;

export const tokenKey = (key: string): string => `geckochat:${key}:token`;
const outboxKey = (key: string): string => `geckochat:${key}:outbox`;

/** Un identificador único para cada mensaje; el servidor lo usa para no duplicarlo si se reenvía. */
export function newClientId(): string {
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function readOutbox(key: string): OutgoingMessage[] {
    try {
        const parsed: unknown = JSON.parse(readStored(outboxKey(key)) ?? '[]');
        if (!Array.isArray(parsed)) return [];

        return parsed.flatMap((entry: unknown): OutgoingMessage[] => {
            if (typeof entry !== 'object' || entry === null) return [];
            const { clientId, body, createdAt } = entry as Record<string, unknown>;

            // Lo que quedó a medias al cerrar la pestaña vuelve como pendiente: se enviará solo al haber conexión.
            return typeof clientId === 'string' && typeof body === 'string' && typeof createdAt === 'string'
                ? [{ clientId, body, createdAt, status: 'queued', error: null }]
                : [];
        });
    } catch {
        return [];
    }
}

export interface Chat {
    /** El token existe: ya hay una conversación (o hubo una). */
    started: boolean;
    conversation: Conversation | null;
    /** Lo que ve el visitante: lo confirmado por el servidor y, al final, lo que aún se está enviando. */
    outgoing: OutgoingMessage[];
    loading: boolean;
    loadFailed: boolean;
    online: boolean;
    contactSaved: boolean;
    contactError: string | null;
    send: (body: string) => void;
    retry: (clientId: string) => void;
    reload: () => void;
    saveContact: (email: string) => Promise<boolean>;
    /** La encuesta ya se contestó desde esta ventana. */
    surveyAnswered: boolean;
    surveyError: string | null;
    answerSurvey: (score: number, comment: string) => Promise<boolean>;
}

/**
 * Toda la lógica de la conversación del visitante: carga, envío con mensajes pendientes que sobreviven a una caída de
 * red, y actualización en vivo. El token vive en el almacenamiento del marco; el servidor es la única fuente de lo que
 * se muestra como enviado.
 */
export function useChat(widgetKey: string): Chat {
    const [token, setToken] = useState<string | null>(() => readStored(tokenKey(widgetKey)));
    const [conversation, setConversation] = useState<Conversation | null>(null);
    const [outgoing, setOutgoing] = useState<OutgoingMessage[]>(() => readOutbox(widgetKey));
    const [loading, setLoading] = useState(token !== null);
    const [loadFailed, setLoadFailed] = useState(false);
    const [online, setOnline] = useState(() => navigator.onLine);
    const [contactSaved, setContactSaved] = useState(false);
    const [contactError, setContactError] = useState<string | null>(null);
    const [surveyAnswered, setSurveyAnswered] = useState(false);
    const [surveyError, setSurveyError] = useState<string | null>(null);
    const connection = useConnectionState();

    const tokenRef = useRef(token);
    const outgoingRef = useRef(outgoing);
    const flushingRef = useRef(false);

    const updateToken = useCallback(
        (next: string | null) => {
            tokenRef.current = next;
            setToken(next);

            if (next === null) removeStored(tokenKey(widgetKey));
            else writeStored(tokenKey(widgetKey), next);
        },
        [widgetKey],
    );

    const updateOutgoing = useCallback(
        (change: (current: OutgoingMessage[]) => OutgoingMessage[]) => {
            const next = change(outgoingRef.current);
            outgoingRef.current = next;
            setOutgoing(next);

            if (next.length === 0) removeStored(outboxKey(widgetKey));
            else writeStored(outboxKey(widgetKey), JSON.stringify(next));
        },
        [widgetKey],
    );

    const load = useCallback(async () => {
        const current = tokenRef.current;
        if (current === null) {
            setLoading(false);

            return;
        }

        try {
            setConversation(await fetchConversation(current));
            setLoadFailed(false);
        } catch (error) {
            if (error instanceof SessionExpiredError) {
                // La sesión caducó: se empieza de cero, sin mostrar un error.
                updateToken(null);
                setConversation(null);
            } else {
                setLoadFailed(true);
            }
        } finally {
            setLoading(false);
        }
    }, [updateToken]);

    const deliver = useCallback(
        async (entry: OutgoingMessage) => {
            updateOutgoing((list) => list.map((item) => (item.clientId === entry.clientId ? { ...item, status: 'sending', error: null } : item)));

            try {
                let sent;

                try {
                    sent = await sendMessage(widgetKey, tokenRef.current, { clientId: entry.clientId, body: entry.body });
                } catch (error) {
                    if (!(error instanceof SessionExpiredError)) throw error;

                    // Una sesión caducada no pierde el mensaje: se abre una conversación nueva con el mismo texto.
                    updateToken(null);
                    setConversation(null);
                    sent = await sendMessage(widgetKey, null, { clientId: entry.clientId, body: entry.body });
                }

                if (sent.token !== null) updateToken(sent.token);
                updateOutgoing((list) => list.filter((item) => item.clientId !== entry.clientId));
                await load();
            } catch (error) {
                const status = error instanceof NetworkError || error instanceof SessionExpiredError ? 'queued' : 'failed';
                const message = error instanceof ApiError ? error.message : null;

                updateOutgoing((list) => list.map((item) => (item.clientId === entry.clientId ? { ...item, status, error: message } : item)));
            }
        },
        [load, updateOutgoing, updateToken, widgetKey],
    );

    /** Envía, en orden, lo que quedó pendiente por falta de conexión. */
    const flush = useCallback(async () => {
        if (flushingRef.current || !navigator.onLine) return;
        flushingRef.current = true;

        try {
            for (const entry of outgoingRef.current.filter((item) => item.status === 'queued')) {
                await deliver(entry);
                // Si volvió a fallar por red, no se insiste con los demás hasta el próximo intento.
                if (outgoingRef.current.some((item) => item.clientId === entry.clientId && item.status === 'queued')) break;
            }
        } finally {
            flushingRef.current = false;
        }
    }, [deliver]);

    useEffect(() => {
        void load();
    }, [load]);

    useEffect(() => {
        const goOnline = () => {
            setOnline(true);
            void flush();
        };
        const goOffline = () => {
            setOnline(false);
        };
        window.addEventListener('online', goOnline);
        window.addEventListener('offline', goOffline);

        return () => {
            window.removeEventListener('online', goOnline);
            window.removeEventListener('offline', goOffline);
        };
    }, [flush]);

    // Lo pendiente se reintenta solo, sin duplicarse: el servidor reconoce el identificador de cada mensaje.
    useEffect(() => {
        if (!outgoing.some((item) => item.status === 'queued')) return;

        const timer = window.setInterval(() => {
            void flush();
        }, RETRY_QUEUED_MS);
        void flush();

        return () => {
            window.clearInterval(timer);
        };
    }, [outgoing, flush]);

    // Con la conexión en vivo caída, la conversación se vuelve a pedir cada tanto para no quedarse sin respuestas.
    const live = connection === 'connected';
    useEffect(() => {
        if (token === null || live) return;

        const timer = window.setInterval(() => {
            void load();
        }, FALLBACK_POLL_MS);

        return () => {
            window.clearInterval(timer);
        };
    }, [token, live, load]);

    // Al reconectar el tiempo real se pide todo una vez: pudo llegar algo mientras no había canal.
    useEffect(() => {
        if (live) void load();
    }, [live, load]);

    useChannel(conversation?.channel ?? null, {
        'widget.changed': () => {
            void load();
        },
    });

    const send = useCallback(
        (body: string) => {
            const entry: OutgoingMessage = {
                clientId: newClientId(),
                body,
                createdAt: new Date().toISOString(),
                status: navigator.onLine ? 'sending' : 'queued',
                error: null,
            };
            updateOutgoing((list) => [...list, entry]);
            if (entry.status === 'sending') void deliver(entry);
        },
        [deliver, updateOutgoing],
    );

    const retry = useCallback(
        (clientId: string) => {
            const entry = outgoingRef.current.find((item) => item.clientId === clientId);
            if (entry !== undefined) void deliver(entry);
        },
        [deliver],
    );

    const reload = useCallback(() => {
        setLoadFailed(false);
        setLoading(true);
        void load();
    }, [load]);

    const saveContact = useCallback(async (email: string): Promise<boolean> => {
        const current = tokenRef.current;
        if (current === null) return false;

        setContactError(null);

        try {
            await saveEmail(current, email);
            setContactSaved(true);
            setConversation((value) => (value === null ? value : { ...value, contact: { has_email: true } }));

            return true;
        } catch (error) {
            setContactError(error instanceof ApiError ? error.message : '');

            return false;
        }
    }, []);

    const answerSurvey = useCallback(async (score: number, comment: string): Promise<boolean> => {
        const current = tokenRef.current;
        if (current === null) return false;

        setSurveyError(null);

        try {
            await sendSurvey(current, score, comment);
            setSurveyAnswered(true);
            setConversation((value) => (value === null ? value : { ...value, survey: { pending: false } }));

            return true;
        } catch (error) {
            setSurveyError(error instanceof ApiError ? error.message : '');

            return false;
        }
    }, []);

    return useMemo(
        () => ({ started: token !== null || outgoing.length > 0, conversation, outgoing, loading, loadFailed, online, contactSaved, contactError, send, retry, reload, saveContact, surveyAnswered, surveyError, answerSurvey }),
        [token, conversation, outgoing, loading, loadFailed, online, contactSaved, contactError, send, retry, reload, saveContact, surveyAnswered, surveyError, answerSurvey],
    );
}

/** Los mensajes del servidor y, a continuación, los que aún no confirmó, sin repetir ninguno. */
export function visibleMessages(chat: Chat): { confirmed: WidgetMessage[]; pending: OutgoingMessage[] } {
    const confirmed = chat.conversation?.messages ?? [];
    const known = new Set(confirmed.map((message) => message.client_id));

    return { confirmed, pending: chat.outgoing.filter((entry) => !known.has(entry.clientId)) };
}
