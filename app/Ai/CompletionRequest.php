<?php

declare(strict_types=1);

namespace App\Ai;

/** Lo que recibe un proveedor: ya enmascarado, ya con los topes aplicados. */
final readonly class CompletionRequest
{
    /**
     * @param  list<PromptMessage>  $messages
     */
    public function __construct(
        public string $model,
        public string $system,
        public array $messages,
        public int $maxTokens,
        public bool $json,
        public ?string $effort = null,
    ) {}
}
