const formatter = new Intl.RelativeTimeFormat('es', { numeric: 'auto', style: 'short' });

const steps: { unit: Intl.RelativeTimeFormatUnit; seconds: number }[] = [
    { unit: 'day', seconds: 86400 },
    { unit: 'hour', seconds: 3600 },
    { unit: 'minute', seconds: 60 },
];

/** «hace 12 min», «ayer»: la hora relativa de la bandeja. Pasados 7 días devuelve null para que se muestre la fecha. */
export function relativeTime(iso: string, now: Date = new Date()): string | null {
    const elapsed = Math.round((now.getTime() - new Date(iso).getTime()) / 1000);

    if (Number.isNaN(elapsed)) return null;
    if (elapsed < 60) return 'ahora';
    if (elapsed >= 7 * 86400) return null;

    for (const { unit, seconds } of steps) {
        if (elapsed >= seconds) return formatter.format(-Math.floor(elapsed / seconds), unit);
    }

    return null;
}
