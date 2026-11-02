import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { EmptyState } from '../../components/EmptyState';
import { ChatIcon } from '../../components/icons';
import { TextField } from '../../components/TextField';
import { Radio } from '../../components/Radio';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { SharedProps } from '../../types';

interface WidgetCopy {
    title: string;
    detail: string;
    status_label: string;
    status_on: string;
    status_off: string;
    status_off_detail: string;
    install_title: string;
    install_detail: string;
    snippet_label: string;
    copy: string;
    copied: string;
    copy_failed: string;
    origins_title: string;
    origins_detail: string;
    origin_label: string;
    origin_help: string;
    add: string;
    adding: string;
    remove: string;
    remove_label: string;
    removing: string;
    empty_title: string;
    empty_detail: string;
    count: string;
    confirm_title: string;
    confirm_detail: string;
    confirm_last_detail: string;
    confirm_cancel: string;
    confirm_accept: string;
    appearance_title: string;
    appearance_detail: string;
    appearance_mode: string;
    appearance_mode_light: string;
    appearance_mode_dark: string;
    appearance_mode_site: string;
    appearance_mode_site_help: string;
    appearance_corner: string;
    appearance_corner_right: string;
    appearance_corner_left: string;
    appearance_save: string;
    appearance_saving: string;
}

interface Appearance {
    mode: 'claro' | 'oscuro' | 'sitio';
    corner: 'right' | 'left';
}

interface Origin {
    id: number;
    origin: string;
}

interface WidgetProps {
    copy: WidgetCopy;
    snippet: string;
    enabled: boolean;
    origins: Origin[];
    max: number;
    appearance: Appearance;
}

/**
 * El chat web de la empresa: estado, código para incrustar y sitios permitidos. Todo lo valida el servidor; aquí solo se
 * muestran sus respuestas, incluido el motivo exacto cuando una dirección no es admisible.
 */
