<?php

declare(strict_types=1);

namespace App\Ai\Reply;

/** La decisión del modelo ya validada: si puede responder, si pide una persona, el texto limpio y los fragmentos citados. */
final readonly class ModelReply
{
    /**
     * @param  list<int>  $sources  Números de fragmento que existían en lo enviado.
     */
    public function __construct(
        public bool $canAnswer,
        public bool $needsHuman,
        public string $answer,
        public array $sources,
    ) {}
}
