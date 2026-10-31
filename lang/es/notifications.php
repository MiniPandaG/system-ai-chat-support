<?php

declare(strict_types=1);

return [
    'greeting' => 'Hola, :name.',
    'salutation' => 'El equipo de TikaroChat',

    'reset' => [
        'subject' => 'Restablece tu contraseña de TikaroChat',
        'intro' => 'Recibimos una solicitud para restablecer la contraseña de tu cuenta.',
        'action' => 'Restablecer contraseña',
        'expire' => 'El enlace funciona una sola vez y caduca en :minutes minutos.',
        'ignore' => 'Si no la pediste, ignora este correo: tu contraseña no cambia.',
    ],

    'password_changed' => [
        'subject' => 'Cambiaste tu contraseña de TikaroChat',
        'intro' => 'La contraseña de tu cuenta se cambió y cerramos tus otras sesiones abiertas.',
        'warning' => 'Si no fuiste tú, restablece tu contraseña ahora: quien la cambió tiene acceso a tu cuenta.',
        'action' => 'Restablecer contraseña',
    ],

    'verify' => [
        'subject' => 'Verifica tu correo en TikaroChat',
        'intro' => 'Confirma que este correo es tuyo para empezar a usar tu cuenta.',
        'action' => 'Verificar correo',
        'expire' => 'El enlace caduca en :minutes minutos.',
        'ignore' => 'Si no creaste esta cuenta, ignora este correo.',
    ],
];
