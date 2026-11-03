import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { Checkbox } from '../../components/Checkbox';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { EmptyState } from '../../components/EmptyState';
import { KeyIcon } from '../../components/icons';
import { Alert } from '../../components/Alert';
import { Radio } from '../../components/Radio';
import { SelectField } from '../../components/SelectField';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';

type Permission = 'read' | 'write' | 'admin';

interface KeyRow {
    id: number;
    name: string;
    prefix: string;
    permission: Permission;
    created_at: string;
    last_used_at: string | null;
    expires_at: string | null;
    status: 'active' | 'revoked' | 'expired';
}

interface ApiKeysCopy {
    title: string;
    detail: string;
    docs: string;
    create: string;
    empty_title: string;
    empty_detail: string;
    count: string;
    never_used: string;
    never_expires: string;
    expires: string;
    status_active: string;
    status_revoked: string;
    status_expired: string;
    permission_read: string;
    permission_write: string;
    permission_admin: string;
    form_title: string;
    name_label: string;
    name_help: string;
    permission_label: string;
    permission_read_help: string;
    permission_write_help: string;
    permission_admin_help: string;
    expiration_label: string;
    expiration_never: string;
    expiration_30: string;
    expiration_90: string;
    expiration_365: string;
    submit: string;
    submitting: string;
    cancel: string;
    reveal_title: string;
    reveal_warning: string;
    reveal_label: string;
    copy: string;
    copied: string;
    copy_failed: string;
    reveal_confirm: string;
    reveal_close: string;
    rotate: string;
    rotate_label: string;
    rotate_title: string;
    rotate_detail: string;
    rotate_confirm: string;
    rotating: string;
    revoke: string;
    revoke_label: string;
    revoke_title: string;
    revoke_detail: string;
    revoke_confirm: string;
    revoking: string;
}

interface ApiKeysProps {
    copy: ApiKeysCopy;
    keys: KeyRow[];
    created: { name: string; value: string } | null;
    max: number;
}

const dateFormat = new Intl.DateTimeFormat('es', { dateStyle: 'medium' });
const day = (iso: string): string => dateFormat.format(new Date(iso));

/** «hace 3 días» a partir de una fecha; una sola unidad, la mayor. */
function ago(iso: string): string {
    const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    const units: [string, number][] = [
        ['día', 86400],
        ['hora', 3600],
        ['minuto', 60],
    ];

    for (const [name, size] of units) {
        const value = Math.floor(seconds / size);
        if (value >= 1) return `hace ${String(value)} ${name}${value === 1 ? '' : name === 'día' ? 's' : 's'}`;
    }

    return 'hace un momento';
}

/**
 * Llaves de API de la empresa (design/pantallas/llaves-api.md). La llave completa llega una sola vez, al crearla o
 * rotarla, y se muestra en un diálogo que no se puede cerrar sin confirmar que se copió; la lista solo trae el prefijo.
 */
