<?php

declare(strict_types=1);

return [

    /* Peso máximo de un adjunto, en bytes. El límite de PHP y de nginx se ajustan a este (docker/php/php.ini y docker/nginx). */
    'max_bytes' => 10 * 1024 * 1024,

    /* Adjuntos que admite un mensaje. */
    'max_per_message' => 5,

    /* Adjuntos que una persona puede subir por hora. */
    'uploads_per_hour' => 60,

    /* Minutos que dura una URL firmada de descarga (se redondea a ventanas, como la de los logos). */
    'url_ttl_minutes' => 5,

    /*
    | Lo que se admite: extensión → tipos reales que puede tener el contenido. El tipo sale del contenido, nunca de lo
    | que declara el navegador. Los archivos de Office son ZIP; su estructura se comprueba aparte.
    */
    'types' => [
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ],

    /*
    | Quién revisa los archivos. «clamav» es lo único que se acepta fuera de las pruebas: si no se puede revisar un
    | archivo, no se entrega. «fake» solo existe para pruebas e integración continua y la aplicación se niega a
    | arrancar con él en producción.
    */
    'scanner' => env('ATTACHMENT_SCANNER', 'clamav'),

    'clamav' => [
        'host' => env('CLAMAV_HOST', 'clamav'),
        'port' => env('CLAMAV_PORT', 3310),
        'timeout' => 30,
    ],
];
