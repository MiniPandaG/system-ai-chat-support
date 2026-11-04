import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Show from './Show';
import Thanks from './Thanks';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
let formErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const { useState } = await import('react');

    return {
        Head: () => null,
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setData] = useState<T>(initial);

            return {
                data,
                errors: formErrors,
                processing: false,
                setData: (key: string, value: unknown) => {
                    setData((current) => ({ ...current, [key]: value }));
                },
                post: (url: string, options: unknown) => {
                    post(url, data, options);
                },
            };
        },
    };
});

const copy = {
    heading: '¿Cómo te fue con la atención de :company?',
    detail: 'Tu respuesta ayuda al equipo a mejorar.',
    score_legend: 'Calificación',
    scale: { '1': 'Muy mala', '2': 'Mala', '3': 'Regular', '4': 'Buena', '5': 'Excelente' },
    comment: 'Comentario (opcional)',
    comment_hint: 'No escribas contraseñas ni números de tarjeta.',
    submit: 'Enviar respuesta',
    submitting: 'Enviando…',
    invalid_title: 'Este enlace ya no sirve',
    invalid: 'La encuesta ya se respondió o el enlace venció.',
};

const TOKEN = '7.' + 'a'.repeat(43);

function renderShow(overrides: Partial<Parameters<typeof Show>[0]> = {}) {
    return render(<Show copy={copy} token={TOKEN} open company="Zapatería Ejemplo" theme={{}} score={null} comment_max={1000} {...overrides} />);
}

describe('Survey (encuesta pública)', () => {
    beforeEach(() => {
        post.mockReset();
        formErrors = {};
        document.documentElement.removeAttribute('style');
    });

    it('muestra la pregunta con el nombre de la empresa y las cinco calificaciones con palabras', () => {
        renderShow();

        expect(screen.getByRole('heading', { name: '¿Cómo te fue con la atención de Zapatería Ejemplo?' })).toBeTruthy();
        expect(screen.getAllByRole('radio')).toHaveLength(5);
        expect(screen.getByLabelText('1 · Muy mala')).toBeTruthy();
        expect(screen.getByLabelText('5 · Excelente')).toBeTruthy();
    });

    it('la calificación que traía el enlace llega marcada pero no se envía sola', () => {
        renderShow({ score: 4 });

        expect(screen.getByRole<HTMLInputElement>('radio', { name: '4 · Buena' }).checked).toBe(true);
        expect(screen.getByRole<HTMLInputElement>('radio', { name: '1 · Muy mala' }).checked).toBe(false);
        expect(post).not.toHaveBeenCalled();
    });

    it('envía el puntaje elegido y el comentario al enlace, con el token en la dirección', async () => {
        const user = userEvent.setup();
        renderShow({ score: 2 });

        await user.click(screen.getByLabelText('5 · Excelente'));
        await user.type(screen.getByLabelText('Comentario (opcional)'), 'Muy amables');
        await user.click(screen.getByRole('button', { name: 'Enviar respuesta' }));

        expect(post).toHaveBeenCalledWith(`/survey/${TOKEN}`, { score: 5, comment: 'Muy amables' }, expect.anything());
    });

    it('un comentario con HTML se escribe como texto y no crea elementos', async () => {
        const user = userEvent.setup();
        const { container } = renderShow();

        await user.type(screen.getByLabelText('Comentario (opcional)'), '<img src=x onerror=alert(1)>');

        expect(container.querySelector('img')).toBeNull();
    });

    it('el error del servidor queda junto al campo', () => {
        formErrors = { score: 'Elige una calificación del 1 al 5.', comment: 'El comentario puede tener hasta 1000 caracteres.' };
        renderShow();

        expect(screen.getByRole('alert').textContent).toBe('Elige una calificación del 1 al 5.');
        expect(screen.getByText('El comentario puede tener hasta 1000 caracteres.')).toBeTruthy();
    });

    it('aplica el color primario de la empresa como variables CSS y lo quita al salir', () => {
        const { unmount } = renderShow({ theme: { '--color-primary': '#2164DA', '--color-primary-foreground': '#FFFFFF' } });

        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('#2164DA');
        unmount();
        expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('');
    });

    it('un enlace que ya no sirve no muestra formulario ni nada de la empresa', () => {
        renderShow({ open: false, company: '' });

        expect(screen.getByRole('heading', { name: 'Este enlace ya no sirve' })).toBeTruthy();
        expect(screen.getByText('La encuesta ya se respondió o el enlace venció.')).toBeTruthy();
        expect(screen.queryByRole('radio')).toBeNull();
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('el agradecimiento no lleva formulario ni enlace', () => {
        render(<Thanks copy={{ thanks_title: 'Gracias por tu respuesta', thanks: 'Tu opinión ya está con el equipo.' }} />);

        expect(screen.getByRole('heading', { name: 'Gracias por tu respuesta' })).toBeTruthy();
        expect(screen.getByText('Tu opinión ya está con el equipo.')).toBeTruthy();
        expect(screen.queryByRole('button')).toBeNull();
    });
});
