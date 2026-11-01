import { joinNames } from '../lib/typing';
import type { TicketsCopy } from '../ticketTypes';

interface SeenByProps {
    names: string[];
    more: number;
    copy: TicketsCopy['seen'];
}

/** «Visto por Ana y Luis»: los compañeros que ya vieron lo último de la conversación. No aparece si nadie lo vio. */
export function SeenBy({ names, more, copy }: SeenByProps) {
    if (names.length === 0) return null;

    const text = (more > 0 ? copy.more.replace(':count', String(more)) : copy.label).replace(':names', joinNames(names));

    return <p className="text-right text-xs text-text-tertiary">{text}</p>;
}
