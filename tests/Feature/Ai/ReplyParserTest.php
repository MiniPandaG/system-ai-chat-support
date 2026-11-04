<?php

declare(strict_types=1);

use App\Ai\Reply\InvalidReply;
use App\Ai\Reply\ReplyParser;

function parsedReply(array|string $payload, string $allowed = 'Devoluciones hasta 30 días.', int $chunks = 2): mixed
{
    return app(ReplyParser::class)->parse(is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE), $allowed, $chunks);
}

function replyData(array $overrides = []): array
{
    return ['puede_responder' => true, 'necesita_humano' => false, 'respuesta' => 'Tienes 30 días.', 'fuentes' => [1], ...$overrides];
}

describe('la forma de la respuesta', function (): void {
    it('acepta un objeto con las cuatro claves', function (): void {
        $reply = parsedReply(replyData());

        expect($reply->canAnswer)->toBeTrue()->and($reply->needsHuman)->toBeFalse()->and($reply->answer)->toBe('Tienes 30 días.')->and($reply->sources)->toBe([1]);
    });

    it('acepta el JSON envuelto en un bloque de código', function (): void {
        expect(parsedReply("```json\n".json_encode(replyData())."\n```")->answer)->toBe('Tienes 30 días.');
    });

    it('rechaza lo que no es un objeto JSON o no tiene la forma exacta', function (mixed $payload): void {
        expect(fn () => parsedReply($payload))->toThrow(InvalidReply::class);
    })->with([
        'texto suelto' => ['Claro, tienes 30 días.'],
        'JSON roto' => ['{"puede_responder": true'],
        'lista' => ['[1,2,3]'],
        'un número' => ['42'],
        'sin clave' => [['puede_responder' => true, 'respuesta' => 'x', 'fuentes' => [1]]],
        'booleano como texto' => [replyData(['puede_responder' => 'true'])],
        'respuesta no es texto' => [replyData(['respuesta' => ['a']])],
        'fuentes no es lista' => [replyData(['fuentes' => 'uno'])],
        'dice poder y no hay texto' => [replyData(['respuesta' => '   '])],
        'solo etiquetas' => [replyData(['respuesta' => '<b></b>'])],
    ]);

    it('rechaza una respuesta más larga que el tope', function (): void {
        config(['ai.reply.max_answer_chars' => 50]);

        expect(fn () => parsedReply(replyData(['respuesta' => str_repeat('a', 51)])))->toThrow(InvalidReply::class);
    });

    it('cuando no puede responder, no hay texto aunque el modelo lo mande', function (): void {
        $reply = parsedReply(replyData(['puede_responder' => false, 'respuesta' => 'Quizá sean 60 días']));

        expect($reply->canAnswer)->toBeFalse()->and($reply->answer)->toBe('');
    });
});

describe('los fragmentos citados', function (): void {
    it('descarta los números que no existían y repetidos, y no inventa fuentes', function (): void {
        $reply = parsedReply(replyData(['fuentes' => [2, 2, 7, 0, -1, '1', 1.5, null, 1]]));

        expect($reply->sources)->toBe([2, 1]);
    });
});

describe('la limpieza del texto', function (): void {
    it('quita HTML, caracteres invisibles y enlaces de markdown', function (): void {
        $reply = parsedReply(replyData(['respuesta' => "Hola <script>alert(1)</script><b>amigo</b>\u{200B}. Mira [aquí](http://malo.example/x) los pasos."]));

        expect($reply->answer)->toBe('Hola alert(1)amigo. Mira aquí los pasos.');
    });

    it('conserva solo los enlaces y correos que estaban en los datos', function (): void {
        $allowed = 'Escríbenos a soporte@tienda.com o visita https://tienda.com/ayuda.';
        $reply = parsedReply(replyData(['respuesta' => 'Visita https://tienda.com/ayuda o https://phishing.example/login, o escribe a soporte@tienda.com o a falso@otra.com.']), $allowed);

        expect($reply->answer)->toBe('Visita https://tienda.com/ayuda o , o escribe a soporte@tienda.com o a .');
    });

    it('un enlace que solo empieza igual que uno permitido no pasa', function (string $url): void {
        $reply = parsedReply(replyData(['respuesta' => "Ve a {$url} ya."]), 'Sitio: https://tienda.com/ayuda');

        expect($reply->answer)->toBe('Ve a ya.');
    })->with(['https://tienda.com.malo.example/ayuda', 'https://tienda.com.co/ayuda', 'https://tienda.community']);

    it('el punto final de la frase no cuenta como parte del enlace', function (): void {
        $reply = parsedReply(replyData(['respuesta' => 'Entra a https://tienda.com/ayuda.']), 'https://tienda.com/ayuda');

        expect($reply->answer)->toBe('Entra a https://tienda.com/ayuda.');
    });

    it('las direcciones se comparan sin distinguir mayúsculas', function (): void {
        $reply = parsedReply(replyData(['respuesta' => 'Escribe a Soporte@Tienda.com']), 'soporte@tienda.com');

        expect($reply->answer)->toBe('Escribe a Soporte@Tienda.com');
    });

    it('junta líneas en blanco de más', function (): void {
        expect(parsedReply(replyData(['respuesta' => "Uno.\n\n\n\n\nDos.   \nTres."]))->answer)->toBe("Uno.\n\nDos.\nTres.");
    });
});

describe('lo que el modelo copia de los datos escapados', function (): void {
    it('deshace las entidades de texto y aun así quita las etiquetas que escondían', function (): void {
        $reply = parsedReply(replyData(['respuesta' => 'Ropa &amp; calzado: &lt;b&gt;30 días&lt;/b&gt; &lt;img src=x onerror=alert(1)&gt;para devolver &quot;todo&quot;.']));

        expect($reply->answer)->toBe('Ropa & calzado: 30 días para devolver "todo".');
    });

    it('una entidad doble solo se deshace una vez y no puede armar una etiqueta', function (): void {
        $reply = parsedReply(replyData(['respuesta' => 'Mira &amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt; esto']));

        expect($reply->answer)->toBe('Mira &lt;script&gt;alert(1)&lt;/script&gt; esto')->and($reply->answer)->not->toContain('<');
    });
});
