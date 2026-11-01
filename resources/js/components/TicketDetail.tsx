import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { Assignee, TagData, TicketDetailData, TicketPriority, TicketsCopy, TicketStatus } from '../ticketTypes';
import type { SharedProps } from '../types';
import { createTypingSender, postTyping } from '../lib/typing';
import { useConnectionState } from '../realtime/hooks';
import { useTypists } from '../realtime/useTypists';
import { Alert } from './Alert';
import { Button } from './Button';
import { MessageBubble } from './MessageBubble';
import { NoteBubble } from './NoteBubble';
import { OutgoingBubble } from './OutgoingBubble';
import type { Outgoing } from './OutgoingBubble';
import { SeenBy } from './SeenBy';
import { SelectField } from './SelectField';
import { TicketComposer } from './TicketComposer';
import { PriorityBadge, StatusBadge } from './TicketBadges';
import { TicketTags } from './TicketTags';
import { TypingIndicator } from './TypingIndicator';

interface TicketDetailProps {
    ticket: TicketDetailData;
    assignees: Assignee[];
    tagOptions: TagData[];
    copy: TicketsCopy;
    canUpdate: boolean;
}

const statuses: TicketStatus[] = ['open', 'waiting_customer', 'resolved', 'closed'];
const priorities: TicketPriority[] = ['low', 'normal', 'high', 'urgent'];

/**
 * Cabecera del ticket, su conversación y el compositor (design/pantallas/vista-ticket-conversacion.md). La
 * conversación mezcla mensajes y notas internas por hora de creación; el compositor conserva lo escrito aunque se
 * cambie el estado, la prioridad o quien atiende.
 */
export function TicketDetail({ ticket, assignees, tagOptions, copy, canUpdate }: TicketDetailProps) {
    const bodyRef = useRef<HTMLDivElement>(null);
    const threadRef = useRef<HTMLDivElement>(null);
    const { props } = usePage<SharedProps>();
    const [outgoing, setOutgoing] = useState<Outgoing | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const connection = useConnectionState();
    // El componente se vuelve a montar al cambiar de ticket, así que el limitador de señales empieza de cero con cada uno.
    const [sendTyping] = useState(() => createTypingSender(() => {
        postTyping(ticket.id);
    }));
    const typists = useTypists(props.auth?.tenant?.id ?? null, ticket.id, props.auth?.user.id ?? null);
    const typingPeople = typists.map((id) => assignees.find((person) => person.id === id)?.name ?? null);
    const entries = ticket.thread.length;

    // Al abrir el ticket y cuando llega algo nuevo (o se está enviando algo) se muestra el final; el foco no se mueve.
    // En pantallas angostas se desplaza todo el cuerpo y en anchas solo la conversación, así que se prueba con ambos.
    const sending = outgoing !== null;
    useEffect(() => {
        for (const element of [bodyRef.current, threadRef.current]) {
            if (element !== null) element.scrollTop = element.scrollHeight;
        }
    }, [ticket.id, entries, sending]);

    // Un archivo recién subido está «por revisar» unos segundos y el servidor avisa cuando termina. Si no hay conexión en
    // vivo ese aviso no llega, y se ofrece actualizar a mano en vez de consultar sin parar.
    const reviewing = ticket.thread.some((entry) => entry.kind === 'message' && entry.attachments.some((attachment) => attachment.status === 'pending'));
    const refresh = () => {
        setRefreshing(true);
        router.reload({
            only: ['selected'],
            onFinish: () => {
                setRefreshing(false);
            },
        });
    };

    const customer = ticket.customer.name ?? copy.no_customer;

    return (
        <section aria-labelledby="ticket-subject" className="flex h-full min-h-0 flex-col">
            {/* Desde 1024 px la cabecera queda fija y solo se desplaza la conversación; en pantallas angostas la cabecera no cabe junto al compositor, así que se desplaza con ella. */}
            <div ref={bodyRef} className="flex min-h-0 flex-1 flex-col overflow-y-auto xl:overflow-visible">
                <header className="@container flex shrink-0 flex-col gap-3 border-b border-border bg-surface px-4 py-3">
                    <div className="flex flex-col gap-1">
                        <h2 id="ticket-subject" className="break-words font-display text-lg font-semibold">
                            {ticket.subject}
                        </h2>
                        <p className="text-sm text-text-secondary">
                            <span className="font-medium text-text">{customer}</span>
                            {ticket.customer.email !== null && (
                                <>
                                    {' · '}
                                    <a href={`mailto:${ticket.customer.email}`} className="underline hover:text-text">
                                        {ticket.customer.email}
                                    </a>
                                </>
                            )}
                        </p>
                    </div>

                    <TicketControls
                        key={`${ticket.status}-${ticket.priority}-${String(ticket.assigned_to)}`}
                        ticket={ticket}
                        assignees={assignees}
                        copy={copy}
                        canUpdate={canUpdate}
                    />

                    <TicketTags ticketId={ticket.id} tags={ticket.tags} options={tagOptions} canEdit={canUpdate} copy={copy.tags} />
                </header>

                <div ref={threadRef} className="px-4 py-6 xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                    <div role="log" aria-label={copy.conversation} aria-live="polite" className="mx-auto flex max-w-[44rem] flex-col gap-5">
                        {ticket.thread.map((entry) =>
                            entry.kind === 'note' ? (
                                <NoteBubble key={`note-${String(entry.id)}`} note={entry} copy={copy.note} />
                            ) : (
                                <MessageBubble
                                    key={`message-${String(entry.id)}`}
                                    message={entry}
                                    attachmentsCopy={copy.attachments}
                                    authorLabel={entry.author === 'customer' ? copy.message_author_customer : copy.message_author_agent}
                                />
                            ),
                        )}
                        {outgoing !== null && <OutgoingBubble outgoing={outgoing} copy={copy.composer} />}
                        <SeenBy names={ticket.seen_by} more={ticket.seen_more} copy={copy.seen} />
                        {reviewing && connection !== 'connected' && (
                            <Alert variant="info">
                                <span className="flex flex-wrap items-center gap-3">
                                    {copy.attachments.offline}
                                    <Button variant="outline" loading={refreshing} loadingLabel={copy.attachments.refreshing} onClick={refresh}>
                                        {copy.attachments.refresh}
                                    </Button>
                                </span>
                            </Alert>
                        )}
                    </div>
                </div>
            </div>

            <div className="border-t border-border bg-surface px-4 pt-1">
                <div className="mx-auto max-w-[44rem]">
                    <TypingIndicator people={typingPeople} copy={copy.typing} />
                </div>
            </div>
            <TicketComposer ticketId={ticket.id} status={ticket.status} canWrite={canUpdate} copy={copy.composer} attachmentsCopy={copy.attachments} onOutgoing={setOutgoing} onTyping={sendTyping} />
        </section>
    );
}

