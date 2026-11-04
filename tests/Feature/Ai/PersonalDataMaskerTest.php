<?php

declare(strict_types=1);

use App\Ai\MaskLevel;
use App\Ai\PersonalDataMasker;

function masked(string $text, MaskLevel $level = MaskLevel::Customer): string
{
    return app(PersonalDataMasker::class)->mask($text, $level);
}

describe('tarjetas', function (): void {
    it('tapa una tarjeta válida con o sin separadores', function (string $card): void {
        expect(masked("Mi tarjeta es {$card} y vence en 12/28."))->toBe('Mi tarjeta es [tarjeta] y vence en 12/28.');
    })->with(['4111111111111111', '4111 1111 1111 1111', '4111-1111-1111-1111', '5500 0000 0000 0004', '378282246310005', '3530 1113 3330 0000']);

    it('no tapa un número largo que no pasa la verificación de Luhn, como un número de pedido', function (): void {
        expect(masked('Mi pedido es 4111111111111112, ¿dónde está?', MaskLevel::Company))->toBe('Mi pedido es 4111111111111112, ¿dónde está?');
    });

    it('tapa dos tarjetas en el mismo texto', function (): void {
        expect(masked('4111111111111111 y 5500000000000004'))->toBe('[tarjeta] y [tarjeta]');
    });
});

describe('documentos de identidad', function (): void {
    it('tapa el número que sigue a una palabra de documento', function (string $text, string $expected): void {
        expect(masked($text))->toBe($expected);
    })->with([
        'cédula' => ['Mi cédula es 1.234.567.890', 'Mi cédula es [documento]'],
        'cédula con dos puntos' => ['Cédula: 80123456', 'Cédula: [documento]'],
        'cédula de ciudadanía' => ['cédula de ciudadanía 1032456789', 'cédula de ciudadanía [documento]'],
        'cc con puntos' => ['C.C. 52.123.456', 'C.C. [documento]'],
        'nit' => ['NIT 900.123.456-7', 'NIT [documento]'],
        'pasaporte' => ['pasaporte AB 1234567', 'pasaporte [documento]'],
        'documento de identidad' => ['documento de identidad 12345678', 'documento de identidad [documento]'],
        'dni' => ['DNI: 12345678', 'DNI: [documento]'],
    ]);

    it('no tapa un número que no sigue a una palabra de documento', function (): void {
        expect(masked('Pedido 12345678 en camino.', MaskLevel::Company))->toBe('Pedido 12345678 en camino.');
    });
});

describe('secretos', function (): void {
    it('tapa llaves con forma de llave y contraseñas dichas en voz alta', function (string $text, string $expected): void {
        expect(masked($text, MaskLevel::Company))->toBe($expected);
    })->with([
        'llave de API de TikaroChat' => ['usé tk_21_Sj1Jf8oSmeAhl1AlnV54v9yagkH3RBlyYIGe7zKl', 'usé [secreto]'],
        'llave sk' => ['mi llave sk-fe4b655dcc4848f28961e8b1a6f4bb3c falló', 'mi llave [secreto] falló'],
        'contraseña con dos puntos' => ['mi contraseña: Hunter2!!', 'mi contraseña: [secreto]'],
        'password con es' => ['el password es abc123', 'el password es [secreto]'],
        'token igual' => ['token=abcabcabc', 'token=[secreto]'],
    ]);
});

describe('correos y teléfonos', function (): void {
    it('en texto de un cliente tapa correos y teléfonos de 10 dígitos o con más', function (string $text, string $expected): void {
        expect(masked($text))->toBe($expected);
    })->with([
        'correo' => ['Escríbeme a ana.perez+tienda@correo-ejemplo.com.co por favor', 'Escríbeme a [correo] por favor'],
        'celular pegado' => ['Llámame al 3001234567', 'Llámame al [teléfono]'],
        'celular con espacios' => ['Mi cel 300 123 4567', 'Mi cel [teléfono]'],
        'celular con guiones' => ['300-123-4567', '[teléfono]'],
        'con prefijo' => ['+57 300 123 4567', '[teléfono]'],
        'con paréntesis' => ['(601) 123 4567', '[teléfono]'],
    ]);

    it('en texto de la empresa conserva su correo y su teléfono de contacto, que la IA sí debe poder citar', function (): void {
        expect(masked('Escríbenos a soporte@tienda.com o llama al 3001234567.', MaskLevel::Company))->toBe('Escríbenos a soporte@tienda.com o llama al 3001234567.');
    });

    it('un número de pedido corto no se confunde con un teléfono', function (): void {
        expect(masked('Pedido #48213 y guía 123456789'))->toBe('Pedido #48213 y guía 123456789');
    });
});

describe('niveles', function (): void {
    it('sin nivel no toca nada, ni siquiera lo sensible', function (): void {
        expect(masked('4111111111111111 3001234567', MaskLevel::None))->toBe('4111111111111111 3001234567');
    });

    it('el texto vacío o sin datos queda igual', function (): void {
        expect(masked(''))->toBe('')->and(masked('Hola, ¿cuánto tarda el envío a Medellín?'))->toBe('Hola, ¿cuánto tarda el envío a Medellín?');
    });

    it('combina varios datos en un mismo mensaje', function (): void {
        $text = 'Soy Ana, cédula 52.123.456, correo ana@correo.com, cel 3001234567, tarjeta 4111 1111 1111 1111.';

        expect(masked($text))->toBe('Soy Ana, cédula [documento], correo [correo], cel [teléfono], tarjeta [tarjeta].');
    });
});
