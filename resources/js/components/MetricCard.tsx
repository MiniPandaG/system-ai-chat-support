import type { ReactNode } from 'react';

export interface MetricChange {
    delta: number | null;
    unit: 'percent' | 'points';
    /** Hacia dónde mejora la cifra: en un tiempo, bajar es mejorar. */
    better: 'up' | 'down';
}

export interface ChangeCopy {
    better: string;
    worse: string;
    same: string;
    none: string;
    percent: string;
    points: string;
    pp: string;
}

interface MetricCardProps {
    title: string;
    /** Ya formateado; «—» cuando todavía no hay dato. */
    value: string;
    hint?: string;
    icon?: ReactNode;
    change?: MetricChange | undefined;
    /** Cuando el valor es porcentaje de puntos (por ejemplo el resuelto por la IA), la diferencia se dice en «pp». */
    pointsAsPp?: boolean;
    copy: ChangeCopy;
}

const number = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 1 });

/** El cambio dicho con signo y con palabra, nunca solo con color ni solo con la dirección de una flecha. */
export function describeChange(change: MetricChange, copy: ChangeCopy, pointsAsPp: boolean): { text: string; tone: 'better' | 'worse' | 'same' } | null {
    if (change.delta === null) return null;

    if (change.delta === 0) return { text: copy.same, tone: 'same' };

    const improved = (change.delta > 0 && change.better === 'up') || (change.delta < 0 && change.better === 'down');
    const template = change.unit === 'percent' ? copy.percent : pointsAsPp ? copy.pp : copy.points;
    const text = template.replace(':sign', change.delta > 0 ? '+' : '−').replace(':value', number.format(Math.abs(change.delta)));

    return { text: `${text} · ${improved ? copy.better : copy.worse}`, tone: improved ? 'better' : 'worse' };
}

/**
 * Una métrica (design/componentes/tarjeta.md): el número grande y, debajo, cuánto cambió frente al periodo anterior. El color
 * del cambio sigue su significado (mejora o empeora), y siempre va acompañado del signo y de la palabra.
 */
export function MetricCard({ title, value, hint, icon, change, pointsAsPp = false, copy }: MetricCardProps) {
    const described = change === undefined ? null : describeChange(change, copy, pointsAsPp);
    const tones = { better: 'text-success', worse: 'text-danger', same: 'text-text-secondary' };

    return (
        <section aria-label={title} className="flex flex-col gap-2 rounded-md border border-border bg-surface-raised p-5">
            <h3 className="text-sm font-medium text-text-secondary">{title}</h3>
            <p className="flex items-center gap-2 font-display text-3xl font-semibold text-text">
                {icon}
                <span>{value}</span>
            </p>
            {hint !== undefined && <p className="text-sm text-text-secondary">{hint}</p>}
            {change !== undefined && (
                <p className={`text-sm font-medium ${described === null ? 'text-text-tertiary' : tones[described.tone]}`}>{described === null ? copy.none : described.text}</p>
            )}
        </section>
    );
}
