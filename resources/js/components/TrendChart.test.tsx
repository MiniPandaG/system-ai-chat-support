import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { segments, TrendChart } from './TrendChart';

const labels = { chart: 'Ver como gráfico', table: 'Ver como tabla', chartLabel: ':title, del :from al :to', colDate: 'Día', colValue: 'Recibidos' };

const points = [
    { date: '2026-11-01', value: 2 },
    { date: '2026-11-02', value: null },
    { date: '2026-11-03', value: 4 },
    { date: '2026-11-04', value: 1 },
];

function row(rows: HTMLElement[], index: number): HTMLElement {
    const found = rows[index];

    if (found === undefined) throw new Error('No hay esa fila.');

    return found;
}

describe('segments', () => {
    it('un día sin dato corta la línea en dos tramos en vez de unir los vecinos', () => {
        const lines = segments(points);

        expect(lines).toHaveLength(2);
        expect(lines[0]?.split(' ')).toHaveLength(1);
        expect(lines[1]?.split(' ')).toHaveLength(2);
    });

    it('sin ningún dato no dibuja nada y todo en cero no rompe la escala', () => {
        expect(segments([{ date: '2026-11-01', value: null }])).toEqual([]);
        expect(segments([{ date: '2026-11-01', value: 0 }, { date: '2026-11-02', value: 0 }])).toHaveLength(1);
    });

    it('el valor más alto toca el techo y el más bajo (cero) queda en la base', () => {
        const [line] = segments([{ date: 'a', value: 0 }, { date: 'b', value: 10 }]);
        const [first, second] = (line ?? '').split(' ');

        expect(Number(first?.split(',')[1])).toBeGreaterThan(Number(second?.split(',')[1]));
    });
});

describe('TrendChart', () => {
    it('es una imagen con nombre accesible que dice el título y el rango', () => {
        render(<TrendChart title="Tickets por día" points={points} from="2026-11-01" to="2026-11-04" format={String} labels={labels} />);

        expect(screen.getByRole('img', { name: 'Tickets por día' })).toBeTruthy();
        expect(document.querySelector('title')?.textContent).toBe('Tickets por día, del 2026-11-01 al 2026-11-04');
    });

    it('«Ver como tabla» muestra los mismos datos en una tabla real, con «—» donde no hubo dato, y se puede volver al gráfico', async () => {
        const user = userEvent.setup();
        render(<TrendChart title="Tickets por día" points={points} from="2026-11-01" to="2026-11-04" format={(value) => `${String(value)} tickets`} labels={labels} />);

        await user.click(screen.getByRole('button', { name: 'Ver como tabla' }));

        const table = screen.getByRole('table', { name: 'Tickets por día' });
        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(5);
        expect(within(table).getByRole('columnheader', { name: 'Día' })).toBeTruthy();
        expect(within(row(rows, 2)).getByText('—')).toBeTruthy();
        expect(within(row(rows, 3)).getByText('4 tickets')).toBeTruthy();
        expect(screen.queryByRole('img')).toBeNull();

        await user.click(screen.getByRole('button', { name: 'Ver como gráfico' }));

        expect(screen.getByRole('img', { name: 'Tickets por día' })).toBeTruthy();
    });

    it('un solo día con dato se ve como un punto', () => {
        const { container } = render(<TrendChart title="Un día" points={[{ date: '2026-11-01', value: 3 }]} from="2026-11-01" to="2026-11-01" format={String} labels={labels} />);

        expect(container.querySelector('circle')).not.toBeNull();
    });
});
