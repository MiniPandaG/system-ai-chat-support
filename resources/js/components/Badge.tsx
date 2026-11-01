import type { ReactNode } from 'react';
import { XIcon } from './icons';

type Variant = 'success' | 'neutral' | 'primary' | 'warning' | 'danger' | 'info';

interface BadgeProps {
    variant: Variant;
    children: ReactNode;
    /** Pastilla para estados del sistema; esquina corta para etiquetas que escribe la empresa (design/componentes/insignia.md). */
    shape?: 'pill' | 'tag';
    /** Con esto la insignia lleva una «x» para quitarla; `removeLabel` es su nombre accesible. */
    onRemove?: () => void;
    removeLabel?: string;
}

const variantClasses: Record<Variant, string> = {
    success: 'bg-success-surface text-success border-success-border',
    neutral: 'bg-surface-raised text-text-secondary border-border',
    primary: 'bg-primary-surface text-primary border-primary-border',
    warning: 'bg-warning-surface text-warning border-warning-border',
    danger: 'bg-danger-surface text-danger border-danger-border',
    info: 'bg-info-surface text-info border-info-border',
};

/** Estado de un vistazo (design/componentes/insignia.md): siempre lleva texto, nunca solo color. */
export function Badge({ variant, children, shape = 'pill', onRemove, removeLabel = '' }: BadgeProps) {
    const radius = shape === 'pill' ? 'rounded-full' : 'rounded-sm';

    return (
        <span className={`inline-flex h-[22px] items-center gap-1 border px-2 text-xs font-medium ${radius} ${variantClasses[variant]}`}>
            {children}
            {onRemove !== undefined && (
                <button
                    type="button"
                    aria-label={removeLabel}
                    onClick={onRemove}
                    className="-mr-1 flex size-5 items-center justify-center rounded-sm hover:bg-surface-hover"
                >
                    <XIcon className="size-3" />
                </button>
            )}
        </span>
    );
}
