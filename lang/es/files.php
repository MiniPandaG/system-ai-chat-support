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
        'attachments_too_many' => 'Un mensaje admite hasta :max archivos adjuntos.',
        'attachment_type' => 'Este tipo de archivo no se admite. Usa PNG, JPEG, WebP, PDF, TXT, CSV, DOCX o XLSX.',
        'attachment_too_large' => 'Cada archivo puede pesar hasta :max MB.',
        'attachment_corrupt' => 'No pudimos leer este archivo. Prueba con otro.',
        'attachment_text_invalid' => 'Este archivo de texto contiene datos que no son texto. Guárdalo de nuevo como texto plano.',
        'attachment_office_invalid' => 'No pudimos leer este documento de Office. Ábrelo y guárdalo de nuevo.',
        'knowledge_type' => 'Solo se admiten documentos PDF, TXT o Markdown.',
        'knowledge_corrupt' => 'No pudimos leer este PDF. Prueba con otro archivo.',
        'knowledge_text_invalid' => 'Este archivo de texto contiene datos que no son texto. Guárdalo de nuevo como texto plano.',
        'knowledge_text_encoding' => 'Este archivo de texto no está en UTF-8. Guárdalo de nuevo con esa codificación.',
        'attachment_office_macros' => 'Los documentos con macros o con contenido no admitido no se pueden adjuntar. Guárdalo sin macros.',
    ],
];
