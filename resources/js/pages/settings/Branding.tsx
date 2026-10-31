import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Alert } from '../../components/Alert';
import { BrandPreview } from '../../components/BrandPreview';
import type { PreviewCopy } from '../../components/BrandPreview';
import { Button } from '../../components/Button';
import { ColorField } from '../../components/ColorField';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { FileUpload } from '../../components/FileUpload';
import type { FileUploadCopy } from '../../components/FileUpload';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import { checkPalette, colorKeys, isHex, overrides } from '../../lib/palette';
import type { ColorKey, Palette, PaletteReport } from '../../lib/palette';
import type { SharedProps } from '../../types';

interface BrandingCopy {
    title: string;
    detail: string;
    logo: FileUploadCopy & {
        title: string;
        replace: string;
        remove: string;
        removing: string;
        done: string;
        preview_round: string;
        preview_square: string;
        alt: string;
        confirm_title: string;
        confirm_detail: string;
        confirm_cancel: string;
        confirm_accept: string;
    };
    name: {
        title: string;
        detail: string;
        label: string;
        required_suffix: string;
        required: string;
    };
    logo_required: string;
    colors: {
        title: string;
        detail: string;
        advanced: string;
        picker: string;
        use_suggestion: string;
        format: string;
        checking: string;
        unavailable: string;
        labels: Record<ColorKey, string>;
        help: Record<ColorKey, string>;
    };
    preview: PreviewCopy;
    save: {
        save: string;
        saving: string;
        unsaved: string;
        restore: string;
        restore_title: string;
        restore_detail: string;
        restore_cancel: string;
        restore_accept: string;
        restoring: string;
    };
}

interface BrandingProps {
    copy: BrandingCopy;
    name: string;
    logo: { url: string; width: number; height: number } | null;
    palette: { colors: Palette; defaults: Palette; foregrounds: Record<string, string> };
    limits: { max_bytes: number; accepted: string[] };
}

const mainColors: ColorKey[] = ['primary', 'secondary', 'accent', 'background', 'surface', 'text'];
const statusColors: ColorKey[] = ['success', 'warning', 'danger', 'info'];

