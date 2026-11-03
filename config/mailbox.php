<?php

declare(strict_types=1);

return [

    /*
    | El buzón que recibe las respuestas de los clientes. Es uno solo, el de la plataforma (el dominio remitente propio de
    | cada empresa llega en la fase 2). Sin servidor configurado no se lee nada y el resto de la aplicación sigue igual.
    | Las credenciales solo viajan por variables de entorno.
    */
    'imap' => [
        'host' => env('MAILBOX_IMAP_HOST'),
        'port' => (int) env('MAILBOX_IMAP_PORT', 993),
        // «ssl», «tls» o «starttls»: sin cifrado no se admite fuera de local (ver MailboxReader).
        'encryption' => env('MAILBOX_IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => (bool) env('MAILBOX_IMAP_VALIDATE_CERT', true),
        'username' => env('MAILBOX_IMAP_USERNAME'),
        'password' => env('MAILBOX_IMAP_PASSWORD'),
        'folder' => env('MAILBOX_IMAP_FOLDER', 'INBOX'),
    ],

    'fetch' => [
        // Correos que se leen por vuelta y espera entre vueltas del comando `mail:fetch --loop`.
        'batch' => 50,
        'interval_seconds' => (int) env('MAILBOX_FETCH_INTERVAL', 15),
        // Un correo que falla al procesarse se reintenta tantas veces; después se descarta con su motivo.
        'max_attempts' => 3,
    ],

    'inbound' => [
        // Lo más largo que se guarda del texto de un correo (el tope de un mensaje del ticket).
        'max_body_chars' => 10000,
        // Correos aceptados por ticket y hora: una inundación no llena la conversación.
        'per_ticket_per_hour' => 30,
        // Nada por encima de este peso se descarga: cinco adjuntos de 10 MB más el texto.
        'max_bytes' => 56 * 1024 * 1024,
    ],

    'outbound' => [
        // Confirmaciones de «ticket recibido» a una misma dirección: nadie puede usar el chat para llenar la bandeja de otra persona.
        'received_per_address_per_day' => 3,
    ],

];
