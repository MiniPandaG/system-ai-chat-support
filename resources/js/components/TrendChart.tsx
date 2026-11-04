import { useId, useState } from 'react';
import { Button } from './Button';

export interface TrendPoint {
    date: string;
    /** Nulo cuando ese día no hubo dato (por ejemplo, ningún ticket con respuesta). */
    value: number | null;
}

interface TrendChartProps {
    title: string;
    points: TrendPoint[];
    from: string;
    to: string;
    /** Cómo se escribe cada valor en la tabla y en el nombre accesible del gráfico. */
    format: (value: number) => string;
    labels: { chart: string; table: string; chartLabel: string; colDate: string; colValue: string };
}

const WIDTH = 600;
const HEIGHT = 140;
const PAD = 8;

/** Los tramos de la línea: un hueco (día sin dato) la corta en vez de dibujar una mentira. */
export function segments(points: TrendPoint[]): string[] {
    const max = Math.max(1, ...points.map((point) => point.value ?? 0));
    const step = points.length > 1 ? (WIDTH - PAD * 2) / (points.length - 1) : 0;
    const result: string[] = [];
    let current: string[] = [];

    points.forEach((point, index) => {
        if (point.value === null) {
            if (current.length > 0) result.push(current.join(' '));
            current = [];

            return;
        }

        const x = points.length > 1 ? PAD + step * index : WIDTH / 2;
        const y = HEIGHT - PAD - (point.value / max) * (HEIGHT - PAD * 2);
        current.push(`${x.toFixed(1)},${y.toFixed(1)}`);
    });

    if (current.length > 0) result.push(current.join(' '));

    return result;
}

/**
 * Una línea simple a lo largo del rango (design/pantallas/metricas-csat.md): un solo color, sin adornos. Cada gráfico tiene
 * una tabla equivalente a un botón de distancia, para que la información no dependa de interpretar la línea.
 */
export function TrendChart({ title, points, from, to, format, labels }: TrendChartProps) {
    const id = useId();
    const [asTable, setAsTable] = useState(false);
    const lines = segments(points);
    const known = points.filter((point) => point.value !== null).length;

    return (
        <figure className="flex flex-col gap-3 rounded-md border border-border bg-surface-raised p-5">
            <figcaption className="flex flex-wrap items-center justify-between gap-2">
                <span id={id} className="text-sm font-medium text-text">
                    {title}
                </span>
                <Button
                    variant="text"
                    aria-pressed={asTable}
                    onClick={() => {
                        setAsTable((current) => !current);
                    }}
                >
                    {asTable ? labels.chart : labels.table}
                </Button>
            </figcaption>
            {asTable ? (
                <div className="max-h-72 overflow-y-auto">
                    <table className="w-full text-left text-sm">
                        <caption className="sr-only">{title}</caption>
                        <thead className="sticky top-0 bg-surface text-text-secondary">
                            <tr>
                                <th scope="col" className="px-2 py-2 font-semibold">
                                    {labels.colDate}
                                </th>
                                <th scope="col" className="px-2 py-2 font-semibold">
                                    {labels.colValue}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {points.map((point) => (
                                <tr key={point.date} className="border-t border-border">
                                    <td className="px-2 py-1">{point.date}</td>
                                    <td className="px-2 py-1">{point.value === null ? '—' : format(point.value)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <svg role="img" aria-labelledby={id} viewBox={`0 0 ${String(WIDTH)} ${String(HEIGHT)}`} className="h-36 w-full" preserveAspectRatio="none">
                    <title>{labels.chartLabel.replace(':title', title).replace(':from', from).replace(':to', to)}</title>
                    <line x1={PAD} y1={HEIGHT - PAD} x2={WIDTH - PAD} y2={HEIGHT - PAD} className="stroke-border" strokeWidth={1} />
                    {lines.map((line) => (
                        <polyline key={line} points={line} fill="none" className="stroke-primary" strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
                    ))}
                    {known === 1 && lines[0] !== undefined && <circle cx={lines[0].split(',')[0]} cy={lines[0].split(',')[1]} r={3} className="fill-primary" />}
                </svg>
            )}
        </figure>
    );
}
