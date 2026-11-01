<?php

declare(strict_types=1);

namespace App\Attachments;

use Closure;
use ErrorException;

/**
 * Cliente mínimo del demonio de ClamAV por su protocolo de red (comando INSTREAM): se envía el archivo en trozos y el
 * demonio contesta una línea. Sin dependencias: son cuatro mensajes sobre un socket. Todo lo que no sea una respuesta
 * clara lanza `ScannerUnavailable`; así un demonio caído o una respuesta rara nunca cuentan como «limpio».
 */
final class ClamAvScanner implements Scanner
{
    /** Bytes por trozo. El demonio rechaza trozos mayores que su límite de flujo, y este es muy inferior. */
    private const CHUNK_BYTES = 65536;

    /**
     * @param  Closure(): (resource|false)|null  $connector  Cómo abrir la conexión; por defecto, TCP al demonio. Existe para
     *                                                       poder probar el protocolo con un par de sockets en memoria.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeoutSeconds,
        private readonly ?Closure $connector = null,
    ) {}

    public function scan($stream): ScanResult
    {
        $socket = $this->connect();

        try {
            $this->send($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);

                if ($chunk === false) {
                    throw new ScannerUnavailable('No se pudo leer el archivo que había que revisar.');
                }

                if ($chunk === '') {
                    break;
                }

                // Cada trozo lleva delante su largo en 4 bytes (orden de red); un trozo de largo cero termina el envío.
                $this->send($socket, pack('N', strlen($chunk)).$chunk);
            }

            $this->send($socket, pack('N', 0));

            return $this->interpret($this->receive($socket));
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        try {
            $socket = $this->connector !== null
                ? ($this->connector)()
                : stream_socket_client("tcp://{$this->host}:{$this->port}", $code, $message, $this->timeoutSeconds);
        } catch (ErrorException $failure) {
            throw new ScannerUnavailable('No se pudo conectar con el antivirus.', previous: $failure);
        }

        if ($socket === false) {
            throw new ScannerUnavailable('No se pudo conectar con el antivirus.');
        }

        stream_set_timeout($socket, $this->timeoutSeconds);

        return $socket;
    }

    /**
     * @param  resource  $socket
     */
    private function send($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);

        try {
            while ($offset < $length) {
                $written = fwrite($socket, substr($data, $offset));

                if ($written === false || $written === 0) {
                    throw new ScannerUnavailable('El antivirus cerró la conexión antes de recibir el archivo.');
                }

                $offset += $written;
            }
        } catch (ErrorException $failure) {
            throw new ScannerUnavailable('El antivirus cerró la conexión antes de recibir el archivo.', previous: $failure);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function receive($socket): string
    {
        // Con el prefijo «z» el demonio termina su respuesta con un byte nulo.
        try {
            $reply = stream_get_line($socket, 4096, "\0");
        } catch (ErrorException $failure) {
            throw new ScannerUnavailable('El antivirus no contestó.', previous: $failure);
        }

        if ($reply === false || $reply === '') {
            throw new ScannerUnavailable('El antivirus no contestó a tiempo.');
        }

        return rtrim($reply, "\r\n ");
    }

    private function interpret(string $reply): ScanResult
    {
        if ($reply === 'stream: OK') {
            return ScanResult::clean();
        }

        if (preg_match('/\Astream: ([\x20-\x7E]{1,100}) FOUND\z/', $reply, $found) === 1) {
            return ScanResult::infected($found[1]);
        }

        // «ERROR» (por ejemplo, un archivo por encima del límite del demonio) y cualquier otra cosa: sin respuesta válida.
        throw new ScannerUnavailable('El antivirus devolvió una respuesta que no se reconoce.');
    }
}
