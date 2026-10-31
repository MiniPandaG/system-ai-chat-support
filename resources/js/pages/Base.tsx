interface Copy {
    heading: string;
    detail: string;
    palette: {
        title: string;
        detail: string;
        brand: string;
        surfaces: string;
        text: string;
        text_levels: Record<'primary' | 'secondary' | 'tertiary' | 'disabled', string>;
    };
    typography: {
        title: string;
        detail: string;
        display_sample: string;
        body_sample: string;
        mono_sample: string;
        scale: string;
        scale_sample: string;
    };
    shape: {
        title: string;
        detail: string;
        radii: string;
        shadows: string;
    };
}

interface BaseProps {
    copy: Copy;
}

interface Swatch {
    token: string;
    classes: string;
}

const brandSwatches: Swatch[] = [
    { token: 'primary', classes: 'bg-primary text-primary-foreground' },
    { token: 'secondary', classes: 'bg-secondary text-secondary-foreground' },
    { token: 'accent', classes: 'bg-accent text-accent-foreground' },
    { token: 'success', classes: 'bg-success text-success-foreground' },
    { token: 'warning', classes: 'bg-warning text-warning-foreground' },
    { token: 'danger', classes: 'bg-danger text-danger-foreground' },
    { token: 'info', classes: 'bg-info text-info-foreground' },
];

const surfaceSwatches: Swatch[] = [
    { token: 'background', classes: 'bg-background text-text' },
    { token: 'surface', classes: 'bg-surface text-text' },
    { token: 'surface-raised', classes: 'bg-surface-raised text-text' },
    { token: 'surface-sunken', classes: 'bg-surface-sunken text-text' },
    { token: 'primary-surface', classes: 'bg-primary-surface text-text' },
    { token: 'accent-surface', classes: 'bg-accent-surface text-text' },
    { token: 'success-surface', classes: 'bg-success-surface text-text' },
    { token: 'danger-surface', classes: 'bg-danger-surface text-text' },
];

const textLevels = [
    { key: 'primary', classes: 'text-text' },
    { key: 'secondary', classes: 'text-text-secondary' },
    { key: 'tertiary', classes: 'text-text-tertiary' },
    { key: 'disabled', classes: 'text-text-disabled' },
] as const;

const scale = [
    { token: 'text-xs', size: '12/16', classes: 'text-xs' },
    { token: 'text-sm', size: '13/20', classes: 'text-sm' },
    { token: 'text-base', size: '14/22', classes: 'text-base' },
    { token: 'text-md', size: '16/26', classes: 'text-md' },
    { token: 'text-lg', size: '18/28', classes: 'text-lg' },
    { token: 'text-xl', size: '22/28', classes: 'text-xl' },
    { token: 'text-2xl', size: '28/36', classes: 'text-2xl' },
    { token: 'text-3xl', size: '36/44', classes: 'text-3xl' },
];

const radii = [
    { token: 'rounded-sm', classes: 'rounded-sm' },
    { token: 'rounded-md', classes: 'rounded-md' },
    { token: 'rounded-lg', classes: 'rounded-lg' },
    { token: 'rounded-full', classes: 'rounded-full' },
];

const shadows = [
    { token: 'shadow-sm', classes: 'shadow-sm' },
    { token: 'shadow-md', classes: 'shadow-md' },
    { token: 'shadow-lg', classes: 'shadow-lg' },
];

function SwatchGrid({ swatches }: { swatches: Swatch[] }) {
    return (
        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {swatches.map(({ token, classes }) => (
                <li key={token} className="overflow-hidden rounded-md border border-border bg-surface">
                    <div className={`${classes} px-4 py-6 font-display text-xl font-semibold`} aria-hidden="true">
                        Aa
                    </div>
                    <p className="px-4 py-3 font-mono text-xs text-text-secondary">{token}</p>
                </li>
            ))}
        </ul>
    );
}

