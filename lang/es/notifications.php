<?php

declare(strict_types=1);

return [
    'greeting' => 'Hola, :name.',
    'salutation' => 'El equipo de GeckoChat',

    'reset' => [
        'subject' => 'Restablece tu contraseña de GeckoChat',
        'intro' => 'Recibimos una solicitud para restablecer la contraseña de tu cuenta.',
        'action' => 'Restablecer contraseña',
        'expire' => 'El enlace funciona una sola vez y caduca en :minutes minutos.',
        'ignore' => 'Si no la pediste, ignora este correo: tu contraseña no cambia.',
    ],

    'password_changed' => [
        'subject' => 'Cambiaste tu contraseña de GeckoChat',
        'intro' => 'La contraseña de tu cuenta se cambió y cerramos tus otras sesiones abiertas.',
        'warning' => 'Si no fuiste tú, restablece tu contraseña ahora: quien la cambió tiene acceso a tu cuenta.',
        'action' => 'Restablecer contraseña',
    ],

    'invitation' => [
        'subject' => 'Te invitaron a :company en GeckoChat',
        'greeting' => 'Hola.',
        'intro' => 'Te invitaron a unirte a :company en GeckoChat con el rol de :role.',
        'action' => 'Aceptar la invitación',
        'expire' => 'El enlace funciona una sola vez y caduca en :hours horas.',
        'ignore' => 'Si no esperabas esta invitación, ignora este correo: no se crea ninguna cuenta.',
    ],

    'verify' => [
        'subject' => 'Verifica tu correo en GeckoChat',
        'intro' => 'Confirma que este correo es tuyo para empezar a usar tu cuenta.',
        'action' => 'Verificar correo',
        'expire' => 'El enlace caduca en :minutes minutos.',
        'ignore' => 'Si no creaste esta cuenta, ignora este correo.',
    ],
];