export default function Widget({ copy, snippet, enabled, origins, max, appearance }: WidgetProps) {
    const { props } = usePage<SharedProps>();
    const form = useForm({ origin: '' });
    const look = useForm<Appearance>({ mode: appearance.mode, corner: appearance.corner });
    const [copied, setCopied] = useState<'done' | 'failed' | null>(null);
    const [removing, setRemoving] = useState<Origin | null>(null);
    const [removeBusy, setRemoveBusy] = useState(false);
    const limitError = props.errors.limit;

    const copySnippet = () => {
        navigator.clipboard.writeText(snippet).then(
            () => {
                setCopied('done');
            },
            () => {
                setCopied('failed');
            },
        );
    };

    const remove = () => {
        if (removing === null) return;

        setRemoveBusy(true);
        router.delete(`/settings/widget/origins/${String(removing.id)}`, {
            preserveScroll: true,
            onFinish: () => {
                setRemoveBusy(false);
                setRemoving(null);
            },
        });
    };

    return (
        <PanelLayout title={copy.title} section="widget">
            <div className="flex flex-col gap-3">
                <p className="text-text-secondary">{copy.detail}</p>
                <p className="flex flex-wrap items-center gap-2 text-text-secondary">
                    {copy.status_label} <Badge variant={enabled ? 'success' : 'neutral'}>{enabled ? copy.status_on : copy.status_off}</Badge>
                </p>
                {!enabled && <Alert variant="info">{copy.status_off_detail}</Alert>}
            </div>

            <section className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4" aria-labelledby="widget-origins">
                <div className="flex flex-col gap-1">
                    <h2 id="widget-origins" className="font-display text-lg font-semibold">
                        {copy.origins_title}
                    </h2>
                    <p className="text-sm text-text-secondary">{copy.origins_detail}</p>
                </div>

                {limitError !== undefined && <Alert variant="warning">{limitError}</Alert>}

                <form
                    noValidate
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/settings/widget/origins', {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                            },
                        });
                    }}
                >
                    <TextField
                        label={copy.origin_label}
                        help={copy.origin_help}
                        type="url"
                        inputMode="url"
                        autoComplete="off"
                        spellCheck={false}
                        value={form.data.origin}
                        error={form.errors.origin}
                        onChange={(event) => {
                            form.setData('origin', event.target.value);
                        }}
                    />
                    <div>
                        <Button type="submit" loading={form.processing} loadingLabel={copy.adding} disabled={form.data.origin.trim() === ''}>
                            {copy.add}
                        </Button>
                    </div>
                </form>

                {origins.length === 0 ? (
                    <EmptyState icon={<ChatIcon />} title={copy.empty_title} detail={copy.empty_detail} />
                ) : (
                    <>
                        <p className="text-sm text-text-secondary">{copy.count.replace(':count', String(origins.length)).replace(':max', String(max))}</p>
                        <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                            {origins.map((item) => (
                                <li key={item.id} className="flex items-center justify-between gap-3 px-4 py-3">
                                    <span className="min-w-0 break-all font-mono text-base">{item.origin}</span>
                                    <Button
                                        variant="outline"
                                        aria-label={copy.remove_label.replace(':origin', item.origin)}
                                        onClick={() => {
                                            setRemoving(item);
                                        }}
                                    >
                                        {copy.remove}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </section>

            <section className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4" aria-labelledby="widget-appearance">
                <div className="flex flex-col gap-1">
                    <h2 id="widget-appearance" className="font-display text-lg font-semibold">
                        {copy.appearance_title}
                    </h2>
                    <p className="text-sm text-text-secondary">{copy.appearance_detail}</p>
                </div>
                <form
                    noValidate
                    className="flex flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        look.put('/settings/widget/appearance', { preserveScroll: true });
                    }}
                >
                    <fieldset className="flex flex-col">
                        <legend className="mb-1 text-sm font-medium text-text">{copy.appearance_mode}</legend>
                        <Radio name="mode" label={copy.appearance_mode_light} checked={look.data.mode === 'claro'} onChange={() => { look.setData('mode', 'claro'); }} />
                        <Radio name="mode" label={copy.appearance_mode_dark} checked={look.data.mode === 'oscuro'} onChange={() => { look.setData('mode', 'oscuro'); }} />
                        <Radio name="mode" label={copy.appearance_mode_site} checked={look.data.mode === 'sitio'} onChange={() => { look.setData('mode', 'sitio'); }} />
                        <p className="text-sm text-text-secondary">{copy.appearance_mode_site_help}</p>
                        {look.errors.mode !== undefined && <p className="text-sm text-danger">{look.errors.mode}</p>}
                    </fieldset>
                    <fieldset className="flex flex-col">
                        <legend className="mb-1 text-sm font-medium text-text">{copy.appearance_corner}</legend>
                        <Radio name="corner" label={copy.appearance_corner_right} checked={look.data.corner === 'right'} onChange={() => { look.setData('corner', 'right'); }} />
                        <Radio name="corner" label={copy.appearance_corner_left} checked={look.data.corner === 'left'} onChange={() => { look.setData('corner', 'left'); }} />
                        {look.errors.corner !== undefined && <p className="text-sm text-danger">{look.errors.corner}</p>}
                    </fieldset>
                    <div>
                        <Button type="submit" loading={look.processing} loadingLabel={copy.appearance_saving} disabled={!look.isDirty}>
                            {copy.appearance_save}
                        </Button>
                    </div>
                </form>
            </section>
            <section className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4" aria-labelledby="widget-install">
                <div className="flex flex-col gap-1">
                    <h2 id="widget-install" className="font-display text-lg font-semibold">
                        {copy.install_title}
                    </h2>
                    <p className="text-sm text-text-secondary">{copy.install_detail}</p>
                </div>
                <pre aria-label={copy.snippet_label} className="overflow-x-auto whitespace-pre-wrap break-all rounded-sm bg-surface-sunken p-3 font-mono text-sm text-text">
                    <code>{snippet}</code>
                </pre>
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="outline" onClick={copySnippet}>
                        {copy.copy}
                    </Button>
                    <p role="status" className="text-sm text-text-secondary">
                        {copied === 'done' ? copy.copied : copied === 'failed' ? copy.copy_failed : ''}
                    </p>
                </div>
            </section>

            <ConfirmDialog
                open={removing !== null}
                title={copy.confirm_title.replace(':origin', removing?.origin ?? '')}
                detail={origins.length === 1 ? copy.confirm_last_detail : copy.confirm_detail}
                cancelLabel={copy.confirm_cancel}
                confirmLabel={copy.confirm_accept}
                confirmingLabel={copy.removing}
                loading={removeBusy}
                onConfirm={remove}
                onCancel={() => {
                    setRemoving(null);
                }}
            />
        </PanelLayout>
    );
}
