<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Enmascara datos personales sensibles antes de enviar un texto a un proveedor externo (RS-15). Es deliberadamente
 * conservador: prefiere tapar de más. Tapa tarjetas (solo las que pasan la verificación de Luhn, para no tapar números de
 * pedido), números de documento de identidad que siguen a palabras como «cédula» o «NIT» y secretos (llaves y contraseñas
 * dichas en voz alta). En texto de un cliente tapa además correos y teléfonos de 10 dígitos o con «+». Un número de pedido
 * de 10 dígitos seguidos se confunde con un teléfono y también se tapa: es el precio de no filtrar teléfonos.
 */
class PersonalDataMasker
{
    public function mask(string $text, MaskLevel $level): string
    {
        if ($level === MaskLevel::None || $text === '') {
            return $text;
        }

        $text = $this->secrets($text);
        $text = $this->cards($text);
        $text = $this->documents($text);

        if ($level === MaskLevel::Customer) {
            $text = $this->emails($text);
            $text = $this->phones($text);
        }

        return $text;
    }

    private function secrets(string $text): string
    {
        $text = preg_replace('/\b(?:sk|pk|tk|ghp|gho|xox[abp])[-_][A-Za-z0-9_-]{16,}\b/', '[secreto]', $text) ?? $text;

        return preg_replace('/\b(contraseña|password|clave|token)(\s*(?:es|:|=)\s*)\S+/iu', '$1$2[secreto]', $text) ?? $text;
    }

    private function cards(string $text): string
    {
        return preg_replace_callback('/(?<!\d)(?:\d[ -]?){12,18}\d(?!\d)/', function (array $match): string {
            $digits = preg_replace('/\D/', '', $match[0]) ?? '';

            return strlen($digits) >= 13 && strlen($digits) <= 19 && $this->luhn($digits) ? '[tarjeta]' : $match[0];
        }, $text) ?? $text;
    }

    private function documents(string $text): string
    {
        $keywords = 'c[eé]dula(?:\s+de\s+ciudadan[ií]a|\s+de\s+extranjer[ií]a)?|c\.\s?c\.|cc|nit|dni|pasaporte|documento(?:\s+de\s+identidad)?|identificaci[oó]n|tarjeta\s+de\s+identidad';

        // El número puede llevar hasta tres letras mayúsculas delante (los pasaportes) y puntos o guiones (las cédulas y los NIT).
        return preg_replace('/\b('.$keywords.')(?!\p{L})((?:\s+(?:es|son|número|numero))?[\s:#.\-°noº]*)((?:(?-i:[A-Z]{1,3})[\s-]?)?[0-9][0-9.\s-]{4,14}[0-9])/iu', '$1$2[documento]', $text) ?? $text;
    }

    private function emails(string $text): string
    {
        return preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[correo]', $text) ?? $text;
    }

    private function phones(string $text): string
    {
        // Con indicativo entre paréntesis (601) 123 4567, o diez dígitos seguidos o separados, con «+» de país opcional.
        return preg_replace('/(?<![\w.])(?:(?:\+\d{1,3}[\s.-]?)?\(\d{2,4}\)[\s.-]?\d{3}[\s.-]?\d{4}|(?:\+\d{1,3}[\s.-]?)?\d{3}[\s.-]?\d{3}[\s.-]?\d{4})(?!\d)/', '[teléfono]', $text) ?? $text;
    }

    private function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
