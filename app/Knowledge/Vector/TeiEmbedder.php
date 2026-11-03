<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * Embeddings con un modelo abierto servido por Text Embeddings Inference dentro de la red de Docker. La dirección sale
 * de la configuración (nunca de lo que escribe una persona), no se siguen redirecciones y todo fallo se reduce a
 * `EmbeddingUnavailable` con un mensaje genérico.
 */
final class TeiEmbedder implements Embedder
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $url,
        private readonly int $dimensions,
        private readonly int $batch,
        private readonly int $timeoutSeconds,
        private readonly string $queryPrefix,
        private readonly string $passagePrefix,
    ) {}

    public function embed(array $texts, EmbedKind $kind): array
    {
        $prefix = $kind === EmbedKind::Query ? $this->queryPrefix : $this->passagePrefix;
        $vectors = [];

        foreach (array_chunk($texts, max(1, $this->batch)) as $group) {
            foreach ($this->request(array_map(fn (string $text): string => $prefix.$text, $group)) as $vector) {
                $vectors[] = $vector;
            }
        }

        return $vectors;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $inputs
     * @return list<list<float>>
     *
     * @throws EmbeddingUnavailable
     */
    private function request(array $inputs): array
    {
        try {
            $response = $this->client()->post('/embed', ['inputs' => $inputs, 'normalize' => true, 'truncate' => true]);
        } catch (ConnectionException) {
            throw new EmbeddingUnavailable('El servicio de embeddings no responde.');
        } catch (Throwable) {
            throw new EmbeddingUnavailable('El servicio de embeddings falló.');
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || count($body) !== count($inputs)) {
            throw new EmbeddingUnavailable('El servicio de embeddings dio una respuesta inválida.');
        }

        $vectors = [];

        foreach ($body as $row) {
            if (! is_array($row) || count($row) !== $this->dimensions) {
                throw new EmbeddingUnavailable('El servicio de embeddings dio una respuesta inválida.');
            }

            $vector = [];

            foreach ($row as $value) {
                if (! is_int($value) && ! is_float($value)) {
                    throw new EmbeddingUnavailable('El servicio de embeddings dio una respuesta inválida.');
                }

                $vector[] = (float) $value;
            }

            $vectors[] = $vector;
        }

        return $vectors;
    }

    private function client(): PendingRequest
    {
        return $this->http->baseUrl($this->url)->acceptJson()->asJson()->timeout($this->timeoutSeconds)->connectTimeout(3)->withoutRedirecting();
    }
}
