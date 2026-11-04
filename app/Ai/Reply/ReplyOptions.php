<?php

declare(strict_types=1);

namespace App\Ai\Reply;

use App\Ai\AiChannel;
use App\Ai\AiMode;

/** Cómo se arma una respuesta. Los valores por defecto salen de la configuración de la plataforma. */
final readonly class ReplyOptions
{
    public function __construct(
        /** Sin ningún fragmento de conocimiento parecido no se llama al modelo: solo responde con lo que la empresa escribió. */
        public bool $requireKnowledge = true,
        public ?int $knowledgeLimit = null,
        public ?float $minScore = null,
        /** Por dónde llegó el mensaje y qué hace la IA en ese canal: solo se anotan en la ejecución. */
        public ?AiChannel $channel = null,
        public ?AiMode $mode = null,
        /** Cómo quiere la empresa que se hable a sus clientes: ya redactado, se le da al modelo como parte de los datos. */
        public ?string $tone = null,
    ) {}

    public function limit(): int
    {
        return $this->knowledgeLimit ?? config()->integer('ai.reply.knowledge_limit');
    }

    public function floor(): float
    {
        return $this->minScore ?? config()->float('ai.reply.min_score');
    }
}
