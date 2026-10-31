import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from './Button';
import { ConfirmDialog } from './ConfirmDialog';

export interface SessionItem {
    handle: string;
    device: string;
    ip: string | null;
    last_activity: string;
    last_activity_label: string;
    current: boolean;
}

export interface SessionsCopy {
    title: string;
    detail: string;
    current: string;
    ip: string;
    last_activity: string;
    close: string;
    closing: string;
    close_others: string;
    closing_others: string;
    close_all: string;
    closing_all: string;
    only_this: string;
    confirm_title: string;
    confirm_detail: string;
    confirm_cancel: string;
    confirm_accept: string;
}

type Pending = { kind: 'one'; handle: string } | { kind: 'others' } | { kind: 'all' } | null;

export function SessionsList({ copy, sessions }: { copy: SessionsCopy; sessions: SessionItem[] }) {
    const [pending, setPending] = useState<Pending>(null);
    const [confirming, setConfirming] = useState(false);
    const others = sessions.filter((session) => !session.current);

    const run = (action: NonNullable<Pending>, url: string) => {
        setPending(action);
        router.delete(url, {
            preserveScroll: true,
            onFinish: () => {
                setPending(null);
                setConfirming(false);
            },
        });
    };

    return (
        <section aria-labelledby="sesiones" className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <h2 id="sesiones" className="font-display text-xl font-semibold">
                    {copy.title}
                </h2>
                <p className="text-text-secondary">{copy.detail}</p>
            </div>

            <ul className="divide-y divide-border overflow-hidden rounded-md border border-border bg-surface">
                {sessions.map((session) => (
                    <li key={session.handle} aria-current={session.current ? 'true' : undefined} className="flex flex-col gap-3 p-4">
                        <div className="flex flex-col gap-1">
                            <p className="flex flex-wrap items-center gap-2 font-medium">
                                {session.device}
                                {session.current && (
                                    <span className="rounded-sm bg-primary-surface px-2 py-0.5 text-sm font-medium">{copy.current}</span>
                                )}
                            </p>
                            <p className="text-sm text-text-secondary">
                                {session.ip !== null && <>{copy.ip.replace(':ip', session.ip)} · </>}
                                <time dateTime={session.last_activity}>{copy.last_activity.replace(':when', session.last_activity_label)}</time>
                            </p>
                        </div>
                        {!session.current && (
                            <Button
                                variant="outline"
                                className="self-start"
                                aria-label={copy.close.replace(':device', session.device)}
                                loading={pending?.kind === 'one' && pending.handle === session.handle}
                                loadingLabel={copy.closing}
                                disabled={pending !== null}
                                onClick={() => {
                                    run({ kind: 'one', handle: session.handle }, `/settings/sessions/${session.handle}`);
                                }}
                            >
                                {copy.close.replace(':device', session.device)}
                            </Button>
                        )}
                    </li>
                ))}
            </ul>

            {others.length === 0 && <p className="text-sm text-text-secondary">{copy.only_this}</p>}

            <div className="flex flex-col gap-3">
                {others.length > 0 && (
                    <Button
                        variant="outline"
                        size="lg"
                        fullWidth
                        loading={pending?.kind === 'others'}
                        loadingLabel={copy.closing_others}
                        disabled={pending !== null}
                        onClick={() => {
                            run({ kind: 'others' }, '/settings/sessions');
                        }}
                    >
                        {copy.close_others}
                    </Button>
                )}
                <Button
                    variant="danger"
                    size="lg"
                    fullWidth
                    disabled={pending !== null}
                    onClick={() => {
                        setConfirming(true);
                    }}
                >
                    {copy.close_all}
                </Button>
            </div>

            <ConfirmDialog
                open={confirming}
                title={copy.confirm_title}
                detail={copy.confirm_detail}
                cancelLabel={copy.confirm_cancel}
                confirmLabel={copy.confirm_accept}
                confirmingLabel={copy.closing_all}
                loading={pending?.kind === 'all'}
                onCancel={() => {
                    setConfirming(false);
                }}
                onConfirm={() => {
                    run({ kind: 'all' }, '/settings/sessions/all');
                }}
            />
        </section>
    );
}
