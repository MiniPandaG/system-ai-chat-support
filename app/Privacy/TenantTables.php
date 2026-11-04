<?php

declare(strict_types=1);

namespace App\Privacy;

/**
 * El mapa único de dónde viven los datos de una empresa (RS-17 y RS-18). El borrado y la exportación salen de aquí, y
 * una prueba compara este mapa con las tablas reales: una tabla nueva con `tenant_id` que no esté aquí rompe la
 * integración continua antes de que una empresa pueda pedir su borrado y quedarse con datos.
 */
final class TenantTables
{
    /**
     * Tablas con `tenant_id` en el orden en que se pueden vaciar sin chocar con las claves foráneas (lo que apunta a
     * otra tabla va antes que a lo que apunta). `users` y `tenants` no están: caen al final, con sus sesiones.
     *
     * @var list<string>
     */
    public const PURGE_ORDER = [
        'ai_runs',
        'ai_calls',
        'ai_usage_daily',
        'ai_settings',
        'csat_responses',
        'mail_messages',
        'mail_threads',
        'api_idempotency',
        'api_keys',
        'ticket_attachments',
        'ticket_notes',
        'ticket_reads',
        'ticket_tag',
        'ticket_views',
        'ticket_messages',
        'widget_sessions',
        'tickets',
        'tags',
        'customers',
        'kb_chunks',
        'kb_documents',
        'kb_articles',
        'kb_context',
        'tenant_branding',
        'files',
        'invitations',
        'widget_origins',
    ];

    /**
     * Tablas que entran en la exportación y las columnas que se dejan fuera: secretos y huellas de credenciales, y las
     * rutas internas de los archivos. Todo lo demás sale tal cual, porque es lo que la empresa y sus clientes guardaron.
     *
     * @var array<string, list<string>>
     */
    public const EXPORT = [
        'users' => ['password', 'two_factor_secret', 'two_factor_recovery_codes'],
        'customers' => [],
        'tickets' => [],
        'ticket_messages' => [],
        'ticket_notes' => [],
        'ticket_tag' => [],
        'tags' => [],
        'ticket_views' => [],
        'ticket_attachments' => ['disk', 'path'],
        'ticket_reads' => [],
        'mail_messages' => [],
        'csat_responses' => ['token_hash'],
        'invitations' => ['token_hash'],
        'api_keys' => ['key_hash'],
        'kb_articles' => [],
        'kb_documents' => ['disk', 'path'],
        'kb_context' => [],
        'ai_settings' => [],
        'ai_runs' => [],
        'ai_calls' => [],
        'ai_usage_daily' => [],
        'tenant_branding' => [],
        'files' => ['disk', 'path'],
        'widget_origins' => [],
    ];

    /**
     * Tablas con `tenant_id` que no se exportan, cada una con su motivo.
     *
     * @var array<string, string>
     */
    public const NOT_EXPORTED = [
        'api_idempotency' => 'Guarda respuestas repetibles de la API, que ya viven en los tickets y mensajes exportados.',
        'mail_threads' => 'Solo contiene el token secreto de cada hilo de correo.',
        'widget_sessions' => 'Solo contiene credenciales de visitantes del widget, que caducan.',
        'kb_chunks' => 'Son fragmentos derivados de los artículos y documentos exportados.',
    ];

    /**
     * Palabras que delatan una columna secreta. Si una columna exportada coincide, una prueba falla: hay que decidir si
     * se excluye o se justifica.
     */
    public const SECRET_COLUMN = '/hash|(^|_)token($|_)|secret|password|recovery/i';
}
