<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/account',

    'prefix' => '',

    'domain' => null,

    // login-ip limita por IP a quien prueba muchos correos; el bloqueo por correo lo hace LoginThrottle.
    'middleware' => ['web', 'throttle:login-ip'],

    // Con un limitador con nombre aquí, Fortify omite su comprobación de bloqueo por correo; debe quedar en null.
    'limiters' => [
        'login' => null,
        'two-factor' => 'two-factor',
    ],

    'views' => true,

    // Registro, perfil y llaves de acceso no se usan; las funciones se activan módulo a módulo.
    'features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],

];
