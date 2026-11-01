import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { addFiles } from '../lib/attachments';
import type { AttachmentsCopy, ComposerCopy, TicketStatus } from '../ticketTypes';
import { Alert } from './Alert';
import { AttachmentPicker } from './AttachmentPicker';
import { Button } from './Button';
import { Radio } from './Radio';
import { TextAreaField } from './TextAreaField';

interface TicketComposerProps {
    ticketId: number;
    status: TicketStatus;
    /** Quien atiende puede escribir; un observador solo lee. */
    canWrite: boolean;
    copy: ComposerCopy;
    attachmentsCopy: AttachmentsCopy;
}

type Mode = 'reply' | 'note';

const MAX_LENGTH = 10000;

/**
 * Compositor del ticket (design/patrones/conversacion.md): un control explícito elige entre responder al cliente y
 * dejar una nota interna, y el color del compositor cambia por completo para que nadie escriba una nota creyendo que
 * es pública. Cada modo guarda su propio borrador: lo escrito como nota nunca pasa a una respuesta al cambiar de modo.
 * Un ticket cerrado muestra un aviso con «Reabrir» en vez de un campo deshabilitado.
 */
export function TicketComposer({ ticketId, status, canWrite, copy, attachmentsCopy }: TicketComposerProps) {
    const [mode, setMode] = useState<Mode>('reply');
    const [drafts, setDrafts] = useState<Record<Mode, string>>({ reply: '', note: '' });
    const [files, setFiles] = useState<File[]>([]);
    const [dragging, setDragging] = useState(false);
    const [reopening, setReopening] = useState(false);
    const form = useForm({ body: '' });

    if (!canWrite) {
        return (
            <div className="border-t border-border bg-surface px-4 py-3">
                <p className="mx-auto max-w-[44rem] text-sm text-text-secondary">{copy.read_only}</p>
            </div>
        );
    }

    if (status === 'closed') {
        const reopen = () => {
            setReopening(true);
            router.patch(
                `/tickets/${String(ticketId)}`,
                { status: 'open' },
                {
                    preserveScroll: true,
                    onFinish: () => {
                        setReopening(false);
                    },
                },
            );
        };

        return (
            <div className="border-t border-border bg-surface px-4 py-3">
                <div className="mx-auto flex max-w-[44rem] flex-col gap-3">
                    <Alert variant="info">{copy.closed}</Alert>
                    <div>
                        <Button variant="outline" loading={reopening} loadingLabel={copy.sending} onClick={reopen}>
                            {copy.reopen}
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    const isNote = mode === 'note';
    const body = drafts[mode];
    // Los archivos solo se adjuntan a una respuesta: una nota es del equipo y no lleva adjuntos.
    const attached = isNote ? [] : files;
    const fileError = Object.entries(form.errors).find(([key]) => key === 'files' || key.startsWith('files.'))?.[1];
    const empty = body.trim() === '' && attached.length === 0;

    const change = (next: Mode) => {
        setMode(next);
        form.clearErrors();
    };

    const send = () => {
        if (empty || form.processing) return;

        form.transform(() => (attached.length > 0 ? { body, files: attached } : { body }));
        form.post(`/tickets/${String(ticketId)}/${isNote ? 'notes' : 'messages'}`, {
            preserveScroll: true,
            onSuccess: () => {
                setDrafts((current) => ({ ...current, [mode]: '' }));
                setFiles([]);
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
            event.preventDefault();
            send();
        }
    };

    return (
        <form
            aria-label={copy.label}
            onSubmit={(event) => {
                event.preventDefault();
                send();
            }}
            // Arrastrar archivos sobre el compositor es un atajo: el botón «Adjuntar archivos» siempre existe.
            onDragOver={(event) => {
                if (isNote || !event.dataTransfer.types.includes('Files')) return;

                event.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => {
                setDragging(false);
            }}
            onDrop={(event) => {
                setDragging(false);
                if (isNote || event.dataTransfer.files.length === 0) return;

                event.preventDefault();
                setFiles(addFiles(files, Array.from(event.dataTransfer.files), attachmentsCopy).files);
            }}
            className={`border-t px-4 py-3 ${dragging ? 'border-primary bg-primary-surface' : isNote ? 'border-bubble-nota-interna-borde bg-bubble-nota-interna' : 'border-border bg-surface'}`}
        >
            <div className="mx-auto flex max-w-[44rem] flex-col gap-2">
                <fieldset className="flex flex-wrap gap-x-6">
                    <legend className="sr-only">{copy.mode_label}</legend>
                    <Radio
                        name={`mode-${String(ticketId)}`}
                        label={copy.mode_reply}
                        checked={!isNote}
                        onChange={() => {
                            change('reply');
                        }}
                    />
                    <Radio
                        name={`mode-${String(ticketId)}`}
                        label={copy.mode_note}
                        checked={isNote}
                        onChange={() => {
                            change('note');
                        }}
                    />
                </fieldset>
                <p role="status" className="sr-only">
                    {isNote ? copy.note_announce : copy.reply_announce}
                </p>
                <TextAreaField
                    label={isNote ? copy.note_field : copy.reply_field}
                    placeholder={isNote ? copy.note_placeholder : copy.reply_placeholder}
                    help={isNote ? copy.note_hint : copy.reply_hint}
                    error={form.errors.body}
                    value={body}
                    maxLength={MAX_LENGTH}
                    onKeyDown={onKeyDown}
                    onChange={(event) => {
                        const value = event.target.value;
                        setDrafts((current) => ({ ...current, [mode]: value }));
                    }}
                />
                {!isNote && (
                    <AttachmentPicker
                        files={files}
                        copy={attachmentsCopy}
                        serverError={fileError}
                        progress={form.processing && attached.length > 0 ? Math.round(form.progress?.percentage ?? 0) : null}
                        disabled={form.processing}
                        onChange={setFiles}
                    />
                )}
                {dragging && <p className="text-sm font-medium text-primary">{attachmentsCopy.drop}</p>}
                <div className="flex justify-end">
                    <Button type="submit" disabled={empty} loading={form.processing} loadingLabel={copy.sending}>
                        {isNote ? copy.send_note : copy.send_reply}
                    </Button>
                </div>
            </div>
        </form>
    );
}
