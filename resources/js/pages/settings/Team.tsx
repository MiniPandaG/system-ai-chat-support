import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { InviteDialog } from '../../components/InviteDialog';
import type { InviteCopy } from '../../components/InviteDialog';
import { SelectField } from '../../components/SelectField';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { SharedProps } from '../../types';

interface Member {
    id: number;
    name: string;
    email: string;
    role: string | null;
    active: boolean;
    is_self: boolean;
    since: string | null;
    since_label: string | null;
}

interface PendingInvitation {
    id: number;
    email: string;
    role: string;
    expired: boolean;
    expires_label: string;
}

interface TeamCopy extends InviteCopy {
    title: string;
    detail: string;
    table_label: string;
    name: string;
    email: string;
    role: string;
    status: string;
    since: string;
    actions: string;
    you: string;
    active: string;
    inactive: string;
    roles: Record<string, string>;
    role_help: Record<string, string>;
    change_role: string;
    promote_title: string;
    promote_detail: string;
    promote_accept: string;
    promote_accepting: string;
    cancel: string;
    deactivate: string;
    deactivate_short: string;
    deactivating: string;
    deactivate_title: string;
    deactivate_detail: string;
    deactivate_accept: string;
    activate: string;
    activate_short: string;
    activating: string;
    empty: string;
    invite: string;
    pending: string;
    expired: string;
    expires: string;
    pending_name: string;
    resend: string;
    resend_label: string;
    revoke: string;
    revoke_label: string;
    seats: string;
    seats_unlimited: string;
    seats_full: string;
    seats_over: string;
}

interface Seats {
    plan: string;
    used: number;
    max: number | null;
    remaining: number | null;
    over: boolean;
}

type Pending = { kind: 'promote' | 'deactivate'; member: Member } | null;

const roleOrder = ['admin', 'agent', 'observer'];

