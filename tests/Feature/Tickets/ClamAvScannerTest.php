<?php

declare(strict_types=1);

use App\Attachments\ClamAvScanner;
use App\Attachments\ScannerUnavailable;

/*
 * El protocolo de ClamAV se prueba con un par de sockets conectados en memoria: la prueba hace de demonio. Con el
 * demonio de verdad se comprueba aparte (ver docs/ESTADO.md): aquí se fija qué se envía y cómo se entiende cada respuesta.
 */

/** @return array{0: resource, 1: resource} El extremo del cliente y el del «demonio». */
function socketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    assert($pair !== false);

    return [$pair[0], $pair[1]];
}

/** @return resource */
function memoryStream(string $content)
{
    $handle = fopen('php://memory', 'w+b');
    assert($handle !== false);
    fwrite($handle, $content);
    rewind($handle);

    return $handle;
}

/** Lo que el cliente envió al «demonio» hasta ahora. */
function receivedByDaemon($daemon): string
{
    stream_set_blocking($daemon, false);

    return (string) stream_get_contents($daemon);
}

function scannerOver($client, int $timeout = 2): ClamAvScanner
{
    return new ClamAvScanner('antivirus', 3310, $timeout, fn () => $client);
}

describe('lo que se envía', function (): void {
    it('manda el comando, el archivo en trozos con su largo delante y un trozo final vacío', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: OK\0");

        scannerOver($client)->scan(memoryStream('hola'));

        expect(receivedByDaemon($daemon))->toBe("zINSTREAM\0".pack('N', 4).'hola'.pack('N', 0));
    });

    it('un archivo grande se parte en trozos de 64 KB', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: OK\0");
        $content = str_repeat('x', 65536 * 2 + 1000);

        scannerOver($client)->scan(memoryStream($content));

        $sent = receivedByDaemon($daemon);
        expect($sent)->toBe("zINSTREAM\0".pack('N', 65536).str_repeat('x', 65536).pack('N', 65536).str_repeat('x', 65536).pack('N', 1000).str_repeat('x', 1000).pack('N', 0));
    });

    it('un archivo vacío envía solo el comando y el cierre', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: OK\0");

        scannerOver($client)->scan(memoryStream(''));

        expect(receivedByDaemon($daemon))->toBe("zINSTREAM\0".pack('N', 0));
    });

    it('cierra la conexión al terminar', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: OK\0");

        scannerOver($client)->scan(memoryStream('x'));

        expect(is_resource($client))->toBeFalse();
    });
});

describe('cómo se entiende la respuesta', function (): void {
    it('«OK» es un archivo limpio', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: OK\0");

        $result = scannerOver($client)->scan(memoryStream('hola'));

        expect($result->clean)->toBeTrue()->and($result->signature)->toBeNull();
    });

    it('«FOUND» es una amenaza, con el nombre que da el antivirus', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: Eicar-Test-Signature FOUND\0");

        $result = scannerOver($client)->scan(memoryStream('x'));

        expect($result->clean)->toBeFalse()->and($result->signature)->toBe('Eicar-Test-Signature');
    });

    it('un nombre de amenaza con espacios y puntos se conserva', function (): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, "stream: Win.Test.EICAR_HDB-1 FOUND\0");

        expect(scannerOver($client)->scan(memoryStream('x'))->signature)->toBe('Win.Test.EICAR_HDB-1');
    });

    it('una respuesta rara que dice «FOUND» se trata como amenaza: ante la duda, se bloquea', function (string $reply): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, $reply);

        expect(scannerOver($client)->scan(memoryStream('x'))->clean)->toBeFalse();
    })->with([
        'OK con FOUND detrás' => ["stream: OK FOUND\0"],
    ]);

    it('nada que no sea una respuesta clara cuenta como limpio', function (string $reply): void {
        [$client, $daemon] = socketPair();
        fwrite($daemon, $reply);

        expect(fn () => scannerOver($client)->scan(memoryStream('x')))->toThrow(ScannerUnavailable::class);
    })->with([
        'error del demonio' => ["INSTREAM size limit exceeded. ERROR\0"],
        'error con prefijo' => ["stream: Can't open file. ERROR\0"],
        'basura' => ["lo que sea\0"],
        'OK de otra cosa' => ["stream: OKAY\0"],
        'amenaza sin nombre' => ["stream:  FOUND\0"],
        'amenaza con caracteres de control' => ["stream: Mala\x01 FOUND\0"],
        'respuesta vacía' => ["\0"],
        'parece limpio pero con otro texto' => ["stream: ok\0"],
        'resultado de otro comando' => ["PONG\0"],
    ]);
});

describe('cuando el antivirus falla', function (): void {
    it('si no contesta a tiempo, no hay respuesta y no se da por limpio', function (): void {
        [$client] = socketPair();
        $started = microtime(true);

        expect(fn () => scannerOver($client, 1)->scan(memoryStream('x')))->toThrow(ScannerUnavailable::class);
        expect(microtime(true) - $started)->toBeLessThan(5.0);
    });

    it('si cierra la conexión antes de recibir el archivo, no se da por limpio', function (): void {
        [$client, $daemon] = socketPair();
        fclose($daemon);

        expect(fn () => scannerOver($client)->scan(memoryStream(str_repeat('x', 100000))))->toThrow(ScannerUnavailable::class);
    });

    it('si no se puede abrir la conexión, tampoco', function (Closure $connector): void {
        $scanner = new ClamAvScanner('antivirus', 3310, 1, $connector);

        expect(fn () => $scanner->scan(memoryStream('x')))->toThrow(ScannerUnavailable::class);
    })->with([
        'sin conexión' => [fn () => false],
        'con aviso de PHP' => [function (): never {
            throw new ErrorException('Connection refused');
        }],
    ]);

    it('con un demonio que no existe en la red real, falla rápido y sin dar nada por limpio', function (): void {
        $started = microtime(true);

        expect(fn () => (new ClamAvScanner('127.0.0.1', 1, 2))->scan(memoryStream('x')))->toThrow(ScannerUnavailable::class);
        expect(microtime(true) - $started)->toBeLessThan(5.0);
    });

    it('con un nombre de equipo que no se resuelve, también', function (): void {
        expect(fn () => (new ClamAvScanner('no-existe.invalid', 3310, 2))->scan(memoryStream('x')))->toThrow(ScannerUnavailable::class);
    });
});
