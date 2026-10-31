import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { OtpInput } from './OtpInput';

const digitLabel = (position: number, total: number) => `Dígito ${String(position)} de ${String(total)}`;

function boxes(): HTMLInputElement[] {
    return screen.getAllByRole('textbox');
}

function box(index: number): HTMLInputElement {
    const element = boxes()[index];
    if (element === undefined) {
        throw new Error(`No existe la casilla ${String(index)}`);
    }

    return element;
}

describe('OtpInput', () => {
    it('muestra seis casillas con nombre accesible, dentro de un grupo con etiqueta', () => {
        render(<OtpInput label="Código de 6 dígitos" digitLabel={digitLabel} />);

        expect(boxes()).toHaveLength(6);
        expect(screen.getByRole('textbox', { name: 'Dígito 1 de 6' })).toBeDefined();
        expect(screen.getByRole('textbox', { name: 'Dígito 6 de 6' })).toBeDefined();
        expect(screen.getByRole('group', { name: 'Código de 6 dígitos' })).toBeDefined();
    });

    it('solo la primera casilla ofrece autocompletar el código de un solo uso, y todas piden teclado numérico', () => {
        render(<OtpInput label="Código" digitLabel={digitLabel} />);

        const [first, second] = boxes();
        expect(first?.getAttribute('autocomplete')).toBe('one-time-code');
        expect(second?.getAttribute('autocomplete')).toBe('off');
        boxes().forEach((box) => {
            expect(box.getAttribute('inputmode')).toBe('numeric');
        });
    });

    it('al escribir cada dígito el foco avanza y al completar avisa con el código', async () => {
        const user = userEvent.setup();
        const completed: string[] = [];
        render(
            <OtpInput
                label="Código"
                digitLabel={digitLabel}
                onComplete={(code) => {
                    completed.push(code);
                }}
            />,
        );

        await user.click(box(0));
        await user.keyboard('123456');

        expect(boxes().map((box) => box.value)).toEqual(['1', '2', '3', '4', '5', '6']);
        expect(completed).toEqual(['123456']);
        expect(document.activeElement).toBe(box(5));
    });

    it('ignora lo que no sea un dígito', async () => {
        const user = userEvent.setup();
        render(<OtpInput label="Código" digitLabel={digitLabel} />);

        await user.click(box(0));
        await user.keyboard('a-b+1');

        expect(boxes().map((box) => box.value)).toEqual(['1', '', '', '', '', '']);
    });

    it('Retroceso en una casilla vacía vuelve a la anterior y la borra', async () => {
        const user = userEvent.setup();
        render(<OtpInput label="Código" digitLabel={digitLabel} />);

        await user.click(box(0));
        await user.keyboard('12');
        expect(document.activeElement).toBe(box(2));

        await user.keyboard('{Backspace}');

        expect(document.activeElement).toBe(box(1));
        expect(boxes().map((box) => box.value)).toEqual(['1', '', '', '', '', '']);
    });

    it('pegar el código completo reparte los dígitos de una vez, aunque traiga espacios o guiones', async () => {
        const user = userEvent.setup();
        const changes: string[] = [];
        const completed: string[] = [];
        render(
            <OtpInput
                label="Código"
                digitLabel={digitLabel}
                onChange={(code) => {
                    changes.push(code);
                }}
                onComplete={(code) => {
                    completed.push(code);
                }}
            />,
        );

        await user.click(box(0));
        await user.paste('123 456');

        expect(boxes().map((box) => box.value)).toEqual(['1', '2', '3', '4', '5', '6']);
        expect(completed).toEqual(['123456']);
        expect(changes.at(-1)).toBe('123456');
    });

    it('pegar desde una casilla intermedia llena solo hasta el final y descarta el sobrante', async () => {
        const user = userEvent.setup();
        render(<OtpInput label="Código" digitLabel={digitLabel} />);

        await user.click(box(3));
        await user.paste('987654321');

        expect(boxes().map((box) => box.value)).toEqual(['', '', '', '9', '8', '7']);
    });

    it('las flechas, Inicio y Fin mueven el foco', async () => {
        const user = userEvent.setup();
        render(<OtpInput label="Código" digitLabel={digitLabel} />);

        await user.click(box(2));
        await user.keyboard('{ArrowRight}');
        expect(document.activeElement).toBe(box(3));

        await user.keyboard('{ArrowLeft}{ArrowLeft}');
        expect(document.activeElement).toBe(box(1));

        await user.keyboard('{End}');
        expect(document.activeElement).toBe(box(5));

        await user.keyboard('{Home}');
        expect(document.activeElement).toBe(box(0));
    });

    it('con error marca las casillas como inválidas y une el mensaje al grupo', () => {
        render(<OtpInput label="Código" digitLabel={digitLabel} error="El código no es correcto." />);

        const message = screen.getByText('El código no es correcto.');
        expect(boxes().every((box) => box.getAttribute('aria-invalid') === 'true')).toBe(true);
        expect(screen.getByRole('group').getAttribute('aria-describedby')).toBe(message.id);
    });

    it('puede empezar con el foco en la primera casilla', () => {
        render(<OtpInput label="Código" digitLabel={digitLabel} focusOnMount />);

        expect(document.activeElement).toBe(box(0));
    });

    it('deshabilitado no recibe dígitos', async () => {
        const user = userEvent.setup();
        render(<OtpInput label="Código" digitLabel={digitLabel} disabled />);

        await user.click(box(0));
        await user.keyboard('123456');

        expect(boxes().map((box) => box.value)).toEqual(['', '', '', '', '', '']);
    });
});
