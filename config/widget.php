<?php

declare(strict_types=1);

return [

    'session' => [
        // La sesión caduca tras tantas horas sin actividad y, pase lo que pase, a los tantos días de creada.
        'idle_hours' => (int) env('WIDGET_SESSION_IDLE_HOURS', 24),
        'max_days' => (int) env('WIDGET_SESSION_MAX_DAYS', 7),
    ],

    // Lo que el visitante puede escribir.
    'message_max_length' => 2000,
    'name_max_length' => 80,
    'email_max_length' => 160,

    // Los mensajes que se devuelven al abrir la conversación (los más recientes).
    'conversation_limit' => 200,

    // Orígenes que una empresa puede registrar.
    'max_origins' => 10,

    // Abuso (RS-07). Las ventanas están en el proveedor de servicios del widget.
    'limits' => [
        'start_per_ip_per_hour' => (int) env('WIDGET_START_PER_IP_PER_HOUR', 10),
        'start_per_key_per_hour' => (int) env('WIDGET_START_PER_KEY_PER_HOUR', 300),
        'tickets_per_session_per_day' => 3,
        'tickets_per_ip_per_day' => 10,
        'messages_per_session_per_minute' => 20,
        'messages_per_ip_per_minute' => 60,
        'frames_per_ip_per_minute' => 60,
        'reads_per_session_per_minute' => 120,
        'contact_per_session_per_hour' => 5,
        'auth_per_session_per_minute' => 30,
    ],

];
