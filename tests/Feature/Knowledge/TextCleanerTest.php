<?php

declare(strict_types=1);

use App\Knowledge\TextCleaner;

describe('TextCleaner', function (): void {
    it('conserva el texto normal, los acentos, los saltos de línea y las tabulaciones', function (): void {
        $text = "Envío rápido: ñandú, corazón.\n\n\tPárrafo con sangría 😀";

        expect(TextCleaner::clean($text))->toBe($text);
    });

    it('unifica los saltos de línea', function (): void {
        expect(TextCleaner::clean("a\r\nb\rc"))->toBe("a\nb\nc");
    });

    it('quita los caracteres de control, los invisibles y los de dirección del texto', function (string $dirty): void {
        expect(TextCleaner::clean("a{$dirty}b"))->toBe('ab');
    })->with([
        'nulo' => ["\0"],
        'campana' => ["\x07"],
        'escape' => ["\x1B"],
        'borrar' => ["\x7F"],
        'control C1' => ["\u{0085}"],
        'ancho cero' => ["\u{200B}"],
        'unión de ancho cero' => ["\u{200D}"],
        'orden de bytes' => ["\u{FEFF}"],
        'guion blando' => ["\u{00AD}"],
        'dirección izquierda a derecha' => ["\u{200E}"],
        'invierte la dirección' => ["\u{202E}"],
        'aislamiento de dirección' => ["\u{2066}"],
        'etiqueta de Unicode' => ["\u{E0041}"],
        'separador de línea' => ["\u{2028}"],
        'separador de párrafo' => ["\u{2029}"],
    ]);

    it('lleva el texto a la forma canónica para que «é» siempre sea un solo carácter', function (): void {
        expect(TextCleaner::clean("e\u{0301}"))->toBe("\u{00E9}");
    });

    it('un texto que no es UTF-8 válido queda vacío en vez de pasar a medias', function (): void {
        expect(TextCleaner::clean("hola\xC3\x28"))->toBe('');
    });

    it('una línea reduce los espacios y los saltos a un espacio y recorta', function (): void {
        expect(TextCleaner::line("  Hola \n\n  mundo\t ! \u{200B}"))->toBe('Hola mundo !');
    });

    it('sabe si un texto trae algo que se quitaría', function (): void {
        expect(TextCleaner::isClean("Normal\ncon salto"))->toBeTrue()
            ->and(TextCleaner::isClean("Con\u{200B}oculto"))->toBeFalse()
            ->and(TextCleaner::isClean("Con\0nulo"))->toBeFalse()
            ->and(TextCleaner::isClean("hola\xC3\x28"))->toBeFalse();
    });
});
