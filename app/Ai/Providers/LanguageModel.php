<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Completion;
use App\Ai\CompletionRequest;

/**
 * Un proveedor de modelos (RIA-07). Todos hablan igual hacia el resto del sistema: cambiar o agregar uno no toca nada más.
 * Todo lo que no sea una respuesta utilizable lanza `ProviderUnavailable`.
 */
interface LanguageModel
{
    /**
     * @throws ProviderUnavailable
     */
    public function complete(CompletionRequest $request): Completion;
}
