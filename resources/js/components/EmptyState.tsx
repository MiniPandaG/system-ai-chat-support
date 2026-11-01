import type { ReactNode } from 'react';

interface EmptyStateProps {
    icon: ReactNode;
    title: string;
    detail?: string;
    children?: ReactNode;
}

/** Estado vacío (design/patrones/vacios.md): qué significa y qué hacer ahora; el icono va en gris, nunca a color. */
export function EmptyState({ icon, title, detail, children }: EmptyStateProps) {
    return (
        <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
            <span className="text-text-tertiary [&>svg]:size-12">{icon}</span>
            <h2 className="font-display text-lg font-semibold">{title}</h2>
            {detail !== undefined && <p className="max-w-[28rem] text-text-secondary">{detail}</p>}
            {children}
        </div>
    );
}
