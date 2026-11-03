<?php

declare(strict_types=1);

namespace App\Api;

/**
 * La especificación OpenAPI 3.1 de la API v1, escrita a mano. Es la fuente de la documentación interactiva y la lista
 * contra la que una prueba comprueba las rutas reales, los permisos y los campos que acepta cada petición: si la API
 * cambia y esto no, las pruebas fallan.
 */
final class OpenApiSpec
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'API de TikaroChat',
                'version' => '1.0.0',
                'description' => "Crea y consulta tickets, mensajes y clientes de tu empresa desde tus propios sistemas.\n\n**Autenticación.** Cada petición lleva una llave de API en la cabecera `Authorization: Bearer tk_…`. Las llaves se crean en el panel (Llaves de API) y tienen un nivel de acceso: lectura, escritura (incluye lectura) o administración (incluye escritura).\n\n**Errores.** Siempre `{\"error\": {\"code\", \"message\", \"details\"?}}` con un código estable.\n\n**Listas.** Se paginan por cursor: usa `next_cursor` de la respuesta como `cursor` de la siguiente petición.\n\n**Reintentos seguros.** En las creaciones, envía una `Idempotency-Key` (hasta 64 caracteres) y repetir la petición no crea nada otra vez.\n\n**Límites.** Hay un tope de peticiones por llave y por dirección; al pasarlo la API responde 429 con `Retry-After`.",
            ],
            'servers' => [['url' => url('/api/v1'), 'description' => 'Esta instalación']],
            'security' => [['bearerAuth' => []]],
            'tags' => [
                ['name' => 'Tickets'],
                ['name' => 'Mensajes'],
                ['name' => 'Clientes'],
                ['name' => 'Equipo'],
            ],
            'paths' => [
                '/tickets' => [
                    'get' => self::operation('Tickets', 'Listar tickets', 'read', 'Del más reciente al más antiguo, con filtros y paginación por cursor.', [
                        ['$ref' => '#/components/parameters/Limit'],
                        ['$ref' => '#/components/parameters/Cursor'],
                        self::query('status', 'Filtra por estado.', ['type' => 'string', 'enum' => ['open', 'waiting_customer', 'resolved', 'closed']]),
                        self::query('priority', 'Filtra por prioridad.', ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']]),
                        self::query('assigned_to', 'Filtra por quien atiende: un identificador de la lista de agentes, o `none` para los sin asignar.', ['type' => 'string']),
                        self::query('customer_id', 'Filtra por cliente.', ['type' => 'integer']),
                        self::query('number', 'Busca por el número visible del ticket.', ['type' => 'integer']),
                    ], null, ['200' => self::page('Ticket', 'Una página de tickets.')]),
                    'post' => self::operation('Tickets', 'Crear un ticket', 'write', 'El ticket nace abierto, con el canal `api` y la descripción como primer mensaje del cliente. Si `customer_email` ya es de un cliente de tu empresa se usa ese cliente; si no, se crea uno. Cuenta contra el límite de tickets del plan: pasado el tope responde 422 con el código `plan_limit`.', [
                        ['$ref' => '#/components/parameters/IdempotencyKey'],
                    ], self::body('TicketCreate'), ['201' => self::created('Ticket', 'El ticket creado.')]),
                ],
                '/tickets/{ticket}' => [
                    'get' => self::operation('Tickets', 'Consultar un ticket', 'read', 'Un ticket de tu empresa por su identificador.', [self::path('ticket', 'Identificador del ticket.')], null, ['200' => self::one('Ticket', 'El ticket.')]),
                    'patch' => self::operation('Tickets', 'Cambiar un ticket', 'admin', 'Cambia el estado, la prioridad o quien atiende. Solo se modifica lo que envías. `assigned_to: null` deja el ticket sin asignar.', [self::path('ticket', 'Identificador del ticket.')], self::body('TicketUpdate'), ['200' => self::one('Ticket', 'El ticket actualizado.')]),
                ],
                '/tickets/{ticket}/messages' => [
                    'get' => self::operation('Mensajes', 'Listar los mensajes de un ticket', 'read', 'En orden cronológico, con paginación por cursor. Las notas internas del equipo nunca se incluyen.', [
                        self::path('ticket', 'Identificador del ticket.'),
                        ['$ref' => '#/components/parameters/Limit'],
                        ['$ref' => '#/components/parameters/Cursor'],
                    ], null, ['200' => self::page('Message', 'Una página de mensajes.')]),
                    'post' => self::operation('Mensajes', 'Añadir un mensaje', 'write', 'A nombre del cliente (por defecto) o del equipo. Un mensaje del cliente reabre un ticket resuelto o en espera; uno del equipo se envía por correo si el cliente dejó el suyo. Un ticket cerrado no recibe mensajes.', [
                        self::path('ticket', 'Identificador del ticket.'),
                        ['$ref' => '#/components/parameters/IdempotencyKey'],
                    ], self::body('MessageCreate'), ['201' => self::created('Message', 'El mensaje creado.')]),
                ],
                '/customers' => [
                    'get' => self::operation('Clientes', 'Listar o buscar clientes', 'read', 'Del más reciente al más antiguo. `email` busca un correo exacto; `q` busca por nombre o correo (mínimo 2 caracteres).', [
                        ['$ref' => '#/components/parameters/Limit'],
                        ['$ref' => '#/components/parameters/Cursor'],
                        self::query('email', 'Correo exacto.', ['type' => 'string']),
                        self::query('q', 'Texto a buscar en el nombre o el correo.', ['type' => 'string', 'minLength' => 2]),
                    ], null, ['200' => self::page('Customer', 'Una página de clientes.')]),
                    'post' => self::operation('Clientes', 'Crear un cliente', 'write', 'Un correo que ya usa otro cliente de tu empresa se rechaza.', [
                        ['$ref' => '#/components/parameters/IdempotencyKey'],
                    ], self::body('CustomerCreate'), ['201' => self::created('Customer', 'El cliente creado.')]),
                ],
                '/customers/{customer}' => [
                    'get' => self::operation('Clientes', 'Consultar un cliente', 'read', 'Un cliente de tu empresa por su identificador.', [self::path('customer', 'Identificador del cliente.')], null, ['200' => self::one('Customer', 'El cliente.')]),
                ],
                '/agents' => [
                    'get' => self::operation('Equipo', 'Listar quién puede atender tickets', 'read', 'Administradores y agentes activos. Sus identificadores son los que se usan en `assigned_to`. Solo nombre y rol.', [], null, ['200' => ['description' => 'La lista de agentes.', 'content' => self::json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Agent']]]])]]),
                ],
            ],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Una llave de API de tu empresa: `tk_…`.']],
                'parameters' => [
                    'Limit' => ['name' => 'limit', 'in' => 'query', 'description' => 'Cuántos elementos por página (1 a 100, por defecto 25).', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                    'Cursor' => ['name' => 'cursor', 'in' => 'query', 'description' => 'El `next_cursor` de la página anterior.', 'schema' => ['type' => 'integer', 'minimum' => 1]],
                    'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'description' => 'Hace seguro reintentar: repetir la petición con la misma clave y el mismo contenido devuelve la misma respuesta (con la cabecera `Idempotent-Replayed: true`) y no crea nada. Hasta 64 caracteres: letras, números, guion y guion bajo. Se recuerda 24 horas.', 'schema' => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9_-]{1,64}$']],
                ],
                'responses' => [
                    'Unauthorized' => ['description' => 'Falta la llave o no es válida (inexistente, revocada o caducada).', 'content' => self::error('unauthenticated', 'Falta una llave de API válida. Envíala en la cabecera Authorization: Bearer.')],
                    'Forbidden' => ['description' => 'La llave no tiene el nivel de acceso que pide esta ruta.', 'content' => self::error('forbidden', 'Esta llave no tiene permiso para esta acción.')],
                    'NotFound' => ['description' => 'El recurso no existe en tu empresa.', 'content' => self::error('not_found', 'No se encontró el recurso.')],
                    'ValidationFailed' => ['description' => 'Algún dato no es válido, la clave de idempotencia se reutilizó con otro contenido o se alcanzó un límite del plan.', 'content' => self::error('validation_failed', 'Algunos datos no son válidos. Revisa los detalles.', ['asunto' => ['El asunto es obligatorio.']])],
                    'RateLimited' => ['description' => 'Demasiadas peticiones. Espera los segundos de `Retry-After`.', 'headers' => ['Retry-After' => ['description' => 'Segundos que hay que esperar.', 'schema' => ['type' => 'integer']]], 'content' => self::error('rate_limited', 'Hiciste demasiadas peticiones. Espera un momento e inténtalo de nuevo.')],
                ],
                'schemas' => self::schemas(),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>|null  $body
     * @param  array<int|string, array<string, mixed>>  $responses
     * @return array<string, mixed>
     */
    private static function operation(string $tag, string $summary, string $permission, string $description, array $parameters, ?array $body, array $responses): array
    {
        $errors = ['401' => ['$ref' => '#/components/responses/Unauthorized'], '429' => ['$ref' => '#/components/responses/RateLimited']];

        // Toda llave puede leer: solo las rutas que piden más que lectura pueden responder 403.
        if ($permission !== 'read') {
            $errors['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        if (array_filter($parameters, fn (array $p): bool => ($p['in'] ?? null) === 'path') !== []) {
            $errors['404'] = ['$ref' => '#/components/responses/NotFound'];
        }

        if ($body !== null || array_filter($parameters, fn (array $p): bool => in_array($p['name'] ?? null, ['limit', 'cursor', 'status', 'priority', 'assigned_to', 'q'], true)) !== []) {
            $errors['422'] = ['$ref' => '#/components/responses/ValidationFailed'];
        }

        ksort($errors);

        return array_filter([
            'tags' => [$tag],
            'summary' => $summary,
            'description' => $description,
            'x-permission' => $permission,
            'parameters' => $parameters === [] ? null : $parameters,
            'requestBody' => $body,
            'responses' => $responses + $errors,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function query(string $name, string $description, array $schema): array
    {
        return ['name' => $name, 'in' => 'query', 'description' => $description, 'schema' => $schema];
    }

    /**
     * @return array<string, mixed>
     */
    private static function path(string $name, string $description): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'description' => $description, 'schema' => ['type' => 'integer']];
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(string $schema): array
    {
        return ['required' => true, 'content' => self::json(['$ref' => '#/components/schemas/'.$schema])];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function json(array $schema): array
    {
        return ['application/json' => ['schema' => $schema]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function one(string $schema, string $description): array
    {
        return ['description' => $description, 'content' => self::json(['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/'.$schema]]])];
    }

    /**
     * @return array<string, mixed>
     */
    private static function created(string $schema, string $description): array
    {
        return self::one($schema, $description) + ['headers' => ['Location' => ['description' => 'La dirección del recurso creado.', 'schema' => ['type' => 'string']]]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function page(string $schema, string $description): array
    {
        return ['description' => $description, 'content' => self::json(['type' => 'object', 'properties' => [
            'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/'.$schema]],
            'next_cursor' => ['type' => ['integer', 'null'], 'description' => 'El cursor de la página siguiente; nulo en la última.'],
        ]])];
    }

    /**
     * @param  array<string, mixed>|null  $details
     * @return array<string, mixed>
     */
    private static function error(string $code, string $message, ?array $details = null): array
    {
        return self::json(['$ref' => '#/components/schemas/Error'] + ['example' => ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details])]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemas(): array
    {
        $date = ['type' => 'string', 'format' => 'date-time'];
        $nullDate = ['type' => ['string', 'null'], 'format' => 'date-time'];

        return [
            'Ticket' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
                'number' => ['type' => 'integer', 'description' => 'El número visible del ticket dentro de tu empresa.'],
                'subject' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['open', 'waiting_customer', 'resolved', 'closed']],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                'channel' => ['type' => 'string', 'enum' => ['panel', 'widget', 'api'], 'description' => 'Por dónde llegó el ticket.'],
                'customer_id' => ['type' => 'integer'],
                'assigned_to' => ['type' => ['integer', 'null'], 'description' => 'Identificador de quien atiende (ver /agents) o nulo.'],
                'created_at' => $date,
                'last_message_at' => $date,
                'resolved_at' => $nullDate,
                'closed_at' => $nullDate,
            ], 'example' => ['id' => 42, 'number' => 7, 'subject' => 'Pedido sin llegar', 'status' => 'open', 'priority' => 'normal', 'channel' => 'api', 'customer_id' => 15, 'assigned_to' => null, 'created_at' => '2026-11-03T15:04:05+00:00', 'last_message_at' => '2026-11-03T15:04:05+00:00', 'resolved_at' => null, 'closed_at' => null]],
            'Message' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
                'ticket_id' => ['type' => 'integer'],
                'author' => ['type' => 'string', 'enum' => ['customer', 'agent']],
                'body' => ['type' => 'string', 'description' => 'Texto plano.'],
                'created_at' => $date,
            ], 'example' => ['id' => 310, 'ticket_id' => 42, 'author' => 'customer', 'body' => 'Hice el pedido hace una semana y no ha llegado.', 'created_at' => '2026-11-03T15:04:05+00:00']],
            'Customer' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'email' => ['type' => ['string', 'null']],
                'created_at' => $date,
            ], 'example' => ['id' => 15, 'name' => 'Marisol Quintero', 'email' => 'marisol@cliente-ejemplo.com', 'created_at' => '2026-11-03T15:04:05+00:00']],
            'Agent' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'role' => ['type' => 'string', 'enum' => ['admin', 'agent']],
            ], 'example' => ['id' => 3, 'name' => 'Laura Gómez', 'role' => 'agent']],
            'Error' => ['type' => 'object', 'properties' => ['error' => ['type' => 'object', 'properties' => [
                'code' => ['type' => 'string', 'enum' => ['validation_failed', 'plan_limit', 'unauthenticated', 'forbidden', 'not_found', 'method_not_allowed', 'rate_limited', 'conflict', 'bad_request', 'payload_too_large', 'unsupported_media_type', 'server_error', 'idempotency_key_invalid', 'idempotency_conflict', 'idempotency_in_progress']],
                'message' => ['type' => 'string'],
                'details' => ['type' => 'object', 'description' => 'En `validation_failed`: los mensajes por campo.', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'limit' => ['type' => 'string', 'description' => 'En `plan_limit`: qué límite del plan se alcanzó.'],
            ], 'required' => ['code', 'message']]]],
            'TicketCreate' => ['type' => 'object', 'required' => ['subject', 'description'], 'description' => 'Hay que indicar el cliente: `customer_id`, o `customer_name` (con `customer_email` si lo tienes).', 'properties' => [
                'subject' => ['type' => 'string', 'maxLength' => 150, 'description' => 'Una sola línea.'],
                'description' => ['type' => 'string', 'maxLength' => 10000, 'description' => 'Texto plano: el primer mensaje del cliente.'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent'], 'default' => 'normal'],
                'customer_id' => ['type' => 'integer'],
                'customer_name' => ['type' => 'string', 'maxLength' => 120],
                'customer_email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
                'assigned_to' => ['type' => ['integer', 'null']],
            ], 'example' => ['subject' => 'Pedido sin llegar', 'description' => 'Hice el pedido hace una semana y no ha llegado.', 'priority' => 'normal', 'customer_name' => 'Marisol Quintero', 'customer_email' => 'marisol@cliente-ejemplo.com']],
            'TicketUpdate' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => ['open', 'waiting_customer', 'resolved', 'closed']],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                'assigned_to' => ['type' => ['integer', 'null']],
            ], 'example' => ['status' => 'resolved', 'assigned_to' => 3]],
            'MessageCreate' => ['type' => 'object', 'required' => ['body'], 'properties' => [
                'body' => ['type' => 'string', 'maxLength' => 10000, 'description' => 'Texto plano.'],
                'author' => ['type' => 'string', 'enum' => ['customer', 'agent'], 'default' => 'customer'],
            ], 'example' => ['body' => 'Ya enviamos tu pedido.', 'author' => 'agent']],
            'CustomerCreate' => ['type' => 'object', 'required' => ['name'], 'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 120],
                'email' => ['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 255],
            ], 'example' => ['name' => 'Marisol Quintero', 'email' => 'marisol@cliente-ejemplo.com']],
        ];
    }
}
