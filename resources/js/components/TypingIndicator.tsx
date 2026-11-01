import type { TicketsCopy } from '../ticketTypes';

interface TypingIndicatorProps {
    /** Quienes escriben ahora; nulo cuando no se conoce el nombre. */
    people: (string | null)[];
    copy: TicketsCopy['typing'];
}

/**
 * «Ana está escribiendo…» sobre el compositor. La región existe siempre, aunque esté vacía, para que el lector de
 * pantalla anuncie el cambio y para que el compositor no salte cuando aparece el texto.
 */
export function TypingIndicator({ people, copy }: TypingIndicatorProps) {
    const names = people.filter((name): name is string => name !== null);
    const unknown = people.length - names.length;

    let text = '';
    if (people.length === 1) {
        text = names[0] === undefined ? copy.someone : copy.one.replace(':name', names[0]);
    } else if (people.length === 2 && unknown === 0) {
        text = copy.two.replace(':first', names[0] ?? '').replace(':second', names[1] ?? '');
    } else if (people.length > 1) {
        text = copy.many;
    }

    return (
        <p role="status" aria-live="polite" className="min-h-5 text-xs text-text-secondary">
            {text}
        </p>
    );
}
