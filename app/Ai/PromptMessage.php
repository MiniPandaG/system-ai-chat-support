<?php

declare(strict_types=1);

namespace App\Ai;

/** Un turno que se le envía al modelo y cuánto de su texto hay que enmascarar antes de enviarlo. */
final readonly class PromptMessage
{
    /**
     * @param  'user'|'assistant'  $role
     */
    public function __construct(
        public string $role,
        public string $content,
        public MaskLevel $mask,
    ) {}
}
