<?php

declare(strict_types=1);

return [
    'required' => 'Escribe tu :attribute.',
    'string' => 'El campo :attribute no es válido.',
    'email' => 'Escribe un correo válido, por ejemplo nombre@empresa.com.',
    'max' => [
        'string' => 'El campo :attribute no puede superar los :max caracteres.',
    ],
    'min' => [
        'string' => 'La :attribute debe tener al menos :min caracteres.',
    ],
    'confirmed' => 'Las contraseñas no coinciden.',
    'current_password' => 'La contraseña actual no es correcta.',
    'different' => 'La contraseña nueva debe ser distinta de la actual.',
    'unique' => 'Ya existe una cuenta con ese :attribute.',
    'password' => [
        'letters' => 'La :attribute debe incluir al menos una letra.',
        'mixed' => 'La :attribute debe incluir al menos una mayúscula y una minúscula.',
        'numbers' => 'La :attribute debe incluir al menos un número.',
        'symbols' => 'La :attribute debe incluir al menos un símbolo.',
        'uncompromised' => 'Esa :attribute apareció en una filtración de datos. Elige otra.',
    ],
    'attributes' => [
        'email' => 'correo',
        'password' => 'contraseña',
        'current_password' => 'contraseña actual',
        'password_confirmation' => 'confirmación de la contraseña',
        'name' => 'nombre',
    ],
];
