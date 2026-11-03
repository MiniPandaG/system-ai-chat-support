<?php

declare(strict_types=1);

namespace App\Knowledge\Vector;

use App\Knowledge\SourceKind;

/**
 * El índice vectorial de las empresas (RIA-04, RS-01). Cada empresa tiene el suyo y todas las operaciones piden su
 * identificador: no existe una búsqueda que cruce empresas. El índice solo guarda identificadores de fragmentos y de su
 * fuente; el texto vive en MySQL, así que lo que el índice devuelva siempre se vuelve a comprobar contra la base.
 */
interface VectorIndex
{
    /**
     * Crea el índice de la empresa si no existe.
     *
     * @throws IndexUnavailable
     */
    public function ensure(int $tenantId, int $dimensions): void;

    /**
     * @param  list<VectorPoint>  $points
     *
     * @throws IndexUnavailable
     */
    public function upsert(int $tenantId, array $points): void;

    /**
     * Quita todos los fragmentos de una fuente.
     *
     * @throws IndexUnavailable
     */
    public function deleteSource(int $tenantId, SourceKind $kind, int $sourceId): void;

    /**
     * @param  list<float>  $vector
     * @return list<SearchHit> Del más parecido al menos, como máximo `$limit`.
     *
     * @throws IndexUnavailable
     */
    public function search(int $tenantId, array $vector, int $limit): array;

    /**
     * Todos los identificadores de fragmentos que hay en el índice de la empresa.
     *
     * @return list<int>
     *
     * @throws IndexUnavailable
     */
    public function chunkIds(int $tenantId): array;

    /**
     * Quita los fragmentos con esos identificadores.
     *
     * @param  list<int>  $chunkIds
     *
     * @throws IndexUnavailable
     */
    public function deleteChunks(int $tenantId, array $chunkIds): void;

    /**
     * Borra el índice entero de la empresa.
     *
     * @throws IndexUnavailable
     */
    public function dropTenant(int $tenantId): void;
}
