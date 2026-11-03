<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

/**
 * Convierte texto en vectores para la búsqueda por significado. El texto de las empresas es contenido no confiable y
 * confidencial: la implementación real corre dentro de la plataforma, sin enviarlo a un tercero (D-06).
 */
interface Embedder
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>> Un vector por texto, en el mismo orden, todos de `dimensions()` números.
     *
     * @throws EmbeddingUnavailable
     */
    public function embed(array $texts, EmbedKind $kind): array;

    public function dimensions(): int;
}
