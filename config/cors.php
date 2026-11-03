<?php

declare(strict_types=1);

return [

    /*
    | CORS solo para la API pública. La lista de orígenes es explícita y está vacía por defecto: la API se usa desde
    | servidores, no desde el navegador de un tercero. Quien necesite llamarla desde una página propia agrega su origen
    | exacto en API_ALLOWED_ORIGINS (separados por coma). Nunca un comodín, y nunca con credenciales.
    */
    'paths' => ['api/v1/*'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key'],

    'exposed_headers' => ['Retry-After', 'Location', 'Idempotent-Replayed'],

    'max_age' => 600,

    'supports_credentials' => false,

];
