<?php

declare(strict_types=1);

return [
    'heading' => 'Base del proyecto en marcha',
    'detail' => 'Laravel, Inertia, React y Tailwind responden. Esta página solo comprueba el entorno y los tokens de diseño; se reemplaza al construir el módulo de autenticación.',

    'palette' => [
        'title' => 'Paleta',
        'detail' => 'Cada color es un token semántico. Una empresa sobrescribe estos valores desde su branding y todo lo demás se recalcula.',
        'brand' => 'Marca y estados',
        'surfaces' => 'Superficies y tintes',
        'text' => 'Niveles de texto',
        'text_levels' => [
            'primary' => 'Texto principal: título de un ticket',
            'secondary' => 'Texto secundario: cliente y canal',
            'tertiary' => 'Texto terciario: hora de la última respuesta',
            'disabled' => 'Texto deshabilitado: acción no disponible',
        ],
    ],

    'typography' => [
        'title' => 'Tipografía',
        'detail' => 'Space Grotesk para títulos, Work Sans para la interfaz e IBM Plex Mono para identificadores y claves.',
        'display_sample' => '3 tickets sin responder hace más de 4 horas',
        'body_sample' => 'Ningún ticket coincide con estos filtros. Quita un filtro para ver más resultados.',
        'mono_sample' => 'TK-1042 · prod-backend-01',
        'scale' => 'Escala de tamaños',
        'scale_sample' => 'El mensaje no se envió.',
    ],

    'shape' => [
        'title' => 'Radios y sombras',
        'detail' => 'Los radios cambian según el elemento y la sombra solo separa lo que flota sobre el resto.',
        'radii' => 'Radios',
        'shadows' => 'Sombras',
    ],
];
