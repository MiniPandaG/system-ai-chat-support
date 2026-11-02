import type { OutgoingMessage, WidgetCopy, WidgetMessage } from './types';

interface WidgetBubbleProps {
    copy: WidgetCopy;
    company: string;
    message: WidgetMessage;
}

const timeFormat = new Intl.DateTimeFormat('es', { hour: '2-digit', minute: '2-digit' });

export function timeOf(iso: string): string {
    const date = new Date(iso);

    return Number.isNaN(date.getTime()) ? '' : timeFormat.format(date);
}

/**
 * Un turno de la conversación (design/componentes/burbuja-mensaje.md): el cliente a la izquierda y el equipo a la
 * derecha, con el orden del DOM cronológico. El texto es siempre texto; los saltos de línea se respetan.
 */
export function WidgetBubble({ copy, company, message }: WidgetBubbleProps) {
    const fromCustomer = message.author === 'customer';
    const sender = fromCustomer ? copy.sender_customer : (message.agent ?? copy.sender_team);
    const time = timeOf(message.sent_at);

    return (
        <article aria-label={`${sender}${fromCustomer ? '' : `, ${company}`}, ${time}`} className={`flex flex-col gap-1 ${fromCustomer ? 'items-start' : 'items-end'}`}>
            <p className="text-xs text-text-tertiary">
                <span className="font-medium text-text-secondary">{sender}</span> · <time dateTime={message.sent_at}>{time}</time>
            </p>
            <div
                className={`max-w-[85%] whitespace-pre-wrap break-words px-4 py-3 text-base ${
                    fromCustomer ? 'rounded-lg rounded-tl-[4px] bg-bubble-cliente text-bubble-cliente-texto' : 'rounded-lg rounded-tr-[4px] border border-border bg-bubble-agente text-bubble-agente-texto'
                }`}
            >
                {message.body}
            </div>
        </article>
    );
}

interface PendingBubbleProps {
    copy: WidgetCopy;
    entry: OutgoingMessage;
    onRetry: (clientId: string) => void;
}

/**
 * Un mensaje que el servidor aún no confirmó. «Enviando» y «en espera» se ven atenuados; uno rechazado muestra su
 * motivo y «Reintentar» (design/componentes/burbuja-mensaje.md, estado fallido).
 */
export function PendingBubble({ copy, entry, onRetry }: PendingBubbleProps) {
    const failed = entry.status === 'failed';
    const note = entry.status === 'sending' ? copy.sending : entry.status === 'queued' ? copy.queued : copy.failed;

    return (
        <article aria-busy={!failed} aria-label={`${copy.sender_customer}, ${note}`} className="flex flex-col items-start gap-1">
            <p className="text-xs text-text-tertiary">
                <span className="font-medium text-text-secondary">{copy.sender_customer}</span> · {timeOf(entry.createdAt)}
            </p>
            <div
                className={`max-w-[85%] whitespace-pre-wrap break-words rounded-lg rounded-tl-[4px] bg-bubble-cliente px-4 py-3 text-base text-bubble-cliente-texto ${
                    failed ? 'border border-danger-border' : 'opacity-70'
                }`}
            >
                {entry.body}
            </div>
            <p className={`text-xs ${failed ? 'text-danger' : 'text-text-secondary'}`}>
                {note}
                {failed && entry.error !== null && entry.error !== '' ? ` ${entry.error}` : ''}
                {failed && (
                    <>
                        {' '}
                        <button
                            type="button"
                            onClick={() => {
                                onRetry(entry.clientId);
                            }}
                            className="font-medium text-text underline underline-offset-2 hover:text-primary"
                        >
                            {copy.retry}
                        </button>
                    </>
                )}
            </p>
        </article>
    );
}
