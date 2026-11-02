import type { ComponentProps } from 'react';

type Variant = 'primary' | 'outline' | 'text' | 'danger';
type Size = 'md' | 'lg';

// ComponentProps incluye `ref`: en React 19 un componente de función lo recibe como una propiedad más.
interface ButtonProps extends ComponentProps<'button'> {
    variant?: Variant;
    size?: Size;
    loading?: boolean;
    /** Texto que anuncian los lectores de pantalla mientras el botón está cargando. */
    loadingLabel?: string;
    fullWidth?: boolean;
}

const variantClasses: Record<Variant, string> = {
    primary:
        'bg-primary text-primary-foreground hover:bg-primary-hover active:bg-primary-active active:scale-98',
    outline: 'border border-border-strong text-text hover:bg-surface-hover active:bg-surface-sunken active:scale-98',
    text: 'text-text-secondary hover:text-text active:scale-98',
    danger: 'bg-danger text-danger-foreground hover:opacity-90 active:scale-98',
};

const sizeClasses: Record<Size, string> = {
    md: 'h-10 px-4 text-base',
    lg: 'h-12 px-6 text-md',
};

export function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    loadingLabel = '',
    fullWidth = false,
    disabled = false,
    type = 'button',
    className = '',
    children,
    ...props
}: ButtonProps) {
    const classes = [
        'relative inline-flex items-center justify-center gap-2 rounded-md font-body font-medium',
        'transition duration-fast ease-standard',
        'disabled:cursor-not-allowed disabled:opacity-45 disabled:active:scale-100',
        variantClasses[variant],
        sizeClasses[size],
        fullWidth ? 'w-full' : '',
        className,
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <button type={type} className={classes} disabled={disabled || loading} aria-busy={loading} {...props}>
            <span className={loading ? 'invisible' : ''}>{children}</span>
            {loading && (
                <>
                    <span
                        className="absolute size-4 animate-spin rounded-full border-2 border-current border-t-transparent"
                        aria-hidden="true"
                    />
                    <span role="status" aria-live="polite" className="sr-only">
                        {loadingLabel}
                    </span>
                </>
            )}
        </button>
    );
}
