import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { UsageBar } from './UsageBar';

describe('UsageBar', () => {
    it('es una barra de progreso con nombre y valores accesibles', () => {
        render(<UsageBar label="Puestos de agente: 2 de 5" used={2} max={5} over={false} />);

        const bar = screen.getByRole('progressbar', { name: 'Puestos de agente: 2 de 5' });

        expect(bar.getAttribute('aria-valuemin')).toBe('0');
        expect(bar.getAttribute('aria-valuemax')).toBe('5');
        expect(bar.getAttribute('aria-valuenow')).toBe('2');
    });

    it('la parte llena es proporcional al uso', () => {
        render(<UsageBar label="Uso" used={1} max={4} over={false} />);

        expect(screen.getByRole('progressbar').firstElementChild?.getAttribute('style')).toContain('width: 25%');
    });

    it('cambia de tono según el avance: normal, advertencia desde el 80 % y peligro al pasarse', () => {
        const tone = (used: number, over: boolean) => {
            const { unmount } = render(<UsageBar label="Uso" used={used} max={5} over={over} />);
            const className = screen.getByRole('progressbar').firstElementChild?.className ?? '';
            unmount();

            return className;
        };

        expect(tone(2, false)).toContain('bg-primary');
        expect(tone(4, false)).toContain('bg-warning');
        expect(tone(5, false)).toContain('bg-warning');
        expect(tone(7, true)).toContain('bg-danger');
    });

    it('al pasarse se llena del todo pero el valor accesible no supera el máximo', () => {
        render(<UsageBar label="Uso" used={7} max={5} over />);

        const bar = screen.getByRole('progressbar');

        expect(bar.firstElementChild?.getAttribute('style')).toContain('width: 100%');
        expect(bar.getAttribute('aria-valuenow')).toBe('5');
    });

    it('un tope de cero se muestra lleno en vez de dividir entre cero', () => {
        render(<UsageBar label="Uso" used={0} max={0} over={false} />);

        expect(screen.getByRole('progressbar').firstElementChild?.getAttribute('style')).toContain('width: 100%');
    });
});
