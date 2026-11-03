<?php

declare(strict_types=1);

namespace App\Mail\Inbound;

/**
 * Saca de un correo entrante solo lo que el cliente escribió, como texto plano: nunca se guarda HTML. Si el correo solo
 * trae HTML, se convierte a texto quitando lo ejecutable y las etiquetas; el texto de un adjunto o de una cita anterior
 * no entra. El resultado no lleva caracteres de control (salvo saltos de línea y tabulaciones) y nunca pasa del tope.
 */
final class ReplyText
{
    /** Donde empieza la cita del mensaje anterior: las líneas de ahí en adelante no son del cliente. */
    private const QUOTE_START = [
        '/^\s*(on|el)\b.{0,300}\b(wrote|escribi[oó]|ha escrito)\s*:?\s*$/iu',
        '/^\s*-{2,}\s*(original message|mensaje original|forwarded message|mensaje reenviado)\s*-{2,}\s*$/iu',
        '/^\s*_{10,}\s*$/u',
    ];

    public static function from(string $text, string $html, int $max): string
    {
        $plain = trim($text) !== '' ? $text : self::htmlToText($html);
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $plain = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $plain);
        $plain = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $plain);

        $lines = explode("\n", $plain);
        $kept = [];

        foreach ($lines as $index => $line) {
            // La firma («-- ») y todo lo que sigue no se guarda.
            if (rtrim($line) === '--') {
                break;
            }

            // La cabecera de la cita a veces se parte en dos líneas.
            $pair = $line.' '.($lines[$index + 1] ?? '');

            if (self::startsQuote($line) || self::startsQuote($pair)) {
                break;
            }

            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            $kept[] = rtrim($line);
        }

        $clean = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)));

        return mb_substr($clean, 0, $max);
    }

    private static function startsQuote(string $line): bool
    {
        foreach (self::QUOTE_START as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function htmlToText(string $html): string
    {
        $withoutActive = (string) preg_replace('#<(script|style|head|title|template|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $withBreaks = (string) preg_replace('#<\s*(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $withoutActive);

        return html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
