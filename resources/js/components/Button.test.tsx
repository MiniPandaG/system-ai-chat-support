import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Button } from './Button';

describe('Button', () => {
    it('es un botón real y no envía formularios salvo que sea de tipo submit', () => {
        render(<Button>Guardar</Button>);

        expect(screen.getByRole('button', { name: 'Guardar' }).getAttribute('type')).toBe('button');
    });

    it('al cargar se deshabilita, conserva su texto en el espacio y anuncia el progreso', () => {
        render(
            <Button loading loadingLabel="Iniciando sesión…">
                Iniciar sesión
            </Button>,
        );

        const button = screen.getByRole('button');
        expect(button.hasAttribute('disabled')).toBe(true);
        expect(button.getAttribute('aria-busy')).toBe('true');
        expect(screen.getByRole('status').textContent).toBe('Iniciando sesión…');
        expect(screen.getByText('Iniciar sesión').className).toContain('invisible');
    });

    it('un botón cargando no repite clics', async () => {
        const user = userEvent.setup();
        let clicks = 0;
        render(
            <Button
                loading
                onClick={() => {
                    clicks += 1;
                }}
            >
                Enviar
            </Button>,
        );

        await user.click(screen.getByRole('button'));
        expect(clicks).toBe(0);
    });

    it('un botón deshabilitado no responde al clic', async () => {
        const user = userEvent.setup();
        let clicks = 0;
        render(
            <Button
                disabled
                onClick={() => {
                    clicks += 1;
                }}
            >
                Enviar
            </Button>,
        );

        await user.click(screen.getByRole('button'));
        expect(clicks).toBe(0);
    });
});
