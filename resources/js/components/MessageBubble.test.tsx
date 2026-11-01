import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { agentMessage, customerMessage, ticketsCopy } from '../test-fixtures/tickets';
import { MessageBubble } from './MessageBubble';

describe('MessageBubble', () => {
    it('cada mensaje es un artículo con autor y hora en texto accesible', () => {
        render(<MessageBubble message={customerMessage} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(screen.getByRole('article', { name: 'Cliente: Marisol Quintero, 31 de oct, 3:00 pm' })).toBeTruthy();
    });

    it('el cliente va a la izquierda y el equipo a la derecha', () => {
        const { container, rerender } = render(<MessageBubble message={customerMessage} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} />);
        expect(container.querySelector('article')?.className).toContain('items-start');

        rerender(<MessageBubble message={agentMessage} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} />);
        expect(container.querySelector('article')?.className).toContain('items-end');
    });

    it('respeta los saltos de línea del texto', () => {
        const { container } = render(<MessageBubble message={customerMessage} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(container.querySelector('.whitespace-pre-wrap')?.textContent).toBe('Me dice que mi contraseña es incorrecta.\nYa probé restablecerla.');
    });

    it('un texto con HTML se muestra como texto, nunca como elementos', () => {
        const hostile = '<img src=x onerror="alert(1)"><script>alert(2)</script><b>negrita</b>';
        const { container } = render(<MessageBubble message={{ ...customerMessage, body: hostile }} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(container.querySelector('img, script, b')).toBeNull();
        expect(container.querySelector('.whitespace-pre-wrap')?.textContent).toBe(hostile);
    });

    it('el nombre del autor tampoco se interpreta como HTML', () => {
        const { container } = render(<MessageBubble message={{ ...customerMessage, author_name: '<i>Ana</i>' }} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(container.querySelector('i')).toBeNull();
        expect(screen.getByText('<i>Ana</i>')).toBeTruthy();
    });
});
