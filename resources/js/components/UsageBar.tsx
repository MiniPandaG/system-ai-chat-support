interface UsageBarProps {
    /** Nombre accesible de la barra, con el uso en texto. */
    label: string;
    used: number;
    max: number;
    /** El uso supera el tope: pasa a aviso fuerte, con texto, no solo con color. */
    over: boolean;
}

/**
 * Barra de uso de un límite. Llena al 80 % o más cambia a advertencia y por encima del tope a peligro; el estado
 * siempre lo dice también el texto de al lado, nunca solo el color.
 */
export function UsageBar({ label, used, max, over }: UsageBarProps) {
    const percent = max === 0 ? 100 : Math.min(100, Math.round((used / max) * 100));
    const tone = over ? 'bg-danger' : percent >= 80 ? 'bg-warning' : 'bg-primary';

    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={max}
            aria-valuenow={Math.min(used, max)}
            className="h-2 overflow-hidden rounded-full bg-border"
        >
            <div className={`h-full transition-[width] duration-base ease-standard ${tone}`} style={{ width: `${String(percent)}%` }} />
        </div>
    );
}
