import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConnectionIndicator } from './ConnectionIndicator';

let state = 'connected';

vi.mock('../realtime/hooks', () => ({ useConnectionState: () => state }));

const copy = { live_on: 'En vivo', live_connecting: 'Conectando…', live_off: 'Sin conexión en vivo' };

describe('ConnectionIndicator', () => {
    it('conectado: punto verde y «En vivo»', () => {
        state = 'connected';
        const { container } = render(<ConnectionIndicator copy={copy} />);

        expect(screen.getByRole('img', { name: 'En vivo' })).toBeTruthy();
        expect(container.querySelector('.bg-success')).not.toBeNull();
    });

    it('conectando: punto ámbar', () => {
        state = 'connecting';
        const { container } = render(<ConnectionIndicator copy={copy} />);

        expect(screen.getByRole('img', { name: 'Conectando…' })).toBeTruthy();
        expect(container.querySelector('.bg-warning')).not.toBeNull();
    });

    it('sin conexión o no disponible: punto gris y el texto lo dice', () => {
        for (const current of ['disconnected', 'unavailable']) {
            state = current;
            const { container, unmount } = render(<ConnectionIndicator copy={copy} />);

            expect(screen.getByRole('img', { name: 'Sin conexión en vivo' })).toBeTruthy();
            expect(container.querySelector('.bg-text-tertiary')).not.toBeNull();
            unmount();
        }
    });

    it('el estado nunca depende solo del color: tiene nombre accesible y título', () => {
        state = 'connected';
        render(<ConnectionIndicator copy={copy} />);

        const indicator = screen.getByRole('img', { name: 'En vivo' });

        expect(indicator.getAttribute('title')).toBe('En vivo');
    });
});
