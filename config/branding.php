<?php

declare(strict_types=1);

return [

    /*
    | Los diez colores que una empresa puede cambiar y sus valores de fábrica (naranja sobre fondo oscuro). Deben
    | coincidir con los de resources/tokens/tokens.css: una prueba lo comprueba.
    */
    'defaults' => [
        'primary' => '#F65F1E',
        'secondary' => '#2E5EAA',
        'accent' => '#2BAFBF',
        'background' => '#15110D',
        'surface' => '#1D1813',
        'text' => '#F6F1EA',
        'success' => '#35A373',
        'warning' => '#E0A526',
        'danger' => '#C83B3F',
        'info' => '#2E6FB0',
    ],

    /* Texto que se pone sobre un color: el sistema elige el de más contraste. Nunca blanco ni negro puro. */
    'foreground_light' => '#F6F1EA',
    'foreground_dark' => '#1D1208',

    /* Contraste mínimo WCAG 2.1 AA: texto normal y elementos de interfaz (foco, bordes, enlaces). */
    'min_text_contrast' => 4.5,
    'min_ui_contrast' => 3.0,

    /* El panel siempre abre en oscuro: el primario también debe verse sobre este fondo y esta superficie. */
    'panel_background' => '#15110D',
    'panel_surface' => '#1D1813',

    /* Ajuste automático de un color: pasos de luminosidad (puntos porcentuales) y máximo de pasos. */
    'adjust_step' => 2.0,
    'adjust_max_steps' => 50,
];
