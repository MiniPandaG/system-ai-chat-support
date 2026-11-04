import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { agentMessage, aiMessage, customerMessage, ticketsCopy } from '../test-fixtures/tickets';
import { MessageBubble } from './MessageBubble';

describe('MessageBubble', () => {
    it('cada mensaje es un artículo con autor y hora en texto accesible', () => {
        render(<MessageBubble message={customerMessage} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);

        expect(screen.getByRole('article', { name: 'Cliente: Marisol Quintero, 31 de oct, 3:00 pm' })).toBeTruthy();
    });

    it('el cliente va a la izquierda y el equipo a la derecha', () => {
        const { container, rerender } = render(<MessageBubble message={customerMessage} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);
        expect(container.querySelector('article')?.className).toContain('items-start');

        rerender(<MessageBubble message={agentMessage} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);
        expect(container.querySelector('article')?.className).toContain('items-end');
    });

    it('respeta los saltos de línea del texto', () => {
        const { container } = render(<MessageBubble message={customerMessage} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);

        expect(container.querySelector('.whitespace-pre-wrap')?.textContent).toBe('Me dice que mi contraseña es incorrecta.\nYa probé restablecerla.');
    });

    it('un texto con HTML se muestra como texto, nunca como elementos', () => {
        const hostile = '<img src=x onerror="alert(1)"><script>alert(2)</script><b>negrita</b>';
        const { container } = render(<MessageBubble message={{ ...customerMessage, body: hostile }} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);

        expect(container.querySelector('img, script, b')).toBeNull();
        expect(container.querySelector('.whitespace-pre-wrap')?.textContent).toBe(hostile);
    });

    it('el nombre del autor tampoco se interpreta como HTML', () => {
        const { container } = render(<MessageBubble message={{ ...customerMessage, author_name: '<i>Ana</i>' }} authorLabel="Cliente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);

        expect(container.querySelector('i')).toBeNull();
        expect(screen.getByText('<i>Ana</i>')).toBeTruthy();
    });
});

describe('MessageBubble: mensajes de la IA', () => {
    it('lleva la insignia «IA» y quién aprobó el borrador, y va del lado del equipo', () => {
        const { container } = render(<MessageBubble message={aiMessage} authorLabel="IA" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);

        expect(screen.getByText('Asistente virtual')).toBeTruthy();
        expect(screen.getByText('IA', { selector: 'span' })).toBeTruthy();
        expect(screen.getByText('· Aprobada por Ana Torres')).toBeTruthy();
        expect(container.querySelector('article')?.className).toContain('items-end');
        expect(screen.getByRole('article', { name: 'IA: Asistente virtual, 31 de oct, 3:06 pm' })).toBeTruthy();
    });

    it('un mensaje del equipo o del cliente no lleva insignia ni aprobación', () => {
        render(<MessageBubble message={agentMessage} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} aiCopy={ticketsCopy.ai} />);

        expect(screen.queryByText('IA')).toBeNull();
        expect(screen.queryByText(/Aprobada por/)).toBeNull();
    });
});
