import type { ReactNode } from 'react';
import { AlertTriangleIcon, CheckCircleIcon, InfoIcon } from './icons';

type Variant = 'info' | 'success' | 'warning' | 'danger';

interface AlertProps {
    variant: Variant;
    children: ReactNode;
}

const variantClasses: Record<Variant, string> = {
    info: 'bg-info-surface border-info-border',
    success: 'bg-success-surface border-success-border',
    warning: 'bg-warning-surface border-warning-border',
    danger: 'bg-danger-surface border-danger-border',
};

const iconClasses: Record<Variant, string> = {
    info: 'text-info',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
};

export function Alert({ variant, children }: AlertProps) {
    const Icon = variant === 'success' ? CheckCircleIcon : variant === 'info' ? InfoIcon : AlertTriangleIcon;
    // Advertencia y peligro interrumpen al lector de pantalla; información y éxito solo informan.
    const role = variant === 'warning' || variant === 'danger' ? 'alert' : 'status';

    return (
        <div role={role} className={`flex gap-3 rounded-md border p-4 text-base ${variantClasses[variant]}`}>
            <Icon className={`size-5 shrink-0 ${iconClasses[variant]}`} />
            <div>{children}</div>
        </div>
    );
}
