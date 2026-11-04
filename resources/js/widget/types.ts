/** Lo que el servidor entrega al marco del widget en un atributo de datos. */
export interface WidgetConfig {
    key: string;
    /** El sitio que incrusta el widget: el único al que se le envían mensajes. */
    parent: string;
    company: string;
    mode: 'claro' | 'oscuro' | 'sitio';
    corner: 'right' | 'left';
    logo: string | null;
    copy: WidgetCopy;
}

export interface WidgetCopy {
    launcher_open: string;
    launcher_unread: string;
    close: string;
    window_label: string;
    welcome_title: string;
    welcome_detail: string;
    welcome_start: string;
    thread_label: string;
    thread_empty: string;
    composer_label: string;
    composer_placeholder: string;
    composer_send: string;
    composer_sending: string;
    sender_customer: string;
    sender_team: string;
    sending: string;
    queued: string;
    failed: string;
    retry: string;
    offline: string;
    ended: string;
    load_error: string;
    loading: string;
    new_message: string;
    contact_title: string;
    contact_help: string;
    contact_label: string;
    contact_save: string;
    contact_dismiss: string;
    contact_saved: string;
    survey_title: string;
    survey_help: string;
    survey_score: string;
    survey_scale_1: string;
    survey_scale_2: string;
    survey_scale_3: string;
    survey_scale_4: string;
    survey_scale_5: string;
    survey_comment: string;
    survey_send: string;
    survey_sending: string;
    survey_dismiss: string;
    survey_thanks: string;
    survey_error: string;
    survey_score_required: string;
    characters_left: string;
    too_long: string;
}

/** Un mensaje de la conversación, tal como lo entrega el servidor. */
export interface WidgetMessage {
    id: number;
    client_id: string | null;
    author: 'customer' | 'agent';
    body: string;
    sent_at: string;
    agent: string | null;
}

export interface Conversation {
    channel: string;
    ticket: { status: string; ended: boolean } | null;
    messages: WidgetMessage[];
    contact: { has_email: boolean };
    /** Hay una encuesta de satisfacción por contestar. */
    survey: { pending: boolean };
}

/**
 * Un mensaje del visitante que todavía no confirmó el servidor. «queued»: se intentará solo (no había conexión);
 * «sending»: va en camino; «failed»: el servidor lo rechazó y el visitante decide si reintenta.
 */
export interface OutgoingMessage {
    clientId: string;
    body: string;
    createdAt: string;
    status: 'queued' | 'sending' | 'failed';
    error: string | null;
}

/** Lo que el marco le cuenta al script que lo incrustó. */
export interface FrameState {
    open: boolean;
    unread: number;
    corner: 'right' | 'left';
}