export default function Team({ copy, members, invitations, seats }: { copy: TeamCopy; members: Member[]; invitations: PendingInvitation[]; seats: Seats | null }) {
    const { props } = usePage<SharedProps>();
    const [pending, setPending] = useState<Pending>(null);
    const [busy, setBusy] = useState(false);
    const [inviting, setInviting] = useState(false);

    const finish = () => {
        setBusy(false);
        setPending(null);
    };

    const send = (method: 'put' | 'post', url: string, data?: Record<string, string>) => {
        setBusy(true);
        router[method](url, data ?? {}, { preserveScroll: true, onFinish: finish });
    };

    const changeRole = (member: Member, role: string) => {
        if (role === member.role) return;
        if (role === 'admin') {
            setPending({ kind: 'promote', member });

            return;
        }

        send('put', `/settings/team/members/${String(member.id)}/role`, { role });
    };

    const confirm = () => {
        if (pending === null) return;
        const base = `/settings/team/members/${String(pending.member.id)}`;

        if (pending.kind === 'promote') send('put', `${base}/role`, { role: 'admin' });
        else send('post', `${base}/deactivate`);
    };

    const roleOptions = roleOrder.map((role) => ({ value: role, label: copy.roles[role] ?? role }));
    const errors = Object.values(props.errors);
    const seatsMax = seats?.max ?? null;

    return (
        <PanelLayout title={copy.title} section="team" wide>
            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}
            {errors.length > 0 && <Alert variant="danger">{errors.join(' ')}</Alert>}
            {seats !== null && seatsMax !== null && seats.over && (
                <Alert variant="warning">{copy.seats_over.replace(':used', String(seats.used)).replace(':plan', seats.plan).replace(':max', String(seatsMax))}</Alert>
            )}
            {seats !== null && seatsMax !== null && !seats.over && seats.remaining === 0 && (
                <Alert variant="info">{copy.seats_full.replace(':plan', seats.plan).replace(':max', String(seatsMax))}</Alert>
            )}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-text-secondary">{copy.detail}</p>
                <Button
                    onClick={() => {
                        setInviting(true);
                    }}
                >
                    {copy.invite}
                </Button>
            </div>

            <div className="overflow-x-auto rounded-md border border-border bg-surface">
                <table aria-label={copy.table_label} className="w-full text-left text-base">
                    <thead className="border-b border-border text-sm text-text-secondary max-md:sr-only">
                        <tr>
                            {[copy.name, copy.email, copy.role, copy.status, copy.since, copy.actions].map((heading) => (
                                <th key={heading} scope="col" className="px-4 py-3 font-semibold">
                                    {heading}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {members.map((member) => (
                            <tr key={member.id} className="hover:bg-surface-hover max-md:flex max-md:flex-col max-md:gap-2 max-md:p-4">
                                <td data-label={copy.name} className="px-4 py-3 font-medium max-md:flex max-md:justify-between max-md:p-0">
                                    <span className="flex flex-wrap items-center gap-2">
                                        {member.name}
                                        {member.is_self && <Badge variant="primary">{copy.you}</Badge>}
                                    </span>
                                </td>
                                <td data-label={copy.email} className="px-4 py-3 text-text-secondary max-md:flex max-md:justify-between max-md:p-0">
                                    {member.email}
                                </td>
                                <td data-label={copy.role} className="px-4 py-3 max-md:flex max-md:items-center max-md:justify-between max-md:p-0">
                                    {member.is_self || member.role === null ? (
                                        <span>{member.role === null ? '' : (copy.roles[member.role] ?? member.role)}</span>
                                    ) : (
                                        <SelectField
                                            label={copy.change_role.replace(':name', member.name)}
                                            options={roleOptions}
                                            value={member.role}
                                            disabled={busy || !member.active}
                                            onChange={(event) => {
                                                changeRole(member, event.target.value);
                                            }}
                                        />
                                    )}
                                </td>
                                <td data-label={copy.status} className="px-4 py-3 max-md:flex max-md:justify-between max-md:p-0">
                                    <Badge variant={member.active ? 'success' : 'neutral'}>{member.active ? copy.active : copy.inactive}</Badge>
                                </td>
                                <td data-label={copy.since} className="px-4 py-3 text-text-secondary max-md:flex max-md:justify-between max-md:p-0">
                                    {member.since !== null && <time dateTime={member.since}>{member.since_label}</time>}
                                </td>
                                <td data-label={copy.actions} className="px-4 py-3 max-md:p-0">
                                    {!member.is_self &&
                                        (member.active ? (
                                            <Button
                                                variant="outline"
                                                disabled={busy}
                                                aria-label={copy.deactivate.replace(':name', member.name)}
                                                onClick={() => {
                                                    setPending({ kind: 'deactivate', member });
                                                }}
                                            >
                                                {copy.deactivate_short}
                                            </Button>
                                        ) : (
                                            <Button
                                                variant="outline"
                                                disabled={busy}
                                                aria-label={copy.activate.replace(':name', member.name)}
                                                onClick={() => {
                                                    send('post', `/settings/team/members/${String(member.id)}/activate`);
                                                }}
                                            >
                                                {copy.activate_short}
                                            </Button>
                                        ))}
                                </td>
                            </tr>
                        ))}
                        {invitations.map((invitation) => (
                            <tr key={`invitation-${String(invitation.id)}`} className="hover:bg-surface-hover max-md:flex max-md:flex-col max-md:gap-2 max-md:p-4">
                                <td data-label={copy.name} className="px-4 py-3 text-text-tertiary max-md:flex max-md:justify-between max-md:p-0">
                                    {copy.pending_name}
                                </td>
                                <td data-label={copy.email} className="px-4 py-3 text-text-secondary max-md:flex max-md:justify-between max-md:p-0">
                                    {invitation.email}
                                </td>
                                <td data-label={copy.role} className="px-4 py-3 max-md:flex max-md:justify-between max-md:p-0">
                                    {copy.roles[invitation.role] ?? invitation.role}
                                </td>
                                <td data-label={copy.status} className="px-4 py-3 max-md:flex max-md:justify-between max-md:p-0">
                                    <Badge variant="neutral">{invitation.expired ? copy.expired : copy.pending}</Badge>
                                </td>
                                <td data-label={copy.since} className="px-4 py-3 text-text-secondary max-md:flex max-md:justify-between max-md:p-0">
                                    {copy.expires.replace(':when', invitation.expires_label)}
                                </td>
                                <td data-label={copy.actions} className="flex gap-2 px-4 py-3 max-md:p-0">
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        aria-label={copy.resend_label.replace(':email', invitation.email)}
                                        onClick={() => {
                                            send('post', `/settings/team/invitations/${String(invitation.id)}/resend`);
                                        }}
                                    >
                                        {copy.resend}
                                    </Button>
                                    <Button
                                        variant="text"
                                        disabled={busy}
                                        aria-label={copy.revoke_label.replace(':email', invitation.email)}
                                        onClick={() => {
                                            send('post', `/settings/team/invitations/${String(invitation.id)}/revoke`);
                                        }}
                                    >
                                        {copy.revoke}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {members.length <= 1 && invitations.length === 0 && <p className="text-sm text-text-secondary">{copy.empty}</p>}

            {seats !== null && (
                <p className="text-sm text-text-secondary">
                    {seats.max === null
                        ? copy.seats_unlimited.replace(':used', String(seats.used)).replace(':plan', seats.plan)
                        : copy.seats.replace(':used', String(seats.used)).replace(':max', String(seats.max)).replace(':plan', seats.plan)}
                </p>
            )}

            <ul className="flex flex-col gap-1 text-sm text-text-secondary">
                {roleOrder.map((role) => (
                    <li key={role}>
                        <span className="font-medium text-text">{copy.roles[role]}:</span> {copy.role_help[role]}
                    </li>
                ))}
            </ul>

            <InviteDialog
                open={inviting}
                copy={copy}
                onClose={() => {
                    setInviting(false);
                }}
            />

            <ConfirmDialog
                open={pending !== null}
                title={
                    pending === null
                        ? ''
                        : (pending.kind === 'promote' ? copy.promote_title : copy.deactivate_title).replace(':name', pending.member.name)
                }
                detail={pending?.kind === 'promote' ? copy.promote_detail : copy.deactivate_detail}
                cancelLabel={copy.cancel}
                confirmLabel={pending?.kind === 'promote' ? copy.promote_accept : copy.deactivate_accept}
                confirmingLabel={pending?.kind === 'promote' ? copy.promote_accepting : copy.deactivating}
                confirmVariant={pending?.kind === 'promote' ? 'primary' : 'danger'}
                loading={busy}
                onCancel={() => {
                    setPending(null);
                }}
                onConfirm={confirm}
            />
        </PanelLayout>
    );
}
