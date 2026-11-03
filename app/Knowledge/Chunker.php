<?php

declare(strict_types=1);

namespace App\Knowledge;

/**
 * Parte un texto en fragmentos para la búsqueda: cada uno cabe en `size` caracteres, se corta por párrafos y, solo si un
 * párrafo es más largo que eso, por palabras. El inicio de cada fragmento repite el final del anterior (`overlap`) para
 * que una idea cortada a medias no se pierda. No inventa nada: todo lo que sale está en el texto de entrada.
 */
final class Chunker
{
    /**
     * @return list<string>
     */
    public static function split(string $text, int $size, int $overlap): array
    {
        $size = max(50, $size);
        $overlap = max(0, min($overlap, intdiv($size, 3)));

        $units = [];

        foreach (preg_split('/\n{2,}/u', trim($text)) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            foreach (self::fit($paragraph, $size) as $piece) {
                $units[] = $piece;
            }
        }

        $chunks = [];
        $current = '';

        foreach ($units as $unit) {
            if ($current === '') {
                $current = $unit;

                continue;
            }

            if (mb_strlen($current) + 2 + mb_strlen($unit) <= $size) {
                $current .= "\n\n".$unit;

                continue;
            }

            $chunks[] = $current;
            $tail = self::tail($current, $overlap);
            $current = $tail !== '' && mb_strlen($tail) + 2 + mb_strlen($unit) <= $size ? $tail."\n\n".$unit : $unit;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Un párrafo que cabe se queda entero; uno más largo se reparte por palabras en trozos que caben.
     *
     * @return list<string>
     */
    private static function fit(string $paragraph, int $size): array
    {
        if (mb_strlen($paragraph) <= $size) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';

        foreach (preg_split('/\s+/u', $paragraph) ?: [] as $word) {
            // Una palabra más larga que el fragmento (una cadena sin espacios) se corta a la fuerza.
            while (mb_strlen($word) > $size) {
                if ($current !== '') {
                    $pieces[] = $current;
                    $current = '';
                }

                $pieces[] = mb_substr($word, 0, $size);
                $word = mb_substr($word, $size);
            }

            if ($word === '') {
                continue;
            }

            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $size) {
                $current .= ' '.$word;
            } else {
                $pieces[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /** Los últimos `overlap` caracteres, empezando en el inicio de una palabra. */
    private static function tail(string $chunk, int $overlap): string
    {
        if ($overlap === 0 || mb_strlen($chunk) <= $overlap) {
            return '';
        }

        $tail = mb_substr($chunk, -$overlap);

        // Se empieza después del primer espacio para no arrancar el fragmento con media palabra. Los desplazamientos
        // de preg_match son en bytes, y `substr` también.
        if (preg_match('/\s/u', $tail, $match, PREG_OFFSET_CAPTURE) === 1) {
            return trim(substr($tail, $match[0][1]));
        }

        return '';
    }
}
