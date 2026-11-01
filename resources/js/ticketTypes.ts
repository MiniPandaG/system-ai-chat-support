export type TicketStatus = 'open' | 'waiting_customer' | 'resolved' | 'closed';
export type TicketPriority = 'low' | 'normal' | 'high' | 'urgent';

export interface TicketRowData {
    id: number;
    number: number;
    subject: string;
    status: TicketStatus;
    priority: TicketPriority;
    customer_name: string | null;
    assignee_name: string | null;
    last_message_at: string;
    last_message_label: string;
    /** Con una búsqueda: en qué campo coincidió el ticket. */
    match: 'number' | 'subject' | 'customer' | 'message' | null;
}

export type AttachmentStatus = 'pending' | 'clean' | 'infected' | 'failed';

export interface AttachmentData {
    id: string;
    name: string;
    size: number;
    status: AttachmentStatus;
    /** Enlace de descarga firmado; solo existe cuando el antivirus declaró limpio el archivo. */
    url: string | null;
}

export interface TicketMessageData {
    kind: 'message';
    id: number;
    author: 'customer' | 'agent';
    author_name: string | null;
    body: string;
    attachments: AttachmentData[];
    created_at: string;
    created_label: string;
}

/** Nota interna: solo la reciben quienes atienden, nunca un observador. */
export interface TicketNoteData {
    kind: 'note';
    id: number;
    author_name: string | null;
    body: string;
    created_at: string;
    created_label: string;
}

export type ThreadEntry = TicketMessageData | TicketNoteData;

export type TagTone = 'neutral' | 'primary' | 'info' | 'success' | 'warning' | 'danger';

export interface TagData {
    id: number;
    name: string;
    tone: TagTone;
}

export interface TicketDetailData {
    id: number;
    number: number;
    subject: string;
    status: TicketStatus;
    priority: TicketPriority;
    channel: string;
    assigned_to: number | null;
    customer: { name: string | null; email: string | null };
    created_at: string;
    created_label: string;
    tags: TagData[];
    thread: ThreadEntry[];
}

export interface Assignee {
    id: number;
    name: string;
    active: boolean;
}

export interface NewTicketCopy {
    title: string;
    detail: string;
    subject: string;
    subject_hint: string;
    description: string;
    description_hint: string;
    customer_existing: string;
    customer_new: string;
    customer_search: string;
    customer_search_hint: string;
    customer_result_one: string;
    customer_results: string;
    customer_none: string;
    customer_selected: string;
    customer_change: string;
    customer_name: string;
    customer_email: string;
    customer_email_hint: string;
    submit: string;
    submitting: string;
    cancel: string;
}

export interface ComposerCopy {
    label: string;
    mode_label: string;
    mode_reply: string;
    mode_note: string;
    reply_announce: string;
    note_announce: string;
    reply_field: string;
    note_field: string;
    reply_placeholder: string;
    note_placeholder: string;
    reply_hint: string;
    note_hint: string;
    send_reply: string;
    send_note: string;
    sending: string;
    closed: string;
    reopen: string;
    read_only: string;
}

export interface AttachmentsCopy {
    add: string;
    hint: string;
    selected: string;
    remove: string;
    too_many: string;
    too_large: string;
    bad_type: string;
    empty: string;
    uploading: string;
    list_label: string;
    download: string;
    drop: string;
    status: Record<'pending' | 'infected' | 'failed', string>;
}

export interface TagsCopy {
    label: string;
    none: string;
    add: string;
    remove: string;
    existing: string;
    existing_none: string;
    new: string;
    name: string;
    name_hint: string;
    tone: string;
    tones: Record<TagTone, string>;
    create: string;
    close: string;
}

export type InboxScope = 'all' | 'mine' | 'unassigned';
export type InboxSort = 'recent' | 'oldest' | 'priority';

/** Filtros, búsqueda y orden de la bandeja tal como los entiende el servidor y viajan en la dirección. */
export interface InboxFilters {
    scope?: InboxScope | undefined;
    status?: TicketStatus[] | undefined;
    priority?: TicketPriority[] | undefined;
    agent?: number | undefined;
    tag?: number | undefined;
    from?: string | undefined;
    to?: string | undefined;
    q?: string | undefined;
    sort?: InboxSort | undefined;
    /** Vista guardada activa; sus filtros los aplica el servidor. */
    saved?: number | null;
}

export interface InboxCounts {
    all: number;
    mine: number;
    unassigned: number;
}

export interface SavedView {
    id: number;
    name: string;
    shared: boolean;
    count: number;
    deletable: boolean;
}

export interface InboxCopy {
    tabs_label: string;
    scope: Record<InboxScope, string>;
    shared: string;
    search_label: string;
    search_placeholder: string;
    search_hint: string;
    filters: string;
    filters_active: string;
    panel_label: string;
    status: string;
    priority: string;
    agent: string;
    any_agent: string;
    tag: string;
    any_tag: string;
    from: string;
    to: string;
    sort_label: string;
    sort: Record<InboxSort, string>;
    clear: string;
    remove_filter: string;
    count_none: string;
    count_one: string;
    count_many: string;
    empty_filtered: string;
    match: Record<'number' | 'subject' | 'customer' | 'message', string>;
    load_more: string;
    loading_more: string;
    load_error: string;
    save: {
        button: string;
        name: string;
        name_hint: string;
        shared: string;
        shared_hint: string;
        submit: string;
        submitting: string;
        cancel: string;
    };
    delete: { button: string; title: string; detail: string; confirm: string; cancel: string };
}

export interface TicketsCopy {
    title: string;
    list_label: string;
    new_ticket: string;
    select_hint: string;
    back_to_inbox: string;
    unassigned: string;
    empty_title: string;
    empty_detail: string;
    empty_observer: string;
    number: string;
    from: string;
    channel_label: string;
    created_at: string;
    conversation: string;
    message_author_customer: string;
    message_author_agent: string;
    no_customer: string;
    channels: Record<string, string>;
    status_label: string;
    status: Record<TicketStatus, string>;
    priority_label: string;
    priority: Record<TicketPriority, string>;
    assignee_label: string;
    assignee_inactive: string;
    new: NewTicketCopy;
    composer: ComposerCopy;
    note: { label: string; aria: string };
    tags: TagsCopy;
    attachments: AttachmentsCopy;
    inbox: InboxCopy;
}
