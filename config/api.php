<?php

declare(strict_types=1);

return [

    // Límites de peticiones de la API (RS-07). Las ventanas están en `ApiServiceProvider`.
    'limits' => [
        'per_key_per_minute' => (int) env('API_LIMIT_PER_KEY', 120),
        'per_ip_per_minute' => (int) env('API_LIMIT_PER_IP', 300),
        // Crear tickets, clientes o mensajes pesa más que leer.
        'writes_per_key_per_minute' => (int) env('API_LIMIT_WRITES_PER_KEY', 30),
        // Intentos con una llave que no existe, caducada o revocada, por dirección: frena a quien prueba llaves.
        'failed_auth_per_ip_per_minute' => (int) env('API_LIMIT_FAILED_AUTH', 20),
    ],

    'pagination' => [
        'default' => 25,
        'max' => 100,
    ],

    'idempotency' => [
        // Cuánto tiempo se recuerda una clave de idempotencia.
        'ttl_hours' => 24,
    ],

];