export default function ApiKeys({ copy, keys, created, max }: ApiKeysProps) {
    const [creating, setCreating] = useState(false);
    const [rotating, setRotating] = useState<KeyRow | null>(null);
    const [revoking, setRevoking] = useState<KeyRow | null>(null);
    const [busy, setBusy] = useState(false);
    const activeCount = keys.filter((key) => key.status === 'active').length;
    const permissionLabel: Record<Permission, string> = { read: copy.permission_read, write: copy.permission_write, admin: copy.permission_admin };
    const statusLabel = { active: copy.status_active, revoked: copy.status_revoked, expired: copy.status_expired };

    const act = (method: 'post' | 'delete', url: string, done: () => void) => {
        setBusy(true);
        router[method](url, {}, {
            preserveScroll: true,
            onFinish: () => {
                setBusy(false);
                done();
            },
        });
    };

    return (
        <PanelLayout title={copy.title} section="api_keys" wide>
            <div className="flex flex-col gap-3">
                <p className="text-text-secondary">{copy.detail}</p>
                <div className="flex flex-wrap items-center gap-4">
                    <Button
                        onClick={() => {
                            setCreating(true);
                        }}
                        disabled={activeCount >= max}
                    >
                        {copy.create}
                    </Button>
                    <Link href="/settings/api-docs" className="text-base text-text underline underline-offset-2 hover:text-primary">
                        {copy.docs}
                    </Link>
                    <span className="text-sm text-text-secondary">{copy.count.replace(':count', String(activeCount)).replace(':max', String(max))}</span>
                </div>
            </div>

            {keys.length === 0 ? (
                <EmptyState icon={<KeyIcon />} title={copy.empty_title} detail={copy.empty_detail} />
            ) : (
                <ul className="flex flex-col gap-3">
                    {keys.map((key) => (
                        <li key={key.id} className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h2 className="font-display text-lg font-semibold">{key.name}</h2>
                                <Badge variant={key.status === 'active' ? 'success' : key.status === 'revoked' ? 'danger' : 'warning'}>{statusLabel[key.status]}</Badge>
                            </div>
                            <p className="break-all font-mono text-base text-text-secondary">{key.prefix}···</p>
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-text-secondary">
                                <Badge variant="neutral">{permissionLabel[key.permission]}</Badge>
                                <span>{day(key.created_at)}</span>
                                <span className={key.last_used_at === null ? 'text-text-tertiary' : ''}>{key.last_used_at === null ? copy.never_used : ago(key.last_used_at)}</span>
                                <span>{key.expires_at === null ? copy.never_expires : copy.expires.replace(':date', day(key.expires_at))}</span>
                            </div>
                            {key.status === 'active' && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        aria-label={copy.rotate_label.replace(':name', key.name)}
                                        onClick={() => {
                                            setRotating(key);
                                        }}
                                    >
                                        {copy.rotate}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        aria-label={copy.revoke_label.replace(':name', key.name)}
                                        onClick={() => {
                                            setRevoking(key);
                                        }}
                                    >
                                        {copy.revoke}
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <CreateDialog
                open={creating}
                copy={copy}
                onClose={() => {
                    setCreating(false);
                }}
            />
            <RevealDialog created={created} copy={copy} />

            <ConfirmDialog
                open={rotating !== null}
                title={copy.rotate_title.replace(':name', rotating?.name ?? '')}
                detail={copy.rotate_detail}
                cancelLabel={copy.cancel}
                confirmLabel={copy.rotate_confirm}
                confirmingLabel={copy.rotating}
                confirmVariant="primary"
                loading={busy}
                onConfirm={() => {
                    if (rotating !== null) act('post', `/settings/api-keys/${String(rotating.id)}/rotate`, () => { setRotating(null); });
                }}
                onCancel={() => {
                    setRotating(null);
                }}
            />
            <ConfirmDialog
                open={revoking !== null}
                title={copy.revoke_title.replace(':name', revoking?.name ?? '')}
                detail={copy.revoke_detail}
                cancelLabel={copy.cancel}
                confirmLabel={copy.revoke_confirm}
                confirmingLabel={copy.revoking}
                loading={busy}
                onConfirm={() => {
                    if (revoking !== null) act('delete', `/settings/api-keys/${String(revoking.id)}`, () => { setRevoking(null); });
                }}
                onCancel={() => {
                    setRevoking(null);
                }}
            />
        </PanelLayout>
    );
}

interface CreateDialogProps {
    open: boolean;
    copy: ApiKeysCopy;
    onClose: () => void;
}

/** Crear una llave: nombre, nivel de acceso y caducidad. */
function CreateDialog({ open, copy, onClose }: CreateDialogProps) {
    const form = useForm({ name: '', permission: 'read', expires_in_days: '' });
    const dialogRef = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) return;

        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const levels: { value: Permission; label: string; help: string }[] = [
        { value: 'read', label: copy.permission_read, help: copy.permission_read_help },
        { value: 'write', label: copy.permission_write, help: copy.permission_write_help },
        { value: 'admin', label: copy.permission_admin, help: copy.permission_admin_help },
    ];

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby="api-key-form-title"
            className="m-auto w-[calc(100%-2rem)] max-w-[34rem] rounded-lg bg-surface-raised p-6 text-text shadow-lg backdrop:bg-[var(--color-overlay-backdrop)]"
            onCancel={(event) => {
                event.preventDefault();
                if (!form.processing) close();
            }}
        >
            <form
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/settings/api-keys', {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            onClose();
                        },
                    });
                }}
            >
                <h2 id="api-key-form-title" className="font-display text-lg font-semibold">
                    {copy.form_title}
                </h2>
                <TextField
                    label={copy.name_label}
                    help={copy.name_help}
                    autoComplete="off"
                    value={form.data.name}
                    error={form.errors.name}
                    onChange={(event) => {
                        form.setData('name', event.target.value);
                    }}
                />
                <fieldset className="flex flex-col gap-1">
                    <legend className="mb-1 text-sm font-medium text-text">{copy.permission_label}</legend>
                    {levels.map((level) => (
                        <div key={level.value}>
                            <Radio
                                name="permission"
                                label={level.label}
                                checked={form.data.permission === level.value}
                                onChange={() => {
                                    form.setData('permission', level.value);
                                }}
                            />
                            <p className="pl-8 text-sm text-text-secondary">{level.help}</p>
                        </div>
                    ))}
                    {form.errors.permission !== undefined && <p className="text-sm text-danger">{form.errors.permission}</p>}
                </fieldset>
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={copy.expiration_label}
                        visibleLabel
                        options={[
                            { value: '', label: copy.expiration_never },
                            { value: '30', label: copy.expiration_30 },
                            { value: '90', label: copy.expiration_90 },
                            { value: '365', label: copy.expiration_365 },
                        ]}
                        value={form.data.expires_in_days}
                        onChange={(event) => {
                            form.setData('expires_in_days', event.target.value);
                        }}
                    />
                    {form.errors.expires_in_days !== undefined && <p className="text-sm text-danger">{form.errors.expires_in_days}</p>}
                </div>
                <div className="flex justify-end gap-3">
                    <Button variant="text" disabled={form.processing} onClick={close}>
                        {copy.cancel}
                    </Button>
                    <Button type="submit" loading={form.processing} loadingLabel={copy.submitting} disabled={form.data.name.trim() === ''}>
                        {copy.submit}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}