export default function Branding({ copy, name, logo, palette, limits }: BrandingProps) {
    const { props } = usePage<SharedProps>();
    const [uploading, setUploading] = useState<{ name: string; percent: number } | null>(null);
    const [confirmRemove, setConfirmRemove] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [confirmRestore, setConfirmRestore] = useState(false);
    const [restoring, setRestoring] = useState(false);
    const cancelUploadRef = useRef<(() => void) | null>(null);

    const [nameValue, setNameValue] = useState(name);
    const [nameError, setNameError] = useState<string | undefined>(undefined);
    const [colors, setColors] = useState<Palette>(palette.colors);
    const [touched, setTouched] = useState<ColorKey[]>([]);
    const [savedColors, setSavedColors] = useState<Palette>(palette.colors);
    const [savedName, setSavedName] = useState(name);
    const [result, setResult] = useState<{ signature: string; report: PaletteReport | null } | null>(null);
    const [lastForegrounds, setLastForegrounds] = useState<Record<string, string>>(palette.foregrounds);
    const [saving, setSaving] = useState(false);

    // Si el servidor devuelve datos distintos (por ejemplo, tras guardar o restaurar), el formulario los adopta.
    if (savedColors !== palette.colors) {
        setSavedColors(palette.colors);
        setColors(palette.colors);
        setLastForegrounds(palette.foregrounds);
    }

    if (savedName !== name) {
        setSavedName(name);
        setNameValue(name);
    }

    const allValid = colorKeys.every((key) => isHex(colors[key]));
    const colorsChanged = JSON.stringify(overrides(colors, palette.defaults)) !== JSON.stringify(overrides(palette.colors, palette.defaults));
    const nameChanged = nameValue.trim() !== name;
    const pending = nameChanged || colorsChanged;
    const active = allValid && colorsChanged;
    const signature = JSON.stringify(overrides(colors, palette.defaults));
    // Un informe solo vale para la paleta con la que se pidió: si cambia, queda pendiente hasta que llegue el nuevo.
    const current = active && result?.signature === signature ? result : null;
    const checking = active && current === null;
    const report = current?.report ?? null;
    const foregrounds = report?.foregrounds ?? lastForegrounds;
    const hasCustomColors = JSON.stringify(overrides(palette.colors, palette.defaults)) !== '{}';

    // La revisión de contraste es del servidor: se consulta con una pequeña espera para no preguntar en cada tecla.
    useEffect(() => {
        if (!active) return;

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            void checkPalette(JSON.parse(signature) as Partial<Palette>, controller.signal).then((answer) => {
                if (controller.signal.aborted) return;
                setResult({ signature, report: answer });
                if (answer !== null) setLastForegrounds(answer.foregrounds);
            });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [active, signature]);

    const upload = (file: File) => {
        setUploading({ name: file.name, percent: 0 });
        router.post(
            '/settings/branding/logo',
            { logo: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onCancelToken: (token) => {
                    cancelUploadRef.current = () => {
                        token.cancel();
                    };
                },
                onProgress: (progress) => {
                    setUploading({ name: file.name, percent: Math.round(progress?.percentage ?? 0) });
                },
                onFinish: () => {
                    setUploading(null);
                    cancelUploadRef.current = null;
                },
            },
        );
    };

    const changeColor = (key: ColorKey, value: string) => {
        setColors((current) => ({ ...current, [key]: value }));
        setTouched((current) => (current.includes(key) ? current : [...current, key]));
    };

    const statusFor = (key: ColorKey) => {
        if (!isHex(colors[key])) return { kind: 'format' as const, message: copy.colors.format };

        const issue = report?.issues.find((candidate) => candidate.key === key);
        if (issue !== undefined) return { kind: 'issue' as const, message: issue.message, suggestion: issue.suggestion?.hex ?? null };

        const serverMessage = props.errors[`colors.${key}`];
        if (serverMessage !== undefined && !colorsChanged) return { kind: 'issue' as const, message: serverMessage, suggestion: null };

        return touched.includes(key) && report?.ok === true ? { kind: 'ok' as const } : null;
    };

    const field = (key: ColorKey) => (
        <ColorField
            key={key}
            label={copy.colors.labels[key]}
            help={copy.colors.help[key]}
            pickerLabel={copy.colors.picker}
            value={colors[key]}
            onChange={(value) => {
                changeColor(key, value);
            }}
            status={statusFor(key)}
            useSuggestion={copy.colors.use_suggestion}
        />
    );

    const canSave = pending && !saving && (!colorsChanged || (allValid && !checking && report?.ok === true));

    const saveColors = () => {
        router.put(
            '/settings/branding/colors',
            { colors: overrides(colors, palette.defaults) },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSaving(false);
                },
            },
        );
    };

    // Una sola acción guarda lo pendiente: primero el nombre y, si todo va bien, los colores.
    const saveAll = () => {
        if (nameChanged && nameValue.trim() === '') {
            setNameError(copy.name.required);

            return;
        }

        setNameError(undefined);
        setSaving(true);

        if (!nameChanged) {
            saveColors();

            return;
        }

        router.put(
            '/settings/branding/name',
            { name: nameValue.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (colorsChanged) saveColors();
                },
                onFinish: () => {
                    if (!colorsChanged) setSaving(false);
                },
                onError: () => {
                    setSaving(false);
                },
            },
        );
    };

    return (
        <PanelLayout title={copy.title} section="branding" wide>
            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}
            <p className="text-text-secondary">{copy.detail}</p>

            <div className="grid gap-8 lg:grid-cols-[26rem_minmax(0,1fr)] lg:items-start">
                <div className="flex flex-col gap-8">
                    <section aria-labelledby="logo" className="flex flex-col gap-4">
                        <h2 id="logo" className="font-display text-xl font-semibold">
                            {copy.logo.title}
                        </h2>

                        {logo !== null && (
                            <div className="flex flex-wrap items-center gap-6 rounded-md border border-border bg-surface p-4">
                                <figure className="flex flex-col items-center gap-2">
                                    <img src={logo.url} alt={copy.logo.alt} className="size-16 rounded-full bg-surface-sunken object-contain" />
                                    <figcaption className="text-sm text-text-secondary">{copy.logo.preview_round}</figcaption>
                                </figure>
                                <figure className="flex flex-col items-center gap-2">
                                    <img src={logo.url} alt="" className="size-16 rounded-sm bg-surface-sunken object-contain" />
                                    <figcaption className="text-sm text-text-secondary">{copy.logo.preview_square}</figcaption>
                                </figure>
                                <Button
                                    variant="outline"
                                    className="ml-auto"
                                    onClick={() => {
                                        setConfirmRemove(true);
                                    }}
                                >
                                    {copy.logo.remove}
                                </Button>
                            </div>
                        )}

                        <FileUpload
                            copy={copy.logo}
                            accept={limits.accepted}
                            maxBytes={limits.max_bytes}
                            uploading={uploading}
                            error={props.errors.logo}
                            onSelect={upload}
                            onCancel={() => {
                                cancelUploadRef.current?.();
                            }}
                        />
                    </section>

                    <section aria-labelledby="nombre" className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <h2 id="nombre" className="font-display text-xl font-semibold">
                                {copy.name.title}
                            </h2>
                            <p className="text-text-secondary">{copy.name.detail}</p>
                        </div>
                        <TextField
                            label={`${copy.name.label} ${copy.name.required_suffix}`}
                            name="name"
                            autoComplete="organization"
                            value={nameValue}
                            error={nameError ?? props.errors.name}
                            onChange={(event) => {
                                setNameValue(event.target.value);
                                setNameError(undefined);
                            }}
                        />
                    </section>

                    <section aria-labelledby="colores" className="flex flex-col gap-4">
                        <div className="flex flex-col gap-2">
                            <h2 id="colores" className="font-display text-xl font-semibold">
                                {copy.colors.title}
                            </h2>
                            <p className="text-text-secondary">{copy.colors.detail}</p>
                        </div>

                        <div className="flex flex-col gap-6">{mainColors.map(field)}</div>

                        <details className="flex flex-col gap-4">
                            <summary className="cursor-pointer text-base font-medium">{copy.colors.advanced}</summary>
                            <div className="mt-4 flex flex-col gap-6">{statusColors.map(field)}</div>
                        </details>

                        {checking && <p className="text-sm text-text-secondary">{copy.colors.checking}</p>}
                        {current !== null && report === null && <Alert variant="warning">{copy.colors.unavailable}</Alert>}

                        <Button
                            variant="text"
                            className="self-start"
                            disabled={!hasCustomColors && !colorsChanged}
                            onClick={() => {
                                setConfirmRestore(true);
                            }}
                        >
                            {copy.save.restore}
                        </Button>
                    </section>

                    <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center gap-3 border-t border-border bg-surface px-4 py-3">
                        <Button loading={saving} loadingLabel={copy.save.saving} disabled={!canSave} onClick={saveAll}>
                            {copy.save.save}
                        </Button>
                        {pending && <p className="text-sm text-warning">{copy.save.unsaved}</p>}
                    </div>
                </div>

                <aside className="lg:sticky lg:top-4">
                    <BrandPreview
                        copy={copy.preview}
                        colors={colors}
                        defaults={palette.defaults}
                        foregrounds={foregrounds}
                        companyName={nameValue.trim() === '' ? name : nameValue.trim()}
                        logoUrl={logo?.url ?? null}
                    />
                </aside>
            </div>

            <ConfirmDialog
                open={confirmRemove}
                title={copy.logo.confirm_title}
                detail={copy.logo.confirm_detail}
                cancelLabel={copy.logo.confirm_cancel}
                confirmLabel={copy.logo.confirm_accept}
                confirmingLabel={copy.logo.removing}
                loading={removing}
                onCancel={() => {
                    setConfirmRemove(false);
                }}
                onConfirm={() => {
                    setRemoving(true);
                    router.delete('/settings/branding/logo', {
                        preserveScroll: true,
                        onFinish: () => {
                            setRemoving(false);
                            setConfirmRemove(false);
                        },
                    });
                }}
            />

            <ConfirmDialog
                open={confirmRestore}
                title={copy.save.restore_title}
                detail={copy.save.restore_detail}
                cancelLabel={copy.save.restore_cancel}
                confirmLabel={copy.save.restore_accept}
                confirmingLabel={copy.save.restoring}
                loading={restoring}
                onCancel={() => {
                    setConfirmRestore(false);
                }}
                onConfirm={() => {
                    setRestoring(true);
                    router.delete('/settings/branding/colors', {
                        preserveScroll: true,
                        onSuccess: () => {
                            setColors(palette.defaults);
                            setTouched([]);
                        },
                        onFinish: () => {
                            setRestoring(false);
                            setConfirmRestore(false);
                        },
                    });
                }}
            />
        </PanelLayout>
    );
}
