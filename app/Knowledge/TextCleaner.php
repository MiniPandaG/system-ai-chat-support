<?php

declare(strict_types=1);

namespace App\Knowledge;

use Normalizer;

/**
 * Limpia todo texto que entra a la base de conocimiento. El contenido es no confiable (RS-14): además de caracteres de
 * control se quitan los invisibles y de formato (ancho cero, dirección del texto, la plana de etiquetas de Unicode),
 * que sirven para esconder instrucciones a un modelo dentro de un texto que una persona lee como inofensivo.
 */
final class TextCleaner
{
    /** Cc salvo salto de línea y tabulación; Cf (invisibles y de dirección); separadores de línea y de párrafo. */
    private const UNWANTED = '/[^\P{Cc}\n\t]|\p{Cf}|[\x{2028}\x{2029}]/u';

    /** Texto de varias líneas: saltos de línea unificados, forma canónica y sin invisibles. */
    public static function clean(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return '';
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
        $subject = is_string($normalized) ? $normalized : $text;

        return preg_replace(self::UNWANTED, '', $subject) ?? '';
    }

    /** Texto de una sola línea: lo anterior, con los espacios y saltos reducidos a un espacio. */
    public static function line(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::clean($text)) ?? '');
    }

    /** ¿Trae algo que `clean` quitaría? Sirve para avisar y para pruebas. */
    public static function isClean(string $text): bool
    {
        return mb_check_encoding($text, 'UTF-8') && preg_match(self::UNWANTED, str_replace("\r", '', $text)) === 0;
    }
}
