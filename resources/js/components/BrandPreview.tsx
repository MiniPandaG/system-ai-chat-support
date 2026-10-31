import { useEffect, useMemo, useRef } from 'react';
import type { ReactNode } from 'react';
import { isHex } from '../lib/palette';
import type { ColorKey, Palette } from '../lib/palette';
import { ChatIcon } from './icons';

export interface PreviewCopy {
    title: string;
    detail: string;
    panel: string;
    widget: string;
    email: string;
    panel_nav: string;
    panel_nav_other: string;
    panel_button: string;
    panel_outline: string;
    panel_badge: string;
    panel_done: string;
    panel_field: string;
    panel_field_value: string;
    widget_status: string;
    widget_agent: string;
    widget_client: string;
    widget_placeholder: string;
    widget_send: string;
    email_greeting: string;
    email_quote: string;
    email_button: string;
    email_alt: string;
    email_footer: string;
    description: string;
}

interface BrandPreviewProps {
    copy: PreviewCopy;
    colors: Palette;
    defaults: Palette;
    foregrounds: Record<string, string>;
    companyName: string;
    logoUrl: string | null;
}

/** Colores base del modo claro del widget y del correo (tokens.css, :root[data-theme='claro']). */
const lightBase = { background: '#FFFFFF', surface: '#F7F4EF', text: '#221B14' };

const withForeground: ColorKey[] = ['primary', 'secondary', 'accent', 'success', 'warning', 'danger', 'info'];

/**
 * Contenedor que redefine variables de color solo para sus hijos. Los tokens calculados (hover, superficies, bordes)
 * se recalculan dentro con brand-scope.css, que repite las fórmulas de tokens.css: así la vista previa usa los tokens
 * reales. Las variables se ponen con setProperty (permitido por la CSP) y solo si son #RRGGBB válidos.
 */
function BrandScope({ variables, mode, className, children }: { variables: Record<string, string>; mode?: 'claro'; className: string; children: ReactNode }) {
    const ref = useRef<HTMLDivElement>(null);
    const signature = JSON.stringify(variables);

    useEffect(() => {
        const element = ref.current;
        if (element === null) return;

        const entries = Object.entries(JSON.parse(signature) as Record<string, string>);
        for (const [name, value] of entries) {
            if (isHex(value)) element.style.setProperty(name, value);
            else element.style.removeProperty(name);
        }

        return () => {
            for (const [name] of entries) element.style.removeProperty(name);
        };
    }, [signature]);

    return (
        <div ref={ref} data-brand-scope="" {...(mode === undefined ? {} : { 'data-mode': mode })} className={className}>
            {children}
        </div>
    );
}

function Logo({ url, name }: { url: string | null; name: string }) {
    return url === null ? (
        <span className="font-display text-base font-bold">{name}</span>
    ) : (
        <img src={url} alt="" className="size-6 rounded-sm object-contain" />
    );
}

/**
 * Cómo se verán los colores en el panel, el widget y el correo. Las tres miniaturas son ilustrativas: no se enfocan ni
 * se leen controles uno a uno (aria-hidden), y un texto oculto describe el conjunto.
 */
