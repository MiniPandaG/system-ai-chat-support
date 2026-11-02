import type { Conversation, WidgetConfig, WidgetCopy, WidgetMessage } from '../widget/types';

export const widgetCopy: WidgetCopy = {
    launcher_open: 'Abrir el chat de soporte',
    launcher_unread: 'Mensajes nuevos: :count',
    close: 'Cerrar el chat',
    window_label: 'Chat de soporte de :company',
    welcome_title: '¿En qué podemos ayudarte?',
    welcome_detail: 'Escríbenos y te respondemos en esta misma ventana.',
    welcome_start: 'Iniciar conversación',
    thread_label: 'Conversación con :company',
    thread_empty: 'Cuéntanos qué necesitas y un agente te responderá aquí.',
    composer_label: 'Tu mensaje',
    composer_placeholder: 'Escribe tu mensaje',
    composer_send: 'Enviar',
    composer_sending: 'Enviando mensaje',
    sender_customer: 'Tú',
    sender_team: 'Equipo',
    sending: 'Enviando…',
    queued: 'Se enviará al volver la conexión.',
    failed: 'No se envió.',
    retry: 'Reintentar',
    offline: 'Sin conexión. Los mensajes se enviarán cuando vuelvas a tener internet.',
    ended: 'Esta conversación terminó. Si escribes de nuevo, abrimos una nueva.',
    load_error: 'No se pudo cargar la conversación.',
    loading: 'Cargando la conversación',
    new_message: 'Nuevo mensaje del equipo.',
    contact_title: '¿Quieres dejar tu correo?',
    contact_help: 'Es opcional. El equipo lo verá junto a tu conversación.',
    contact_label: 'Tu correo',
    contact_save: 'Guardar correo',
    contact_dismiss: 'Ahora no',
    contact_saved: 'Guardamos tu correo en la conversación.',
    characters_left: 'Te quedan :count caracteres.',
    too_long: 'El mensaje es demasiado largo. El máximo es de :max caracteres.',
};

export const WIDGET_KEY = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
export const PARENT_ORIGIN = 'https://tienda-ejemplo.com';

export const widgetConfig: WidgetConfig = {
    key: WIDGET_KEY,
    parent: PARENT_ORIGIN,
    company: 'Tienda Ejemplo',
    mode: 'claro',
    corner: 'right',
    logo: null,
    copy: widgetCopy,
};

export function customerMessage(overrides: Partial<WidgetMessage> = {}): WidgetMessage {
    return { id: 1, client_id: '11111111-1111-4111-8111-111111111111', author: 'customer', body: 'Hola, necesito ayuda', sent_at: '2026-11-01T15:00:00+00:00', agent: null, ...overrides };
}

export function agentMessage(overrides: Partial<WidgetMessage> = {}): WidgetMessage {
    return { id: 2, client_id: null, author: 'agent', body: 'Con gusto te ayudo', sent_at: '2026-11-01T15:01:00+00:00', agent: 'Laura', ...overrides };
}

export function conversation(messages: WidgetMessage[] = [customerMessage()], overrides: Partial<Conversation> = {}): Conversation {
    return { channel: 'tenant.1.widget.01abc', ticket: { status: 'open', ended: false }, messages, contact: { has_email: false }, ...overrides };
}
