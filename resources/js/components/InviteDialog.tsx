import { useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Button } from './Button';
import { EmailChips } from './EmailChips';
import { SelectField } from './SelectField';

export interface InviteCopy {
    invite_title: string;
    invite_detail: string;
    invite_emails: string;
    invite_emails_hint: string;
    invite_remove: string;
    invite_chip_invalid: string;
    invite_role: string;
    invite_submit: string;
    invite_submitting: string;
    invite_cancel: string;
    roles: Record<string, string>;
    role_help: Record<string, string>;
}

interface InviteDialogProps {
    open: boolean;
    copy: InviteCopy;
    onClose: () => void;
}

const roleOrder = ['agent', 'observer', 'admin'];

/** Formulario para invitar (design/pantallas/registro-empresa.md): varios correos como insignias y un rol. */
export function InviteDialog({ open, copy, onClose }: InviteDialogProps) {
    const form = useForm<{ emails: string[]; role: string }>({ emails: [], role: 'agent' });
    const dialogRef = useRef<HTMLDialogElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) return;

        if (open && !dialog.open) {
            dialog.showModal();
            inputRef.current?.focus();
        }
        if (!open && dialog.open) dialog.close();
    }, [open]);

    const emailError = Object.entries(form.errors).find(([key]) => key === 'emails' || key.startsWith('emails.'))?.[1];

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby="invite-title"
            className="m-auto w-[calc(100%-2rem)] max-w-[40rem] rounded-lg bg-surface-raised p-6 text-text shadow-lg backdrop:bg-[var(--color-overlay-backdrop)]"
            onCancel={(event) => {
                event.preventDefault();
                if (!form.processing) onClose();
            }}
        >
            <form
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/settings/team/invitations', {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            onClose();
                        },
                    });
                }}
            >
                <h2 id="invite-title" className="font-display text-lg font-semibold">
                    {copy.invite_title}
                </h2>
                <p className="text-text-secondary">{copy.invite_detail}</p>

                <EmailChips
                    label={copy.invite_emails}
                    hint={copy.invite_emails_hint}
                    emails={form.data.emails}
                    onChange={(emails) => {
                        form.setData('emails', emails);
                    }}
                    error={emailError ?? form.errors.role}
                    removeLabel={copy.invite_remove}
                    invalidLabel={copy.invite_chip_invalid}
                    inputRef={inputRef}
                />

                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.invite_role}
                        visibleLabel
                        options={roleOrder.map((role) => ({ value: role, label: copy.roles[role] ?? role }))}
                        value={form.data.role}
                        onChange={(event) => {
                            form.setData('role', event.target.value);
                        }}
                    />
                    <p className="text-sm text-text-secondary">{copy.role_help[form.data.role]}</p>
                </div>

                <div className="flex justify-end gap-3">
                    <Button variant="text" disabled={form.processing} onClick={onClose}>
                        {copy.invite_cancel}
                    </Button>
                    <Button type="submit" loading={form.processing} loadingLabel={copy.invite_submitting} disabled={form.data.emails.length === 0}>
                        {copy.invite_submit}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
