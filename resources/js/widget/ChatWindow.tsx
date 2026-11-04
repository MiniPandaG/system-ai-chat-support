import { useEffect, useRef, useState } from 'react';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { XIcon } from '../components/icons';
import { ContactPrompt } from './ContactPrompt';
import { SurveyPrompt } from './SurveyPrompt';
import type { Chat } from './useChat';
import { visibleMessages } from './useChat';
import type { WidgetConfig } from './types';
import { PendingBubble, WidgetBubble } from './WidgetBubble';
import { WidgetComposer } from './WidgetComposer';

interface ChatWindowProps {
    config: WidgetConfig;
    chat: Chat;
    /** Hay una conversación en marcha o el visitante ya pulsó «Iniciar conversación». */
    began: boolean;
    fullscreen: boolean;
    onBegin: () => void;
    onClose: () => void;
}

/**
 * La ventana del chat (design/pantallas/widget-chat.md): cabecera con logo y nombre, aviso de conexión, bienvenida o
 * conversación, y compositor. Al abrirse el foco entra a ella; cerrarla la devuelve al botón (lo hace quien la abre).
 */
export function ChatWindow({ config, chat, began, fullscreen, onBegin, onClose }: ChatWindowProps) {
    const { copy, company, logo } = config;
    const startRef = useRef<HTMLButtonElement>(null);
    const logRef = useRef<HTMLDivElement>(null);
    const [contactDismissed, setContactDismissed] = useState(false);
    const [contactNoticeDone, setContactNoticeDone] = useState(false);
    const [surveyDismissed, setSurveyDismissed] = useState(false);
    const contactNotice = chat.contactSaved && !contactNoticeDone;
    const { confirmed, pending } = visibleMessages(chat);
    const total = confirmed.length + pending.length;
    const ended = chat.conversation?.ticket?.ended === true;
    const offerContact = began && chat.conversation?.contact.has_email === false && !chat.contactSaved && !contactDismissed && confirmed.some((message) => message.author === 'customer');

    // El foco entra a la ventana: al botón de iniciar (con conversación en marcha lo toma el compositor al aparecer).
    useEffect(() => {
        if (!began) startRef.current?.focus();
    }, [began]);

    // Al llegar algo nuevo la conversación baja sola, sin mover el foco de donde esté el visitante.
    useEffect(() => {
        const log = logRef.current;
        if (log !== null) log.scrollTop = log.scrollHeight;
    }, [total]);

    // «Guardamos tu correo» se muestra un momento y se va.
    useEffect(() => {
        if (!chat.contactSaved) return;

        const timer = window.setTimeout(() => {
            setContactNoticeDone(true);
        }, 5000);

        return () => {
            window.clearTimeout(timer);
        };
    }, [chat.contactSaved]);

    return (
        <div className={fullscreen ? 'size-full' : 'size-full p-4'}>
            <section
                role="dialog"
                aria-label={copy.window_label.replace(':company', company)}
                className={`flex size-full flex-col overflow-hidden bg-background text-text shadow-lg ${fullscreen ? '' : 'rounded-lg border border-border'}`}
            >
                <header className="flex items-center gap-3 border-b border-border bg-surface px-4 py-3">
                    {logo !== null && <img src={logo} alt="" className="size-8 shrink-0 rounded-sm object-cover" />}
                    <h2 className="min-w-0 flex-1 truncate font-display text-lg font-semibold text-text">{company}</h2>
                    <button
                        type="button"
                        aria-label={copy.close}
                        onClick={onClose}
                        className="flex size-10 shrink-0 items-center justify-center rounded-md text-text-secondary transition duration-fast ease-standard hover:bg-surface-hover hover:text-text"
                    >
                        <XIcon className="size-5" />
                    </button>
                </header>

                {!chat.online && (
                    <div className="px-3 pt-3">
                        <Alert variant="warning">{copy.offline}</Alert>
                    </div>
                )}

                {!began ? (
                    <div className="flex flex-1 flex-col justify-center gap-4 p-6">
                        <h3 className="font-display text-xl font-semibold text-text">{copy.welcome_title}</h3>
                        <p className="text-base text-text-secondary">{copy.welcome_detail}</p>
                        <Button ref={startRef} onClick={onBegin} size="lg" fullWidth>
                            {copy.welcome_start}
                        </Button>
                    </div>
                ) : (
                    <>
                        <div
                            ref={logRef}
                            role="log"
                            aria-live="polite"
                            aria-relevant="additions"
                            aria-label={copy.thread_label.replace(':company', company)}
                            className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4"
                        >
                            {chat.loading && total === 0 && (
                                <div aria-busy="true" className="flex flex-col gap-3">
                                    <span className="sr-only">{copy.loading}</span>
                                    <div className="h-12 w-3/4 animate-pulse rounded-lg bg-surface-sunken" />
                                    <div className="ml-auto h-12 w-2/3 animate-pulse rounded-lg bg-surface-sunken" />
                                </div>
                            )}
                            {chat.loadFailed && total === 0 && (
                                <Alert variant="danger">
                                    <p>{copy.load_error}</p>
                                    <button type="button" onClick={chat.reload} className="mt-1 font-medium underline underline-offset-2">
                                        {copy.retry}
                                    </button>
                                </Alert>
                            )}
                            {!chat.loading && !chat.loadFailed && total === 0 && <p className="text-base text-text-secondary">{copy.thread_empty}</p>}
                            {confirmed.map((message) => (
                                <WidgetBubble key={message.id} copy={copy} company={company} message={message} />
                            ))}
                            {pending.map((entry) => (
                                <PendingBubble key={entry.clientId} copy={copy} entry={entry} onRetry={chat.retry} />
                            ))}
                            {ended && <Alert variant="info">{copy.ended}</Alert>}
                            {contactNotice && <Alert variant="success">{copy.contact_saved}</Alert>}
                            {ended && chat.conversation?.survey.pending === true && !surveyDismissed && (
                                <SurveyPrompt
                                    copy={copy}
                                    error={chat.surveyError}
                                    onAnswer={chat.answerSurvey}
                                    onDismiss={() => {
                                        setSurveyDismissed(true);
                                    }}
                                />
                            )}
                            {chat.surveyAnswered && <Alert variant="success">{copy.survey_thanks}</Alert>}
                            {offerContact && (
                                <ContactPrompt
                                    copy={copy}
                                    error={chat.contactError}
                                    onSave={chat.saveContact}
                                    onDismiss={() => {
                                        setContactDismissed(true);
                                    }}
                                />
                            )}
                        </div>
                        <WidgetComposer copy={copy} onSend={chat.send} />
                    </>
                )}
            </section>
        </div>
    );
}
