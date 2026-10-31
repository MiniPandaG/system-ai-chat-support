import type { ReactNode } from 'react';

type Variant = 'success' | 'neutral' | 'primary';

interface BadgeProps {
    variant: Variant;
    children: ReactNode;
}

const variantClasses: Record<Variant, string> = {
    success: 'bg-success-surface text-success border-success-border',
    neutral: 'bg-surface-raised text-text-secondary border-border',
    primary: 'bg-primary-surface text-primary border-primary-border',
};

/** Estado de un vistazo (design/componentes/insignia.md): siempre lleva texto, nunca solo color. */
export function Badge({ variant, children }: BadgeProps) {
    return (
        <span className={`inline-flex h-[22px] items-center rounded-full border px-2 text-xs font-medium ${variantClasses[variant]}`}>
            {children}
        </span>
    );
}
