<?php

declare(strict_types=1);

return [

    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', true),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 63072000),
    ],

    // Solo se aplica en el entorno local y mientras corre el servidor de Vite.
    'vite_dev_origins' => [
        'http://127.0.0.1:5173',
        'http://localhost:5173',
        'ws://127.0.0.1:5173',
        'ws://localhost:5173',
    ],

];
