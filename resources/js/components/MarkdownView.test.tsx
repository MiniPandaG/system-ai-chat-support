import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { MarkdownView } from './MarkdownView';

describe('MarkdownView', () => {
    it('da formato al Markdown básico y baja los títulos un nivel', () => {
        render(<MarkdownView source={'# Envíos\n\nTexto con **negrita** y *cursiva*.\n\n- uno\n- dos\n\n## Plazos'} />);

        expect(screen.getByRole('heading', { level: 2, name: 'Envíos' })).toBeTruthy();
        expect(screen.getByRole('heading', { level: 3, name: 'Plazos' })).toBeTruthy();
        expect(screen.queryByRole('heading', { level: 1 })).toBeNull();
        expect(screen.getByText('negrita').tagName).toBe('STRONG');
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    it('el HTML escrito dentro del texto no se interpreta ni se muestra como elemento', () => {
        const { container } = render(<MarkdownView source={'Hola <script>window.hackeado = true</script> <img src=x onerror="window.hackeado = true"> <b>fuerte</b>'} />);

        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('b')).toBeNull();
        expect((window as unknown as { hackeado?: boolean }).hackeado).toBeUndefined();
    });

    it('un enlace https se abre en otra pestaña sin pasar el origen ni el referente', () => {
        render(<MarkdownView source="[Políticas](https://empresa-ejemplo.com/politicas)" />);

        const link = screen.getByRole('link', { name: 'Políticas' });
        expect(link.getAttribute('href')).toBe('https://empresa-ejemplo.com/politicas');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer nofollow');
    });

    it('un enlace mailto se acepta', () => {
        render(<MarkdownView source="[Escríbenos](mailto:soporte@empresa-ejemplo.com)" />);

        expect(screen.getByRole('link', { name: 'Escríbenos' }).getAttribute('href')).toBe('mailto:soporte@empresa-ejemplo.com');
    });

    it.each([
        ['javascript', '[clic](javascript:alert(1))'],
        ['javascript con mayúsculas y espacios', '[clic](JaVaScRiPt:alert(1))'],
        ['data', '[clic](data:text/html;base64,PHNjcmlwdD4=)'],
        ['vbscript', '[clic](vbscript:msgbox(1))'],
        ['relativo', '[clic](/settings/api-keys)'],
        ['ancla', '[clic](#seccion)'],
        ['sin esquema', '[clic](empresa-ejemplo.com)'],
    ])('el enlace %s queda sin destino', (_name, source) => {
        render(<MarkdownView source={source} />);

        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getByText('clic')).toBeTruthy();
    });

    it('una imagen se reemplaza por su texto alternativo y no carga nada', () => {
        const { container } = render(<MarkdownView source="![Mapa de la tienda](https://sitio-ajeno.example/rastreo.png)" />);

        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('Mapa de la tienda')).toBeTruthy();
    });

    it('el código se muestra como texto', () => {
        render(<MarkdownView source={'Usa `<div>` así:\n\n```\n<script>x</script>\n```'} />);

        expect(screen.getByText('<div>')).toBeTruthy();
        expect(screen.getByText('<script>x</script>')).toBeTruthy();
    });
});
