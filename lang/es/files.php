<?php

declare(strict_types=1);

return [
    'rejected' => [
        'upload_failed' => 'No pudimos recibir el archivo. Inténtalo de nuevo.',
        'empty' => 'El archivo está vacío.',
        'too_large' => 'Este archivo pesa :size MB; el máximo es :max MB.',
        'unsupported_type' => 'Solo se admiten imágenes PNG, JPEG o WebP. Los SVG y otros formatos no se admiten.',
        'extension_mismatch' => 'La extensión del archivo no corresponde a lo que contiene. Guárdalo de nuevo con su formato real.',
        'corrupt' => 'No pudimos leer esta imagen. Prueba con otro archivo.',
        'dimensions_large' => 'La imagen mide :width×:height px; el máximo es :max×:max px.',
        'dimensions_small' => 'La imagen mide :width×:height px; el mínimo es :min×:min px.',
        'animated' => 'Las imágenes animadas no se admiten. Usa una imagen fija.',
        'rate_limited' => 'Subiste demasiados archivos. Vuelve a intentarlo en :minutes minutos.',
    ],
];