interface RevealDialogProps {
    created: { name: string; value: string } | null;
    copy: ApiKeysCopy;
}

/**
 * La llave recién creada, una sola vez. No se cierra con Esc ni hasta marcar «Ya copié la llave»: la advertencia no se
 * descarta sin copiar o confirmar. La llave es texto seleccionable, nunca una imagen.
 */
function RevealDialog({ created, copy }: RevealDialogProps) {
    const [done, setDone] = useState<string | null>(null);
    const [copied, setCopied] = useState<'done' | 'failed' | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const dialogRef = useRef<HTMLDialogElement>(null);
    const open = created !== null && done !== created.value;

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) return;

        if (open && !dialog.open) dialog.showModal();
        if (!open && dialog.open) dialog.close();
    }, [open]);

    const copyKey = () => {
        if (created === null) return;

        navigator.clipboard.writeText(created.value).then(
            () => {
                setCopied('done');
                window.setTimeout(() => {
                    setCopied(null);
                }, 2000);
            },
            () => {
                setCopied('failed');
            },
        );
    };

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby="api-key-reveal-title"
            className="m-auto w-[calc(100%-2rem)] max-w-[40rem] rounded-lg bg-surface-raised p-6 text-text shadow-lg backdrop:bg-[var(--color-overlay-backdrop)]"
            onCancel={(event) => {
                event.preventDefault();
            }}
        >
            <div className="flex flex-col gap-4">
                <h2 id="api-key-reveal-title" className="font-display text-lg font-semibold">
                    {copy.reveal_title.replace(':name', created?.name ?? '')}
                </h2>
                <Alert variant="warning">{copy.reveal_warning}</Alert>
                <pre aria-label={copy.reveal_label} className="overflow-x-auto whitespace-pre-wrap break-all rounded-sm bg-surface-sunken p-3 font-mono text-base text-text">
                    <code>{created?.value ?? ''}</code>
                </pre>
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="outline" onClick={copyKey}>
                        {copied === 'done' ? copy.copied : copy.copy}
                    </Button>
                    <p role="status" className="text-sm text-text-secondary">
                        {copied === 'done' ? copy.copied : copied === 'failed' ? copy.copy_failed : ''}
                    </p>
                </div>
                <Checkbox
                    label={copy.reveal_confirm}
                    checked={confirmed}
                    onChange={(event) => {
                        setConfirmed(event.target.checked);
                    }}
                />
                <div className="flex justify-end">
                    <Button
                        disabled={!confirmed}
                        onClick={() => {
                            setDone(created?.value ?? null);
                            setConfirmed(false);
                            setCopied(null);
                        }}
                    >
                        {copy.reveal_close}
                    </Button>
                </div>
            </div>
        </dialog>
    );
}
