<?php

declare(strict_types=1);

namespace App\Ai\Rules;

/**
 * La confianza de una respuesta, de 0 a 100 (RIA-01, RIA-05). La calcula el código, no el modelo: sale de lo parecido que
 * es el mejor fragmento citado a la pregunta (la escala se calibra con la configuración) y baja si el propio modelo pidió
 * una persona. Un modelo que dice «estoy seguro» no mueve este número.
 */
class Confidence
{
    /**
     * @param  list<float>  $citedScores  El parecido (0 a 1) de cada fragmento citado; vacío si respondió solo con el contexto de la empresa.
     */
    public function score(array $citedScores, bool $needsHuman): int
    {
        $floor = config()->float('ai.confidence.floor');
        $ceil = config()->float('ai.confidence.ceil');

        $score = $citedScores === []
            ? config()->integer('ai.confidence.context_only')
            : (int) round(100 * max(0.0, min(1.0, (max($citedScores) - $floor) / max(0.0001, $ceil - $floor))));

        return $needsHuman ? min($score, config()->integer('ai.confidence.needs_human_cap')) : $score;
    }
}