export default function Base({ copy }: BaseProps) {
    return (
        <main className="flex flex-col gap-12 px-4 py-10 sm:px-8">
            <header className="flex flex-col gap-3">
                <h1 className="font-display text-2xl font-bold sm:text-3xl">{copy.heading}</h1>
                <p className="max-w-prose text-md text-text-secondary">{copy.detail}</p>
            </header>

            <section aria-labelledby="paleta" className="flex flex-col gap-6">
                <div className="flex flex-col gap-2">
                    <h2 id="paleta" className="font-display text-xl font-semibold">
                        {copy.palette.title}
                    </h2>
                    <p className="max-w-prose text-text-secondary">{copy.palette.detail}</p>
                </div>

                <h3 className="font-display text-lg font-medium">{copy.palette.brand}</h3>
                <SwatchGrid swatches={brandSwatches} />

                <h3 className="font-display text-lg font-medium">{copy.palette.surfaces}</h3>
                <SwatchGrid swatches={surfaceSwatches} />

                <h3 className="font-display text-lg font-medium">{copy.palette.text}</h3>
                <ul className="flex flex-col gap-2 rounded-md border border-border bg-surface p-4">
                    {textLevels.map(({ key, classes }) => (
                        <li key={key} className={`${classes} text-md`}>
                            {copy.palette.text_levels[key]}
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="tipografia" className="flex flex-col gap-6">
                <div className="flex flex-col gap-2">
                    <h2 id="tipografia" className="font-display text-xl font-semibold">
                        {copy.typography.title}
                    </h2>
                    <p className="max-w-prose text-text-secondary">{copy.typography.detail}</p>
                </div>

                <div className="flex flex-col gap-4 rounded-md border border-border bg-surface p-6">
                    <p className="font-display text-2xl font-semibold">{copy.typography.display_sample}</p>
                    <p className="max-w-prose font-body text-md">{copy.typography.body_sample}</p>
                    <p className="font-mono text-sm text-accent">{copy.typography.mono_sample}</p>
                </div>

                <h3 className="font-display text-lg font-medium">{copy.typography.scale}</h3>
                <ul className="flex flex-col divide-y divide-border rounded-md border border-border bg-surface">
                    {scale.map(({ token, size, classes }) => (
                        <li
                            key={token}
                            className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-baseline sm:gap-6"
                        >
                            <span className="w-40 shrink-0 font-mono text-xs text-text-tertiary">
                                {token} · {size}
                            </span>
                            <span className={classes}>{copy.typography.scale_sample}</span>
                        </li>
                    ))}
                </ul>
            </section>

            <section aria-labelledby="forma" className="flex flex-col gap-6">
                <div className="flex flex-col gap-2">
                    <h2 id="forma" className="font-display text-xl font-semibold">
                        {copy.shape.title}
                    </h2>
                    <p className="max-w-prose text-text-secondary">{copy.shape.detail}</p>
                </div>

                <h3 className="font-display text-lg font-medium">{copy.shape.radii}</h3>
                <ul className="flex flex-wrap gap-4">
                    {radii.map(({ token, classes }) => (
                        <li key={token} className="flex flex-col items-start gap-2">
                            <div className={`${classes} border border-border-strong bg-surface-raised px-6 py-6`} aria-hidden="true" />
                            <span className="font-mono text-xs text-text-secondary">{token}</span>
                        </li>
                    ))}
                </ul>

                <h3 className="font-display text-lg font-medium">{copy.shape.shadows}</h3>
                <ul className="flex flex-wrap gap-6">
                    {shadows.map(({ token, classes }) => (
                        <li key={token} className="flex flex-col items-start gap-2">
                            <div className={`${classes} rounded-md border border-border bg-surface-raised px-8 py-6`} aria-hidden="true" />
                            <span className="font-mono text-xs text-text-secondary">{token}</span>
                        </li>
                    ))}
                </ul>
            </section>
        </main>
    );
}
