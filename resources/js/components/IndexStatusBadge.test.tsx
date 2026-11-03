import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { IndexStatusBadge } from './IndexStatusBadge';
import type { IndexState } from './IndexStatusBadge';

function renderBadge(status: IndexState | null) {
    return render(<IndexStatusBadge status={status} pending="Indexando…" indexed="Indexado" failed="Error de indexación" />);
}

describe('IndexStatusBadge', () => {
    it('lo que no está publicado no lleva insignia', () => {
        const { container } = renderBadge(null);

        expect(container.textContent).toBe('');
    });

    it('indexando lleva un indicador de 12 px escondido para los lectores de pantalla y el texto', () => {
        const { container } = renderBadge('pending');

        expect(screen.getByText('Indexando…')).toBeTruthy();
        const spinner = container.querySelector('[aria-hidden="true"]');
        expect(spinner?.className).toContain('size-3');
        expect(spinner?.className).toContain('animate-spin');
    });

    it('indexado y con error se dicen con palabras', () => {
        const { rerender } = renderBadge('indexed');
        expect(screen.getByText('Indexado')).toBeTruthy();

        rerender(<IndexStatusBadge status="failed" pending="Indexando…" indexed="Indexado" failed="Error de indexación" />);
        expect(screen.getByText('Error de indexación')).toBeTruthy();
    });
});
