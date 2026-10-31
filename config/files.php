<?php

declare(strict_types=1);

return [

    /*
    | Disco donde se guardan los archivos de las empresas. Debe ser privado: nunca el disco público. Cambiarlo a un
    | disco compatible con S3 no requiere tocar el código.
    */
    'disk' => env('FILES_DISK', 'local'),

    /* Minutos que dura una URL firmada de descarga. */
    'url_ttl_minutes' => 5,

    /* Imágenes (logos): límites que se comprueban antes de decodificar. */
    'image' => [
        'max_bytes' => 2 * 1024 * 1024,
        'min_side' => 64,
        // 2048 px por lado son 16 MB de imagen decodificada: cabe holgado en la memoria de PHP y sobra para un logo.
        'max_side' => 2048,
    ],

    /* Subidas que una empresa puede hacer por hora. */
    'uploads_per_hour' => 10,
];
