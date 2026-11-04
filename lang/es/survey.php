<?php

declare(strict_types=1);

return [
    'page' => [
        'heading' => '¿Cómo te fue con la atención de :company?',
        'detail' => 'Tu respuesta ayuda al equipo a mejorar. Toma menos de un minuto.',
        'score_legend' => 'Calificación',
        'scale' => ['1' => 'Muy mala', '2' => 'Mala', '3' => 'Regular', '4' => 'Buena', '5' => 'Excelente'],
        'comment' => 'Comentario (opcional)',
        'comment_hint' => 'Cuéntanos qué salió bien o qué podemos mejorar. No escribas contraseñas ni números de tarjeta.',
        'submit' => 'Enviar respuesta',
        'submitting' => 'Enviando…',
        'invalid_title' => 'Este enlace ya no sirve',
        'invalid' => 'La encuesta ya se respondió o el enlace venció. Si todavía necesitas ayuda, responde al correo de tu ticket.',
        'thanks_title' => 'Gracias por tu respuesta',
        'thanks' => 'Tu opinión ya está con el equipo.',
    ],
    'errors' => [
        'score' => 'Elige una calificación del 1 al 5.',
        'comment' => 'El comentario puede tener hasta :max caracteres.',
    ],
];