interface TicketControlsProps {
    ticket: TicketDetailData;
    assignees: Assignee[];
    copy: TicketsCopy;
    canUpdate: boolean;
}

/**
 * Los tres selectores y los datos del ticket. Cambian el valor en el acto y lo confirman con el servidor; si el
 * servidor lo rechaza, vuelven al valor real. Se vuelve a montar cuando el servidor devuelve valores distintos.
 */
function TicketControls({ ticket, assignees, copy, canUpdate }: TicketControlsProps) {
    const { props } = usePage<SharedProps>();
    const [values, setValues] = useState({ status: ticket.status, priority: ticket.priority, assigned_to: ticket.assigned_to });
    const error = props.errors.status ?? props.errors.priority ?? props.errors.assigned_to;

    const save = (changes: Partial<typeof values>) => {
        setValues((current) => ({ ...current, ...changes }));
        router.patch(`/tickets/${String(ticket.id)}`, changes, {
            preserveScroll: true,
            onError: () => {
                setValues({ status: ticket.status, priority: ticket.priority, assigned_to: ticket.assigned_to });
            },
        });
    };

    return (
        <>
            <div className="grid grid-cols-1 gap-3 @lg:grid-cols-3">
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.status_label}
                        visibleLabel
                        disabled={!canUpdate}
                        options={statuses.map((status) => ({ value: status, label: copy.status[status] }))}
                        value={values.status}
                        onChange={(event) => {
                            save({ status: event.target.value as TicketStatus });
                        }}
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.priority_label}
                        visibleLabel
                        disabled={!canUpdate}
                        options={priorities.map((priority) => ({ value: priority, label: copy.priority[priority] }))}
                        value={values.priority}
                        onChange={(event) => {
                            save({ priority: event.target.value as TicketPriority });
                        }}
                    />
                </div>
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.assignee_label}
                        visibleLabel
                        disabled={!canUpdate}
                        options={[
                            { value: '', label: copy.unassigned },
                            ...assignees.map((person) => ({ value: String(person.id), label: person.active ? person.name : copy.assignee_inactive.replace(':name', person.name) })),
                        ]}
                        value={values.assigned_to === null ? '' : String(values.assigned_to)}
                        onChange={(event) => {
                            save({ assigned_to: event.target.value === '' ? null : Number(event.target.value) });
                        }}
                    />
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-tertiary">
                <span className="font-mono">{copy.number.replace(':number', String(ticket.number))}</span>
                <span>
                    {copy.channel_label}: {copy.channels[ticket.channel] ?? ticket.channel}
                </span>
                <span>
                    {copy.created_at} <time dateTime={ticket.created_at}>{ticket.created_label}</time>
                </span>
                {/* En solo lectura no hay selectores que anuncien el estado, así que se muestra como insignias. */}
                {!canUpdate && (
                    <>
                        <StatusBadge status={ticket.status} label={copy.status[ticket.status]} />
                        <PriorityBadge priority={ticket.priority} label={copy.priority[ticket.priority]} />
                    </>
                )}
            </div>

            {error !== undefined && <Alert variant="danger">{error}</Alert>}
        </>
    );
}
