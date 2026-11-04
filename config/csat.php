<?php

declare(strict_types=1);

return [
    /* Cuántos días sirve el enlace de la encuesta desde que el ticket se resuelve o se cierra. */
    'expires_days' => 14,

    'comment_max' => 1000,

    'email' => [
        /* Encuestas a una misma dirección por día: nadie puede usar el chat para llenar la bandeja de otra persona. */
        'per_address_per_day' => 3,
    ],

    'limits' => [
        'show_per_ip_per_minute' => 30,
        'answer_per_ip_per_minute' => 10,
    ],
];