export function BrandPreview({ copy, colors, defaults, foregrounds, companyName, logoUrl }: BrandPreviewProps) {
    const panel = useMemo(() => ({ '--color-primary': colors.primary, '--color-primary-foreground': foregrounds.primary ?? '' }), [colors.primary, foregrounds.primary]);

    const widget = useMemo(() => {
        const variables: Record<string, string> = {};

        for (const key of Object.keys(colors) as ColorKey[]) variables[`--color-${key}`] = colors[key];
        for (const key of withForeground) variables[`--color-${key}-foreground`] = foregrounds[key] ?? '';

        // El widget nace en modo claro: lo que la empresa no cambió toma el claro y no el oscuro de fábrica.
        for (const key of ['background', 'surface', 'text'] as const) {
            if (colors[key].toUpperCase() === defaults[key].toUpperCase()) variables[`--color-${key}`] = lightBase[key];
        }

        return variables;
    }, [colors, defaults, foregrounds]);

    const email = useMemo(
        () => ({
            '--color-primary': colors.primary,
            '--color-primary-foreground': foregrounds.primary ?? '',
            '--color-background': lightBase.background,
            '--color-surface': lightBase.surface,
            '--color-text': lightBase.text,
        }),
        [colors.primary, foregrounds.primary],
    );

    return (
        <section aria-labelledby="vista-previa" className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <h2 id="vista-previa" className="font-display text-xl font-semibold">
                    {copy.title}
                </h2>
                <p className="text-text-secondary">{copy.detail}</p>
                <p className="sr-only">{copy.description}</p>
            </div>

            <div aria-hidden="true" className="flex flex-col gap-6">
                <figure className="flex flex-col gap-2">
                    <figcaption className="text-sm font-medium text-text-secondary">{copy.panel}</figcaption>
                    <BrandScope variables={panel} className="flex gap-4 rounded-md border border-border bg-background p-4 text-text">
                        <div className="flex w-40 shrink-0 flex-col gap-1 rounded-md bg-surface py-2">
                            <span className="border-l-2 border-primary bg-primary-surface px-3 py-2 text-sm font-medium">{copy.panel_nav}</span>
                            <span className="border-l-2 border-transparent px-3 py-2 text-sm text-text-secondary">{copy.panel_nav_other}</span>
                        </div>
                        <div className="flex min-w-0 flex-1 flex-col gap-3">
                            <div className="flex flex-wrap gap-2">
                                <span className="inline-flex h-10 items-center rounded-md bg-primary px-4 text-base font-medium text-primary-foreground">{copy.panel_button}</span>
                                <span className="inline-flex h-10 items-center rounded-md border border-border-strong px-4 text-base font-medium">{copy.panel_outline}</span>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <span className="inline-flex h-[22px] items-center rounded-full border border-primary-border bg-primary-surface px-2 text-xs font-medium text-primary">{copy.panel_badge}</span>
                                <span className="inline-flex h-[22px] items-center rounded-full border border-success-border bg-success-surface px-2 text-xs font-medium text-success">{copy.panel_done}</span>
                            </div>
                            <div className="flex flex-col gap-1">
                                <span className="text-sm font-medium">{copy.panel_field}</span>
                                <span className="inline-flex h-10 items-center rounded-sm border-2 border-primary bg-surface px-3 text-base">{copy.panel_field_value}</span>
                            </div>
                        </div>
                    </BrandScope>
                </figure>

                <figure className="flex flex-col gap-2">
                    <figcaption className="text-sm font-medium text-text-secondary">{copy.widget}</figcaption>
                    <BrandScope variables={widget} mode="claro" className="flex items-end gap-4 rounded-md border border-border bg-background p-4 text-text">
                        <div className="flex w-64 flex-col overflow-hidden rounded-lg border border-border bg-background shadow-lg">
                            <div className="flex flex-col gap-0.5 border-b border-border bg-surface px-4 py-3">
                                <span className="flex items-center gap-2">
                                    <Logo url={logoUrl} name={companyName} />
                                    {logoUrl !== null && <span className="font-display text-base font-bold">{companyName}</span>}
                                </span>
                                <span className="text-xs text-text-secondary">{copy.widget_status}</span>
                            </div>
                            <div className="flex flex-col gap-2 p-3">
                                <span className="max-w-[85%] self-start rounded-md bg-surface-raised px-3 py-2 text-sm">{copy.widget_agent}</span>
                                <span className="max-w-[85%] self-end rounded-md bg-primary-surface px-3 py-2 text-sm">{copy.widget_client}</span>
                            </div>
                            <div className="flex items-center gap-2 border-t border-border p-3">
                                <span className="flex h-9 flex-1 items-center rounded-sm bg-surface-sunken px-3 text-sm text-text-tertiary">{copy.widget_placeholder}</span>
                                <span className="inline-flex h-9 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground">{copy.widget_send}</span>
                            </div>
                        </div>
                        <span className="relative inline-flex size-14 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-md">
                            <ChatIcon className="size-6" />
                            <span className="absolute -right-1 -top-1 inline-flex size-5 items-center justify-center rounded-full bg-danger text-xs font-semibold text-danger-foreground">1</span>
                        </span>
                    </BrandScope>
                </figure>

                <figure className="flex flex-col gap-2">
                    <figcaption className="text-sm font-medium text-text-secondary">{copy.email}</figcaption>
                    <BrandScope variables={email} mode="claro" className="overflow-hidden rounded-md border border-border bg-background text-text">
                        <div className="flex h-16 items-center justify-center bg-primary text-primary-foreground">
                            {logoUrl === null ? <span className="font-display text-lg font-bold">{companyName}</span> : <img src={logoUrl} alt="" className="size-8 object-contain" />}
                        </div>
                        <div className="flex flex-col gap-3 p-4">
                            <span className="text-base font-semibold">{copy.email_greeting}</span>
                            <span className="border-l-[3px] border-primary pl-3 text-sm text-text-secondary">{copy.email_quote}</span>
                            <span className="inline-flex h-10 items-center self-start rounded-md bg-primary px-4 text-base font-medium text-primary-foreground">{copy.email_button}</span>
                            <span className="text-sm text-text-secondary">{copy.email_alt}</span>
                        </div>
                        <div className="border-t border-border bg-surface px-4 py-3 text-xs text-text-tertiary">{copy.email_footer.replace(':company', companyName)}</div>
                    </BrandScope>
                </figure>
            </div>
        </section>
    );
}
