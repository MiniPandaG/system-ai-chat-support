import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { AiDraftCopy, TicketAiData } from '../ticketTypes';
import type { SharedProps } from '../types';
import { Alert } from './Alert';
import { Badge } from './Badge';
import { Button } from './Button';
import { ConfirmDialog } from './ConfirmDialog';
import { TextAreaField } from './TextAreaField';

interface AiDraftPanelProps {
    ticketId: number;
    ai: TicketAiData;
    copy: AiDraftCopy;
}

const MAX_LENGTH = 10000;

/**
 * El borrador que preparó la IA (HU-08): el cliente todavía no lo ve. Quien responde lo envía tal cual, lo edita o lo
 * descarta; el servidor decide si todavía se puede (alguien pudo enviarlo o descartarlo antes). Cuando no hay borrador
 * solo dice en qué quedó el último intento de la IA, con su motivo, para que el equipo sepa qué pasó.
 */
export function AiDraftPanel({ ticketId, ai, copy }: AiDraftPanelProps) {
    const { props } = usePage<SharedProps>();
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState(ai.body ?? '');
    const [busy, setBusy] = useState<'approve' | 'discard' | null>(null);
    const [confirmingDiscard, setConfirmingDiscard] = useState(false);
    const failure = props.errors.draft ?? props.errors.body;

    if (ai.body === null) {
        return (
            <div className="border-t border-border bg-surface px-4 py-2">
                <p className="mx-auto flex max-w-[44rem] flex-wrap items-center gap-2 text-sm text-text-secondary">
                    <span className="font-medium text-text">{copy.last_run}:</span>
                    <Badge variant="neutral">{ai.status_label}</Badge>
                    <span>{ai.reason_label}</span>
                </p>
            </div>
        );
    }

    const base = `/tickets/${String(ticketId)}/ai/${String(ai.id)}`;

    const approve = (body: string | null) => {
        setBusy('approve');
        router.post(base + '/approve', body === null ? {} : { body }, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(null);
            },
        });
    };

    const discard = () => {
        setBusy('discard');
        router.post(base + '/discard', {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(null);
                setConfirmingDiscard(false);
            },
        });
    };

    const empty = text.trim() === '';

    return (
        <section aria-labelledby="ai-draft-title" className="border-t border-border bg-surface px-4 py-3">
            <div className="mx-auto flex max-w-[44rem] flex-col gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 id="ai-draft-title" className="font-display text-base font-semibold">
                        {copy.title}
                    </h3>
                    <Badge variant="info">{copy.badge}</Badge>
                </div>
                <p className="text-sm text-text-secondary">{copy.hint}</p>

                {failure !== undefined && <Alert variant="warning">{failure}</Alert>}

                {editing ? (
                    <TextAreaField label={copy.edit_label} value={text} maxLength={MAX_LENGTH} rows={5} onChange={(event) => { setText(event.target.value); }} />
                ) : (
                    <div className="whitespace-pre-wrap break-words rounded-md border border-border bg-surface-sunken px-4 py-3 text-base">{ai.body}</div>
                )}

                {!editing && ai.sources.length > 0 && (
                    <p className="text-sm text-text-secondary">
                        {copy.sources}: {ai.sources.map((source) => source.title).join(', ')}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    {editing ? (
                        <>
                            <Button loading={busy === 'approve'} loadingLabel={copy.send_edited} disabled={empty || busy !== null} onClick={() => { approve(text); }}>
                                {copy.send_edited}
                            </Button>
                            <Button
                                variant="text"
                                disabled={busy !== null}
                                onClick={() => {
                                    setEditing(false);
                                    setText(ai.body ?? '');
                                }}
                            >
                                {copy.cancel_edit}
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button loading={busy === 'approve'} loadingLabel={copy.approve} disabled={busy !== null} onClick={() => { approve(null); }}>
                                {copy.approve}
                            </Button>
                            <Button variant="outline" disabled={busy !== null} onClick={() => { setEditing(true); }}>
                                {copy.edit}
                            </Button>
                            <Button variant="text" disabled={busy !== null} onClick={() => { setConfirmingDiscard(true); }}>
                                {copy.discard}
                            </Button>
                        </>
                    )}
                </div>
            </div>

            <ConfirmDialog
                open={confirmingDiscard}
                title={copy.discard_title}
                detail={copy.discard_body}
                cancelLabel={copy.discard_cancel}
                confirmLabel={copy.discard_confirm}
                confirmingLabel={copy.discard_confirm}
                loading={busy === 'discard'}
                onConfirm={discard}
                onCancel={() => { setConfirmingDiscard(false); }}
            />
        </section>
    );
}
