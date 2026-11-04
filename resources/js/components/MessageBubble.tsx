import type { AiDraftCopy, AttachmentsCopy, TicketMessageData } from '../ticketTypes';
import { AttachmentList } from './AttachmentList';
import { Badge } from './Badge';

interface MessageBubbleProps {
    message: TicketMessageData;
    /** Texto para quien no ve la posición: «Cliente» o «Agente». */
    authorLabel: string;
    attachmentsCopy: AttachmentsCopy;
    aiCopy: AiDraftCopy;
}

/**
 * Un turno de la conversación (design/componentes/burbuja-mensaje.md). El cliente va a la izquierda y el equipo a la
 * derecha, pero el orden del DOM es el cronológico. El texto es siempre texto: React lo escapa y los saltos de línea
 * se respetan con whitespace-pre-wrap.
 */
export function MessageBubble({ message, authorLabel, attachmentsCopy, aiCopy }: MessageBubbleProps) {
    const fromCustomer = message.author === 'customer';
    const fromAi = message.author === 'ai';

    return (
        <article aria-label={`${authorLabel}: ${message.author_name ?? ''}, ${message.created_label}`} className={`flex flex-col gap-1 ${fromCustomer ? 'items-start' : 'items-end'}`}>
            <p className="flex flex-wrap items-center gap-x-1 text-xs text-text-tertiary">
                <span className="font-medium text-text-secondary">{message.author_name}</span>
                {fromAi && <Badge variant="info">{aiCopy.badge}</Badge>}
                <span aria-hidden="true">·</span>
                <time dateTime={message.created_at}>{message.created_label}</time>
                {fromAi && message.approved_by !== null && <span>· {aiCopy.approved_by.replace(':name', message.approved_by)}</span>}
            </p>
            {message.body !== '' && (
                <div
                    className={`max-w-[85%] whitespace-pre-wrap break-words px-4 py-3 text-base ${
                        fromCustomer
                            ? 'rounded-lg rounded-tl-[4px] bg-bubble-cliente text-bubble-cliente-texto'
                            : 'rounded-lg rounded-tr-[4px] bg-bubble-agente text-bubble-agente-texto'
                    }`}
                >
                    {message.body}
                </div>
            )}
            {message.attachments.length > 0 && (
                <div className="w-full max-w-[85%]">
                    <AttachmentList attachments={message.attachments} copy={attachmentsCopy} />
                </div>
            )}
        </article>
    );
}
