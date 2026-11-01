import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { agentMessage, ticketsCopy } from '../test-fixtures/tickets';
import type { AttachmentData, TicketMessageData } from '../ticketTypes';
import { MessageBubble } from './MessageBubble';

const attachment: AttachmentData = { id: 'a1', name: 'informe.pdf', size: 2048, status: 'clean', url: 'https://app.test/attachments/a1?signature=x' };

function withAttachments(attachments: AttachmentData[], body = agentMessage.body): TicketMessageData {
    return { ...agentMessage, body, attachments };
}

describe('MessageBubble: adjuntos', () => {
    it('un mensaje con archivos los muestra debajo del texto', () => {
        render(<MessageBubble message={withAttachments([attachment])} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(screen.getByText('Ya lo estamos revisando.')).toBeTruthy();
        expect(screen.getByRole('link', { name: /Descargar informe.pdf/ })).toBeTruthy();
    });

    it('un mensaje que es solo archivos no deja una burbuja vacía', () => {
        const { container } = render(<MessageBubble message={withAttachments([attachment], '')} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(container.querySelector('.whitespace-pre-wrap')).toBeNull();
        expect(screen.getByRole('list', { name: 'Archivos adjuntos' })).toBeTruthy();
    });

    it('sin archivos no hay lista de adjuntos', () => {
        render(<MessageBubble message={withAttachments([])} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(screen.queryByRole('list', { name: 'Archivos adjuntos' })).toBeNull();
    });

    it('el artículo sigue teniendo autor y hora aunque no haya texto', () => {
        render(<MessageBubble message={withAttachments([attachment], '')} authorLabel="Agente" attachmentsCopy={ticketsCopy.attachments} />);

        expect(screen.getByRole('article', { name: 'Agente: Ana Torres, 31 de oct, 3:05 pm' })).toBeTruthy();
    });
});
